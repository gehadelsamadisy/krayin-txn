<?php

namespace Webkul\Admin\Support\Automation;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;
use Webkul\Admin\Support\Contact\PipelineEvents;

/**
 * Staff notifications engine: a short plain email to the staff list when
 * something needs a person (Automation::STAFF_EVENTS). The email guard
 * applies: while it is on, staff addresses must be on the allowed list.
 */
final class StaffNotifier
{
    public function notify(string $event, string $subject, string $body, $lead = null, $person = null): void
    {
        if (! Automation::on('staff') || ! in_array($event, (array) config('automation.staff.events', []), true)) {
            return;
        }

        $to = array_values(array_filter((array) config('automation.staff.recipients', [])));

        if (! $to) {
            return;
        }

        if ($lead) {
            $body .= "\n\nLead: ".route('admin.leads.view', $lead->id);
        }

        $body .= "\n\n(Krayin automation: Settings > TXN Settings > Automation)";

        try {
            Mail::raw($body, fn ($message) => $message->to($to)->subject('[Krayin] '.$subject));
        } catch (Throwable $exception) {
            Log::error('Staff notification not sent.', ['event' => $event, 'error' => $exception->getMessage()]);
            PipelineEvents::record('automation_failed', "Staff notification \"{$subject}\" not sent: ".$exception->getMessage(), ['event' => $event], $lead, $person);

            return;
        }

        PipelineEvents::record('staff_notified', 'Told staff ('.implode(', ', $to)."): {$subject}", ['event' => $event, 'to' => $to], $lead, $person);
    }
}
