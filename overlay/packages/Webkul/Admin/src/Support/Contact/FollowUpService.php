<?php

namespace Webkul\Admin\Support\Contact;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;
use Webkul\Activity\Models\ActivityProxy;
use Webkul\Contact\Models\Person;
use Webkul\Lead\Models\Lead;

/**
 * Follow-ups after a booking link is emailed (requirements §8, email only for
 * now; config/follow_ups.php). Per emailed link (table txn_follow_ups):
 *   24h  reminder: the link's still-open times, or a fresh link with new
 *        suggested times, or "call or text us" if no time can be offered
 *   72h  final: "if you still need help, call or text us"
 *   96h  the lead goes Contacted → Lost ("No reply to follow-ups")
 * A sequence stops when the customer books (checked in Cal.com before
 * anything is sent, so a booking not yet synced still counts), when the lead
 * is no longer in Contacted (booked and synced, or moved by staff), or when a
 * newer link is sent. Nothing runs by itself: `php artisan txn:follow-ups`.
 */
final class FollowUpService
{
    private const CALCOM_API = 'https://api.cal.com/v2';

    private const CALCOM_BOOKINGS_VERSION = '2024-08-13';

    public function __construct(
        private BookingEmail $email,
        private LeadStageService $stages,
        private SlotSuggestionService $slots,
        private CalcomBookingLinkService $links,
        private SmsSender $sms
    ) {}

    /**
     * Which steps may run. Null (the command, by hand): every email step,
     * no text, as before. Automation passes the engines' switches
     * (by hand: no texts; scheduled steps: runStep sets the SMS switches).
     */
    private array $allow = ['reminder' => true, 'final' => true, 'lost' => true, 'sms_reminder' => false, 'sms_final' => false];

    /** A booking link was emailed: start its sequence; older open ones for the same lead/person stop. */
    public function start(Person $person, $lead, string $email, string $url): void
    {
        $older = DB::table('txn_follow_ups')
            ->whereNull('stopped_at')
            ->whereNull('lost_at')
            ->where('person_id', $person->id)
            ->when($lead, fn ($query) => $query->where(fn ($q) => $q->where('lead_id', $lead->id)->orWhereNull('lead_id')));
        $olderIds = (clone $older)->pluck('id')->all();
        $older->update(['stopped_at' => now(), 'stop_reason' => 'A newer booking link was sent', 'updated_at' => now()]);

        $scheduler = app(\Webkul\Admin\Support\Automation\MessageScheduler::class);
        $scheduler->cancelForFollowUps($olderIds, 'A newer booking link was sent');

        $id = DB::table('txn_follow_ups')->insertGetId([
            'person_id' => $person->id,
            'lead_id' => $lead?->id,
            'booking_link_id' => $this->linkIdForUrl($url),
            'email' => $email,
            'booking_url' => $url,
            'link_sent_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Email engine on: each step becomes a scheduled entry with its exact time.
        $scheduler->scheduleFollowUp((int) $id);
    }

    /**
     * One scheduled step (txn_scheduled_messages row, MessageScheduler), at
     * its time or "Send now". Checks again first: follow-ups still open, lead
     * still in Contacted, step not already done, not booked in Cal.com, and
     * (unless staff pressed Send now) the step still switched on.
     *
     * @return array{status: string, reason: string} sent | cancelled | failed | retry
     */
    public function runStep(object $scheduled, bool $manual = false): array
    {
        $kinds = \Webkul\Admin\Support\Automation\MessageScheduler::KINDS;
        $row = DB::table('txn_follow_ups')->find($scheduled->follow_up_id);

        if (! $row) {
            return ['status' => 'cancelled', 'reason' => 'its follow-up no longer exists'];
        }

        if ($row->stopped_at || $row->lost_at) {
            return ['status' => 'cancelled', 'reason' => $row->stop_reason ?: 'the follow-ups were stopped'];
        }

        $person = Person::find($row->person_id);
        $leads = $this->contactedLeads($row, $person);

        if (! $person || $leads->isEmpty()) {
            $this->stop($row, $person, $leads, false, 'Lead is no longer in Contacted (booked or moved)');

            return ['status' => 'cancelled', 'reason' => 'the lead is no longer in Contacted'];
        }

        $done = match ($scheduled->kind) {
            'reminder' => $row->reminder_sent_at || $row->final_sent_at,
            'final' => (bool) $row->final_sent_at,
            'lost' => (bool) $row->lost_at,
            default => true,
        };

        if ($done) {
            return ['status' => 'cancelled', 'reason' => 'this step was already done'];
        }

        if (! $manual && ! \Webkul\Admin\Support\Automation\Automation::rule($kinds[$scheduled->kind][2])) {
            return ['status' => 'cancelled', 'reason' => $kinds[$scheduled->kind][0].' is switched off'];
        }

        $booked = $this->bookedInCalcom($row);

        if ($booked === null) {
            return ['status' => 'retry', 'reason' => 'Cal.com could not be asked whether the customer booked; trying again in 15 minutes'];
        }

        if ($booked) {
            $this->stop($row, $person, $leads, false, 'Customer booked (Cal.com; sync to update the stage)');

            return ['status' => 'cancelled', 'reason' => 'the customer booked'];
        }

        $this->allow = [
            'reminder' => true, 'final' => true, 'lost' => true,
            'sms_reminder' => \Webkul\Admin\Support\Automation\Automation::rule('sms.reminder'),
            'sms_final' => \Webkul\Admin\Support\Automation\Automation::rule('sms.final'),
        ];

        $line = match ($scheduled->kind) {
            'reminder' => $this->reminder($row, $person, $leads, false, CarbonImmutable::now()),
            'final' => $this->final($row, $person, $leads, false),
            'lost' => $this->lost($row, $person, $leads, false),
        };

        return ['status' => 'sent', 'reason' => $line['result']];
    }

    /**
     * Sends what is due. $dryRun: decides and reports, sends and changes
     * nothing (Cal.com is still asked whether the customer booked).
     *
     * @return array<int, array{id: int, lead: string, contact: string, action: string, result: string}>
     */
    public function run(bool $dryRun = false, ?CarbonImmutable $now = null, ?array $allow = null): array
    {
        $now ??= CarbonImmutable::now();
        $report = [];
        $this->allow = array_merge(['reminder' => true, 'final' => true, 'lost' => true, 'sms_reminder' => false, 'sms_final' => false], $allow ?? []);

        // Follow-ups on the schedule (MessageScheduler) are sent by it, at their times.
        $scheduled = \Webkul\Admin\Support\Automation\MessageScheduler::ready();

        foreach (DB::table('txn_follow_ups')->whereNull('stopped_at')->whereNull('lost_at')
            ->when($scheduled, fn ($q) => $q->whereNotExists(fn ($s) => $s->select(DB::raw(1))->from('txn_scheduled_messages')->whereColumn('txn_scheduled_messages.follow_up_id', 'txn_follow_ups.id')))
            ->orderBy('id')->get() as $row) {
            try {
                $report[] = $this->handle($row, $dryRun, $now);
            } catch (Throwable $exception) {
                Log::error('Follow-up failed.', ['follow_up_id' => $row->id, 'error' => $exception->getMessage()]);
                $report[] = $this->line($row, null, null, 'error', $exception->getMessage());
            }
        }

        return $report;
    }

    private function handle(object $row, bool $dryRun, CarbonImmutable $now): array
    {
        $person = Person::find($row->person_id);
        $leads = $this->contactedLeads($row, $person);

        if (! $person || $leads->isEmpty()) {
            return $this->stop($row, $person, $leads, $dryRun, 'Lead is no longer in Contacted (booked or moved)');
        }

        $hours = CarbonImmutable::parse($row->link_sent_at)->diffInMinutes($now) / 60;
        // A step turned off (Automation) is passed over: with the final email
        // off, Lost still comes at its hour.
        $action = match (true) {
            ($row->final_sent_at || ! $this->allow['final']) && $this->allow['lost'] && $hours >= config('follow_ups.lost_after_hours') => 'lost',
            ! $row->final_sent_at && $this->allow['final'] && $hours >= config('follow_ups.final_after_hours') => 'final',
            ! $row->reminder_sent_at && ! $row->final_sent_at && $this->allow['reminder'] && $hours >= config('follow_ups.reminder_after_hours') => 'reminder',
            default => null,
        };

        if ($action === null) {
            return $this->line($row, $person, $leads, 'wait', 'next step due '.$this->nextDue($row)->setTimezone(config('slot_finder.timezone'))->format('D M j, g:i A').' ('.TxnSettings::timezoneLabel().')');
        }

        // Booked in Cal.com but not synced yet: stop instead of chasing.
        $booked = $this->bookedInCalcom($row);

        if ($booked === null) {
            return $this->line($row, $person, $leads, $action, 'skipped: could not check Cal.com for a booking; will retry next run');
        }

        if ($booked) {
            return $this->stop($row, $person, $leads, $dryRun, 'Customer booked (Cal.com; sync to update the stage)');
        }

        return match ($action) {
            'reminder' => $this->reminder($row, $person, $leads, $dryRun, $now),
            'final' => $this->final($row, $person, $leads, $dryRun),
            'lost' => $this->lost($row, $person, $leads, $dryRun),
        };
    }

    private function reminder(object $row, Person $person, $leads, bool $dryRun, CarbonImmutable $now): array
    {
        $link = $row->booking_link_id ? DB::table('calcom_booking_links')->find($row->booking_link_id) : null;
        $timezone = $link->timezone ?? config('slot_finder.timezone');
        $earliest = $now->addHours(config('follow_ups.min_hours_before_slot'));
        $url = $row->booking_url;

        // Times still open in the link already sent.
        $slots = collect(array_keys(json_decode($link->slot_workers ?? '[]', true) ?: []))
            ->filter(fn ($slot) => CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $slot, $timezone)->greaterThan($earliest))
            ->values()
            ->all();
        $how = $slots ? count($slots).' still-open time(s) from the sent link' : null;

        // None left: a fresh link with newly suggested times.
        if (! $slots) {
            [$url, $slots, $how] = $dryRun
                ? [$url, [], 'would create a fresh link with new times']
                : $this->freshLink($row, $person, $leads, $now, $url);
        }

        if ($dryRun) {
            return $this->line($row, $person, $leads, 'reminder', "would email {$row->email} ({$how})");
        }

        $lead = $leads->first();
        $this->email->send('reminder', $row->email, $this->email->data($person, $lead, $timezone, $slots, $slots ? $url : null, 'reminder'), $person, $lead);
        DB::table('txn_follow_ups')->where('id', $row->id)->update(['reminder_sent_at' => now(), 'updated_at' => now()]);
        $this->note($person, $leads, 'Follow-up 1 (24h) emailed', "Sent to {$row->email}: {$how}.".($slots ? "\n{$url}" : ''));

        // Reminder text (SMS engine): only with a link to book from.
        $sms = $this->allow['sms_reminder'] && $slots
            ? $this->sms->send($person, $this->email->smsText($person, $lead, $url, 'reminder'), $lead)
            : null;
        $smsText = match ($sms['status'] ?? null) {
            'sent' => ' and SMS ('.$sms['to'].')',
            'blocked' => '; SMS blocked by the guard',
            'skipped' => '; no SMS (no usable phone number)',
            'failed' => '; SMS failed',
            default => '',
        };

        PipelineEvents::record('reminder_sent', 'Sent the reminder to '.PipelineEvents::name($person)." by email ({$row->email}){$smsText}: {$how}", [
            'email' => $row->email,
            'times' => $slots,
            'url' => $slots ? $url : null,
            'sms' => $sms ? array_intersect_key($sms, array_flip(['status', 'to', 'error'])) : null,
        ], $lead, $person);

        return $this->line($row, $person, $leads, 'reminder', "emailed {$row->email} ({$how})".$smsText);
    }

    /** @return array{0: string, 1: array, 2: string} [url, slots, description] */
    private function freshLink(object $row, Person $person, $leads, CarbonImmutable $now, string $oldUrl): array
    {
        try {
            $suggested = $this->slots->suggestForPerson($person, null, $now->setTimezone(config('slot_finder.timezone')));
            $slotWorkers = collect($suggested['options'] ?? [])->mapWithKeys(fn ($option) => [$option['value'] => (int) $option['worker_id']])->all();

            if (($suggested['status'] ?? null) !== SlotSuggestionService::OK || ! $slotWorkers) {
                return [$oldUrl, [], 'no open times found; asks the customer to call or text'];
            }

            $timezone = config('slot_finder.timezone');
            $url = $this->links->createBookingLink($person, $slotWorkers, $timezone, $this->links->askableCodes($person), $leads->count() === 1 ? $leads->first()->id : $row->lead_id);

            DB::table('txn_follow_ups')->where('id', $row->id)->update([
                'booking_url' => $url,
                'booking_link_id' => $this->linkIdForUrl($url),
                'updated_at' => now(),
            ]);

            PipelineEvents::record('fresh_link_created', 'No times left in the sent link: created a fresh one for '.PipelineEvents::name($person).' with '.PipelineEvents::slotsText($slotWorkers, $timezone), [
                'url' => $url,
                'times' => $slotWorkers,
            ], $leads->first() ?? $row->lead_id, $person);

            return [$url, array_keys($slotWorkers), count($slotWorkers).' new time(s) in a fresh link'];
        } catch (Throwable $exception) {
            Log::warning('Follow-up could not create a fresh booking link.', ['follow_up_id' => $row->id, 'error' => $exception->getMessage()]);

            return [$oldUrl, [], 'could not make a fresh link; asks the customer to call or text'];
        }
    }

    private function final(object $row, Person $person, $leads, bool $dryRun): array
    {
        if ($dryRun) {
            return $this->line($row, $person, $leads, 'final', "would email {$row->email}");
        }

        $this->email->send('final', $row->email, $this->email->data($person, $leads->first(), config('slot_finder.timezone'), [], null, 'final'), $person, $leads->first());
        DB::table('txn_follow_ups')->where('id', $row->id)->update(['final_sent_at' => now(), 'updated_at' => now()]);
        $this->note($person, $leads, 'Final follow-up (72h) emailed', "Sent to {$row->email}.");

        // Final text (SMS engine; requirements A8: same channels as the first message).
        $sms = $this->allow['sms_final'] ? $this->sms->send($person, $this->email->smsText($person, $leads->first(), '', 'final'), $leads->first()) : null;
        $smsText = match ($sms['status'] ?? null) {
            'sent' => ' and SMS ('.$sms['to'].')',
            'blocked' => '; SMS blocked by the guard',
            'skipped' => '; no SMS (no usable phone number)',
            'failed' => '; SMS failed',
            default => '',
        };

        PipelineEvents::record('final_sent', 'Sent the final email to '.PipelineEvents::name($person)." by email ({$row->email}){$smsText}", [
            'email' => $row->email,
            'sms' => $sms ? array_intersect_key($sms, array_flip(['status', 'to', 'error'])) : null,
        ], $leads->first(), $person);

        return $this->line($row, $person, $leads, 'final', "emailed {$row->email}".$smsText);
    }

    private function lost(object $row, Person $person, $leads, bool $dryRun): array
    {
        if ($dryRun) {
            return $this->line($row, $person, $leads, 'lost', 'would mark '.$leads->count().' lead(s) Lost');
        }

        $moved = $leads->filter(fn ($lead) => $this->stages->lost($lead, config('follow_ups.lost_reason')))->count();
        DB::table('txn_follow_ups')->where('id', $row->id)->update(['lost_at' => now(), 'updated_at' => now()]);

        foreach ($leads as $lead) {
            PipelineEvents::record('marked_lost', 'Marked '.($lead->title ?: "lead {$lead->id}").' Lost: '.config('follow_ups.lost_reason'), ['reason' => config('follow_ups.lost_reason')], $lead, $person);

            // Staff notifications (if on): "A lead was marked Lost".
            app(\Webkul\Admin\Support\Automation\StaffNotifier::class)->notify('marked_lost', 'Marked Lost: '.($lead->title ?: "lead {$lead->id}"),
                PipelineEvents::name($person)."'s lead was marked Lost after the follow-ups: ".config('follow_ups.lost_reason').'.', $lead, $person);
        }

        return $this->line($row, $person, $leads, 'lost', "{$moved} lead(s) marked Lost");
    }

    private function stop(object $row, ?Person $person, $leads, bool $dryRun, string $reason): array
    {
        if (! $dryRun) {
            DB::table('txn_follow_ups')->where('id', $row->id)->update(['stopped_at' => now(), 'stop_reason' => $reason, 'updated_at' => now()]);
            PipelineEvents::record('follow_up_stopped', 'Stopped the follow-ups for '.PipelineEvents::name($person).": {$reason}", ['reason' => $reason], $row->lead_id, $person ?? $row->person_id);
            // The rest of this sequence on the schedule goes too.
            app(\Webkul\Admin\Support\Automation\MessageScheduler::class)->cancelForFollowUps([(int) $row->id], $reason);
        }

        return $this->line($row, $person, $leads, 'stop', ($dryRun ? 'would stop: ' : 'stopped: ').$reason);
    }

    /** The link's lead, or (link sent from the contact page) the contact's leads, that are still in Contacted. */
    private function contactedLeads(object $row, ?Person $person)
    {
        if (! $person) {
            return collect();
        }

        $query = Lead::query()->with('stage')->where('person_id', $person->id);

        if ($row->lead_id) {
            $query->whereKey($row->lead_id);
        }

        // By stage role, so a renamed or replaced Contacted stage still counts.
        return $query->get()->filter(fn ($lead) => $this->stages->roleOf($lead) === LeadStageService::CONTACTED)->values();
    }

    /** true = a live booking exists for the link's event, false = none, null = couldn't check. */
    private function bookedInCalcom(object $row): ?bool
    {
        $eventTypeId = $row->booking_link_id ? DB::table('calcom_booking_links')->where('id', $row->booking_link_id)->value('event_type_id') : null;

        if (! $eventTypeId) {
            return false; // nothing to check against; the stage rule still applies
        }

        if (! ServiceHealth::hasKey('calcom')) {
            ServiceHealth::noKey('calcom');

            return null;
        }

        try {
            $response = Http::withToken((string) config('calcom.api_key'))
                ->acceptJson()
                ->withHeaders(['cal-api-version' => self::CALCOM_BOOKINGS_VERSION])
                ->timeout(20)
                ->get(self::CALCOM_API.'/bookings', ['eventTypeId' => $eventTypeId, 'take' => 20]);
        } catch (Throwable $exception) {
            ServiceHealth::failed('calcom', 'Krayin could not connect to Cal.com: '.$exception->getMessage());

            return null;
        }

        if (! $response->successful()) {
            if (ServiceHealth::isAuthFailure($response)) {
                ServiceHealth::failed('calcom', "Cal.com refused the API key (HTTP {$response->status()}): ".($response->json('error.message') ?? $response->json('message') ?? $response->body()));
            }

            return null;
        }

        ServiceHealth::ok('calcom');

        return collect($response->json('data') ?? [])
            ->contains(fn ($booking) => (int) data_get($booking, 'eventTypeId', data_get($booking, 'eventType.id')) === (int) $eventTypeId
                && ! in_array($booking['status'] ?? '', ['cancelled', 'rejected'], true));
    }

    private function nextDue(object $row): CarbonImmutable
    {
        $hours = match (true) {
            (bool) $row->final_sent_at => config('follow_ups.lost_after_hours'),
            (bool) $row->reminder_sent_at => config('follow_ups.final_after_hours'),
            default => config('follow_ups.reminder_after_hours'),
        };

        return CarbonImmutable::parse($row->link_sent_at)->addHours($hours);
    }

    private function linkIdForUrl(string $url): ?int
    {
        $slug = basename(parse_url($url, PHP_URL_PATH) ?: '');
        $id = $slug ? DB::table('calcom_booking_links')->where('event_type_slug', $slug)->value('id') : null;

        return $id ? (int) $id : null;
    }

    /** Note on the contact and the leads, like Krayin's own notes. */
    private function note(Person $person, $leads, string $title, string $comment): void
    {
        try {
            $activity = ActivityProxy::modelClass()::create([
                'type' => 'note',
                'title' => $title,
                'comment' => $comment,
                'is_done' => 1,
                'user_id' => auth()->id() ?: config('calcom.activity_user_id', 1),
            ]);

            $person->activities()->syncWithoutDetaching([$activity->id]);

            foreach ($leads as $lead) {
                $lead->activities()->syncWithoutDetaching([$activity->id]);
            }
        } catch (Throwable $exception) {
            Log::warning('Follow-up sent, but the note could not be saved.', ['person_id' => $person->id, 'error' => $exception->getMessage()]);
        }
    }

    private function line(object $row, ?Person $person, $leads, string $action, string $result): array
    {
        return [
            'id' => (int) $row->id,
            'lead' => $leads && $leads->isNotEmpty() ? $leads->pluck('id')->implode(', ') : (string) ($row->lead_id ?? '-'),
            'contact' => $person ? "{$person->id} {$person->name}" : (string) $row->person_id,
            'action' => $action,
            'result' => $result,
        ];
    }
}
