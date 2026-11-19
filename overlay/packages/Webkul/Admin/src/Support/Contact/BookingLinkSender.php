<?php

namespace Webkul\Admin\Support\Contact;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;
use Webkul\Admin\Models\Worker;
use Webkul\Contact\Models\Person;

/**
 * Creating and sending booking links, for the booking-link page (staff) and
 * for automation (Support\Automation\EmailEngine), so both behave the same
 * and write the same events (PipelineEvents):
 *
 *   createLink()     Cal.com link (guard checked in CalcomBookingLinkService)  → link_created
 *   deliver()        email (+ SMS), lead → Contacted, follow-ups started       → link_sent
 *   sendAutomatic()  suggest times → createLink → deliver, no person involved → times_suggested, ...
 */
final class BookingLinkSender
{
    public function __construct(
        private CalcomBookingLinkService $links,
        private SlotSuggestionService $slots,
        private BookingEmail $email,
        private LeadStageService $stages,
        private FollowUpService $followUps,
        private SmsSender $sms
    ) {}

    /** Cal.com link for these times (time => worker id). Records link_created. Throws CalcomApiException. */
    public function createLink(Person $person, array $slotWorkers, string $timezone, array $fieldCodes, ?int $leadId = null): string
    {
        $url = $this->links->createBookingLink($person, $slotWorkers, $timezone, $fieldCodes, $leadId);

        PipelineEvents::record('link_created', 'Created a booking link for '.PipelineEvents::name($person).' with '.PipelineEvents::slotsText($slotWorkers, $timezone), [
            'url' => $url,
            'link_id' => $this->links->lastLinkId,
            'times' => $slotWorkers,
            'timezone' => $timezone,
            'asks_for' => array_values($fieldCodes),
        ], $leadId, $person);

        return $url;
    }

    public function lastLinkId(): ?int
    {
        return $this->links->lastLinkId;
    }

    /**
     * Emails the link (with $emailData from BookingEmail::data(), possibly
     * edited), moves the lead to Contacted, starts the follow-ups and texts
     * the customer when $withSms. Records link_sent with what happened on
     * each channel.
     *
     * @return array{email: string, email_error: ?string, sms: array, url: string}
     *               email: sent | blocked | failed
     */
    public function deliver(Person $person, $lead, string $to, string $url, array $emailData, bool $withSms): array
    {
        $result = ['email' => 'sent', 'email_error' => null, 'sms' => ['status' => 'off'], 'url' => $url];

        // The email guard would drop the email silently; say so instead.
        if (config('txn.guard_email', true) && ! in_array(strtolower($to), array_map('strtolower', (array) config('mail_guard.allowed_recipients', [])), true)) {
            $result['email'] = 'blocked';
            $result['email_error'] = 'the email guard is on and this address is not an allowed test address';
        } else {
            try {
                $this->email->send('link', $to, $emailData);
            } catch (Throwable $exception) {
                Log::error('Krayin could not deliver a Cal.com booking link.', ['person_id' => $person->id, 'exception_class' => get_class($exception)]);
                $result['email'] = 'failed';
                $result['email_error'] = $exception instanceof MailKeyMissingException ? $exception->getMessage() : 'the email could not be sent';
            }
        }

        if ($result['email'] === 'sent') {
            try {
                $this->stages->linkSent($person, $lead);
            } catch (Throwable $exception) {
                Log::error('Booking link sent, but the leads could not be moved to Contacted.', ['person_id' => $person->id, 'exception_class' => get_class($exception)]);
            }

            try {
                $this->followUps->start($person, $lead, $to, $url);
            } catch (Throwable $exception) {
                Log::error('Booking link sent, but its follow-ups could not be scheduled.', ['person_id' => $person->id, 'error' => $exception->getMessage()]);
            }
        }

        if ($withSms) {
            $result['sms'] = $this->sms->send($person, $this->email->smsText($person, $lead, $url), $lead);
        }

        $channels = collect([
            'email' => match ($result['email']) {
                'sent' => "by email ({$to})",
                'blocked' => 'email blocked by the guard',
                default => 'email failed',
            },
            'sms' => match ($result['sms']['status'] ?? 'off') {
                'sent' => 'by SMS ('.($result['sms']['to'] ?? '').')',
                'blocked' => 'SMS blocked by the guard',
                'skipped' => 'no SMS (no usable phone number)',
                'failed' => 'SMS failed',
                default => null,
            },
        ])->filter()->implode(', ');

        PipelineEvents::record(
            $result['email'] === 'sent' ? 'link_sent' : ($result['email'] === 'blocked' ? 'automation_blocked' : 'automation_failed'),
            ($result['email'] === 'sent' ? 'Sent the booking link to ' : 'Booking link not emailed to ').PipelineEvents::name($person).": {$channels}",
            [
                'url' => $url,
                'email' => ['to' => $to, 'status' => $result['email'], 'error' => $result['email_error']],
                'sms' => array_intersect_key($result['sms'], array_flip(['status', 'to', 'error'])),
                'times' => $emailData['times'] ?? [],
            ],
            $lead,
            $person
        );

        return $result;
    }

    /**
     * Automation: suggest the best times for the lead, create the link and
     * send it. Never twice for the same lead. Every outcome is an event.
     *
     * @return array{status: string, reason: string, url?: string}
     *               status: sent | skipped | blocked | failed | no_times | out_of_area
     */
    public function sendAutomatic($lead, bool $withSms): array
    {
        $lead->loadMissing('person');
        $person = $lead->person;

        if (! $person) {
            return $this->outcome('skipped', 'the lead has no contact', $lead, null);
        }

        if ($this->stages->roleOf($lead) !== LeadStageService::RAW_LEAD) {
            return $this->outcome('skipped', 'the lead is not in the "ready for a booking link" stage', $lead, $person);
        }

        if (DB::table('calcom_booking_links')->where('lead_id', $lead->id)->exists()) {
            return $this->outcome('skipped', 'the lead already has a booking link', $lead, $person);
        }

        if (! BookingLinkGuard::allows($person)) {
            return $this->outcome('blocked', 'the Cal.com guard is on and this is not a test contact', $lead, $person);
        }

        $to = BookingLinkGuard::recipientFor($person);

        if (! $to) {
            return BookingLinkGuard::personEmails($person)
                ? $this->outcome('blocked', 'the Cal.com guard is on and the contact has no test recipient address', $lead, $person)
                : $this->outcome('skipped', 'the contact has no email address', $lead, $person);
        }

        if (BookingLinkGuard::singleTestAttempt() && Cache::has('calcom.test-email.sent')) {
            return $this->outcome('blocked', 'the single test booking-link email was already used (Safety)', $lead, $person);
        }

        if (! ServiceHealth::hasKey('calcom')) {
            ServiceHealth::noKey('calcom');

            return $this->outcome('failed', 'Cal.com has no API key', $lead, $person);
        }

        $lock = Cache::lock("txn-auto-link-{$person->id}", 300);

        if (! $lock->get()) {
            return $this->outcome('skipped', 'a link for this contact is being sent right now', $lead, $person);
        }

        try {
            $timezone = config('slot_finder.timezone');
            $suggested = $this->slots->suggestForPerson($person, null, CarbonImmutable::now($timezone), $this->workerService($lead));
            $status = $suggested['status'] ?? 'ERROR';
            $slotWorkers = collect($suggested['options'] ?? [])->mapWithKeys(fn ($option) => [$option['value'] => (int) $option['worker_id']])->all();

            if ($status !== SlotSuggestionService::OK || ! $slotWorkers) {
                return match ($status) {
                    SlotSuggestionService::OUT_OF_AREA => $this->outcome('out_of_area', 'the address is outside the service area', $lead, $person),
                    SlotSuggestionService::NO_LOCATION => $this->outcome('no_times', 'the address could not be found on the map', $lead, $person),
                    SlotSuggestionService::NO_STAFF => $this->outcome('no_times', 'no active worker can do this service', $lead, $person),
                    default => $this->outcome('no_times', 'no free time for any worker in the search window', $lead, $person),
                };
            }

            PipelineEvents::record('times_suggested', 'Suggested '.PipelineEvents::slotsText($slotWorkers, $timezone).' for '.PipelineEvents::name($person), [
                'times' => $slotWorkers,
                'timezone' => $timezone,
            ], $lead, $person);

            $url = $this->createLink($person, $slotWorkers, $timezone, $this->links->askableCodes($person), (int) $lead->id);
            $data = $this->email->data($person, $lead, $timezone, array_keys($slotWorkers), $url);
            $result = $this->deliver($person, $lead, $to, $url, $data, $withSms);

            if ($result['email'] !== 'sent') {
                return ['status' => $result['email'] === 'blocked' ? 'blocked' : 'failed', 'reason' => 'the link was created but '.$result['email_error'], 'url' => $url];
            }

            if (BookingLinkGuard::singleTestAttempt()) {
                Cache::add('calcom.test-email.sent', $person->id, now()->addYears(10));
            }

            return ['status' => 'sent', 'reason' => 'booking link sent', 'url' => $url];
        } catch (CalcomApiException|SlotSuggestionException $exception) {
            return $this->outcome('failed', $exception->getMessage(), $lead, $person);
        } catch (Throwable $exception) {
            Log::error('Automatic booking link failed.', ['lead_id' => $lead->id, 'error' => $exception->getMessage()]);

            return $this->outcome('failed', 'unexpected error: '.$exception->getMessage(), $lead, $person);
        } finally {
            $lock->release();
        }
    }

    /** The lead's service category, when some active worker lists it (else any worker). */
    private function workerService($lead): ?string
    {
        $category = $this->email->serviceCategory($lead);

        if (! $category) {
            return null;
        }

        $services = Worker::query()->where('is_active', true)->get()->flatMap(fn ($worker) => $worker->services ?? [])->unique();

        return $services->first(fn ($service) => strcasecmp((string) $service, $category) === 0);
    }

    /** Records a non-sent outcome as an event and returns it. */
    private function outcome(string $status, string $reason, $lead, ?Person $person): array
    {
        $event = match ($status) {
            'blocked' => 'automation_blocked',
            'failed' => 'automation_failed',
            default => 'automation_skipped',
        };

        PipelineEvents::record($event, 'Booking link not sent'.($person ? ' to '.PipelineEvents::name($person) : '').": {$reason}", ['status' => $status, 'reason' => $reason], $lead, $person);

        return ['status' => $status, 'reason' => $reason];
    }
}
