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
        private CalcomBookingLinkService $links
    ) {}

    /** A booking link was emailed: start its sequence; older open ones for the same lead/person stop. */
    public function start(Person $person, $lead, string $email, string $url): void
    {
        DB::table('txn_follow_ups')
            ->whereNull('stopped_at')
            ->whereNull('lost_at')
            ->where('person_id', $person->id)
            ->when($lead, fn ($query) => $query->where(fn ($q) => $q->where('lead_id', $lead->id)->orWhereNull('lead_id')))
            ->update(['stopped_at' => now(), 'stop_reason' => 'A newer booking link was sent', 'updated_at' => now()]);

        DB::table('txn_follow_ups')->insert([
            'person_id' => $person->id,
            'lead_id' => $lead?->id,
            'booking_link_id' => $this->linkIdForUrl($url),
            'email' => $email,
            'booking_url' => $url,
            'link_sent_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Sends what is due. $dryRun: decides and reports, sends and changes
     * nothing (Cal.com is still asked whether the customer booked).
     *
     * @return array<int, array{id: int, lead: string, contact: string, action: string, result: string}>
     */
    public function run(bool $dryRun = false, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $report = [];

        foreach (DB::table('txn_follow_ups')->whereNull('stopped_at')->whereNull('lost_at')->orderBy('id')->get() as $row) {
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
        $action = match (true) {
            $row->final_sent_at && $hours >= config('follow_ups.lost_after_hours') => 'lost',
            ! $row->final_sent_at && $hours >= config('follow_ups.final_after_hours') => 'final',
            ! $row->reminder_sent_at && ! $row->final_sent_at && $hours >= config('follow_ups.reminder_after_hours') => 'reminder',
            default => null,
        };

        if ($action === null) {
            return $this->line($row, $person, $leads, 'wait', 'next step due '.$this->nextDue($row)->setTimezone(config('slot_finder.timezone'))->format('D M j, g:i A').' (Central)');
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
        $this->email->send('reminder', $row->email, $this->email->data($person, $lead, $timezone, $slots, $slots ? $url : null, 'reminder'));
        DB::table('txn_follow_ups')->where('id', $row->id)->update(['reminder_sent_at' => now(), 'updated_at' => now()]);
        $this->note($person, $leads, 'Follow-up 1 (24h) emailed', "Sent to {$row->email}: {$how}.".($slots ? "\n{$url}" : ''));

        return $this->line($row, $person, $leads, 'reminder', "emailed {$row->email} ({$how})");
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

        $this->email->send('final', $row->email, $this->email->data($person, $leads->first(), config('slot_finder.timezone'), [], null, 'final'));
        DB::table('txn_follow_ups')->where('id', $row->id)->update(['final_sent_at' => now(), 'updated_at' => now()]);
        $this->note($person, $leads, 'Final follow-up (72h) emailed', "Sent to {$row->email}.");

        return $this->line($row, $person, $leads, 'final', "emailed {$row->email}");
    }

    private function lost(object $row, Person $person, $leads, bool $dryRun): array
    {
        if ($dryRun) {
            return $this->line($row, $person, $leads, 'lost', 'would mark '.$leads->count().' lead(s) Lost');
        }

        $moved = $leads->filter(fn ($lead) => $this->stages->lost($lead, config('follow_ups.lost_reason')))->count();
        DB::table('txn_follow_ups')->where('id', $row->id)->update(['lost_at' => now(), 'updated_at' => now()]);

        return $this->line($row, $person, $leads, 'lost', "{$moved} lead(s) marked Lost");
    }

    private function stop(object $row, ?Person $person, $leads, bool $dryRun, string $reason): array
    {
        if (! $dryRun) {
            DB::table('txn_follow_ups')->where('id', $row->id)->update(['stopped_at' => now(), 'stop_reason' => $reason, 'updated_at' => now()]);
        }

        return $this->line($row, $person, $leads, 'stop', ($dryRun ? 'would stop: ' : 'stopped: ').$reason);
    }

    /** The link's lead, or (link sent from the contact page) the contact's leads, that are still in Contacted. */
    private function contactedLeads(object $row, ?Person $person)
    {
        if (! $person) {
            return collect();
        }

        $query = Lead::query()->with('stage', 'pipeline.stages')->where('person_id', $person->id);

        if ($row->lead_id) {
            $query->whereKey($row->lead_id);
        }

        return $query->get()->filter(fn ($lead) => $lead->stage?->code === LeadStageService::CONTACTED)->values();
    }

    /** true = a live booking exists for the link's event, false = none, null = couldn't check. */
    private function bookedInCalcom(object $row): ?bool
    {
        $eventTypeId = $row->booking_link_id ? DB::table('calcom_booking_links')->where('id', $row->booking_link_id)->value('event_type_id') : null;

        if (! $eventTypeId) {
            return false; // nothing to check against; the stage rule still applies
        }

        try {
            $response = Http::withToken((string) config('calcom.api_key'))
                ->acceptJson()
                ->withHeaders(['cal-api-version' => self::CALCOM_BOOKINGS_VERSION])
                ->timeout(20)
                ->get(self::CALCOM_API.'/bookings', ['eventTypeId' => $eventTypeId, 'take' => 20]);
        } catch (Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

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
