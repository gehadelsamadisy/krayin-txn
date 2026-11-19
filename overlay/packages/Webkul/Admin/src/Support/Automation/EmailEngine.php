<?php

namespace Webkul\Admin\Support\Automation;

use Webkul\Admin\Support\Contact\BookingLinkSender;
use Webkul\Admin\Support\Contact\LeadStageService;
use Webkul\Admin\Support\Contact\PipelineEvents;

/**
 * Reacts to the lead check (txn.lead.checked) and to booking changes
 * (txn.booking.changed):
 *   - Email engine: a NEW lead that passed the check (or, if allowed, a
 *     Needs Review lead that was fixed) is sent its booking link
 *     (BookingLinkSender::sendAutomatic), with the text if the SMS engine allows.
 *   - Staff notifications: needs review, out of area, no times, a failed or
 *     blocked send, a cancelled booking.
 * Staff edits never send a link by themselves: only a new lead, or a fix
 * when "Also when a Needs Review lead is fixed" is on.
 */
final class EmailEngine
{
    public function __construct(
        private BookingLinkSender $sender,
        private StaffNotifier $staff
    ) {}

    public function leadChecked($lead, array $result): void
    {
        $person = $lead->person;
        $name = PipelineEvents::name($person);
        $title = $lead->title ?: "lead {$lead->id}";
        $isNew = (bool) ($result['is_new'] ?? false);
        $becameReview = $result['review'] && ($isNew || ($result['role_before'] ?? null) !== LeadStageService::NEEDS_REVIEW);

        // Staff: a lead that needs a person (only when it first lands there).
        if ($becameReview) {
            ($result['location_status'] ?? null) === 'out'
                ? $this->staff->notify('out_of_area', "Out of area: {$title}", "{$name}'s lead is outside the service area: {$result['location']}.\n\n{$result['next_action']}", $lead, $person)
                : $this->staff->notify('needs_review', "Needs review: {$title}", "{$name}'s lead needs a person:\n\n{$result['next_action']}", $lead, $person);
        }

        $ready = ! $result['review'] && ($result['role_after'] ?? null) === LeadStageService::RAW_LEAD;
        $fixed = ! $isNew && ($result['role_before'] ?? null) === LeadStageService::NEEDS_REVIEW;

        if (! $ready || (! $isNew && ! $fixed)) {
            return;
        }

        if (! Automation::on('email')) {
            if ($isNew) {
                PipelineEvents::record('automation_skipped', "Booking link not sent automatically to {$name}: the Email engine is off", ['reason' => 'engine off'], $lead, $person);
            }

            return;
        }

        if ($fixed && ! Automation::rule('email.when_fixed')) {
            return;
        }

        if (! Automation::rule('email.send_link')) {
            PipelineEvents::record('automation_skipped', "Booking link not sent automatically to {$name}: \"Send the booking link\" is off", ['reason' => 'rule off'], $lead, $person);

            return;
        }

        $flow = (string) ($result['flow'] ?? '');

        if (! in_array($flow, (array) config('automation.email.flows', []), true)) {
            PipelineEvents::record('automation_skipped', "Booking link not sent automatically to {$name}: the {$flow} flow isn't sent automatically", ['reason' => 'flow', 'flow' => $flow], $lead, $person);

            return;
        }

        $outcome = PipelineEvents::as('Automation', 'automation', fn () => $this->sender->sendAutomatic($lead, Automation::rule('sms.booking_link')));

        PipelineEvents::as('Automation', 'automation', fn () => match ($outcome['status']) {
            'out_of_area' => $this->staff->notify('out_of_area', "Out of area: {$title}", "No booking link was sent to {$name}: {$outcome['reason']}.", $lead, $person),
            'no_times' => $this->staff->notify('no_times', "No times for {$title}", "No booking link was sent to {$name}: {$outcome['reason']}. Send times by hand.", $lead, $person),
            'failed', 'blocked' => $this->staff->notify('send_failed', "Booking link not sent: {$title}", "The automatic booking link for {$name} was not sent: {$outcome['reason']}.", $lead, $person),
            default => null,
        });
    }

    /** txn.booking.changed: a cancellation can tell staff. */
    public function bookingChanged(string $event, $activity, $person, $lead, string $summary): void
    {
        if ($event === 'cancelled') {
            $this->staff->notify('booking_cancelled', 'Booking cancelled: '.PipelineEvents::name($person), $summary.'.', $lead, $person);
        }
    }
}
