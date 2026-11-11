<?php

namespace Webkul\Admin\Support\Contact;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;
use Webkul\Admin\Models\Worker;
use Webkul\Contact\Models\Person;

/**
 * Emails workers about their customer visits (CRM spec, Notifications):
 * booked → the assigned worker; rescheduled → the old and the new worker;
 * cancelled → the assigned worker. Sent through Krayin's mail transport, so
 * the mail guard (config/mail_guard.php) applies. A failed email is logged
 * and never stops the booking sync.
 */
final class WorkerVisitNotifier
{
    public function booked($activity, Person $person, array $workerIds): void
    {
        foreach ($this->workers($workerIds) as $worker) {
            $this->send($worker, 'New visit: '.$this->when($activity->schedule_from), $this->body(
                $worker,
                'A customer booked a home visit with you.',
                $activity,
                $person
            ));
        }
    }

    /**
     * $oldFrom: the visit's start before the reschedule. A worker who had
     * the visit and no longer does is told it was taken off their schedule.
     */
    public function rescheduled($activity, Person $person, array $oldWorkerIds, array $newWorkerIds, $oldFrom): void
    {
        $previously = 'Previously: '.$this->when($oldFrom);

        foreach ($this->workers(array_unique([...$oldWorkerIds, ...$newWorkerIds])) as $worker) {
            $keeps = in_array($worker->id, $newWorkerIds);
            $had = in_array($worker->id, $oldWorkerIds);

            if ($keeps && $had) {
                $subject = 'Visit moved: '.$this->when($activity->schedule_from);
                $intro = "The customer moved their visit to a new time.\n{$previously}";
            } elseif ($keeps) {
                $subject = 'New visit: '.$this->when($activity->schedule_from);
                $intro = 'A customer rescheduled their visit to a time with you.';
            } else {
                $subject = 'Visit removed from your schedule: '.$this->when($oldFrom);
                $intro = "The customer rescheduled to a time with another worker; this visit is no longer yours.\n{$previously}";
            }

            $this->send($worker, $subject, $this->body($worker, $intro, $activity, $person, $keeps));
        }
    }

    public function cancelled($activity, Person $person, array $workerIds): void
    {
        foreach ($this->workers($workerIds) as $worker) {
            $this->send($worker, 'Visit cancelled: '.$this->when($activity->schedule_from), $this->body(
                $worker,
                'The customer cancelled this visit. It is off your schedule.',
                $activity,
                $person
            ));
        }
    }

    private function workers(array $ids)
    {
        return Worker::query()
            ->whereIn('id', array_filter(array_map('intval', $ids)))
            ->get()
            ->filter(fn (Worker $worker) => filter_var($worker->email, FILTER_VALIDATE_EMAIL));
    }

    private function body(Worker $worker, string $intro, $activity, Person $person, bool $showTime = true): string
    {
        $phones = collect($person->contact_numbers ?? [])->pluck('value')->filter()->implode(', ');
        $lines = [
            "Hello {$worker->name},",
            '',
            $intro,
            '',
            "Customer: {$person->name}",
        ];

        if ($showTime) {
            $lines[] = 'When: '.$this->when($activity->schedule_from).' - '
                .CarbonImmutable::parse($activity->schedule_to)->format('g:i A')
                .' ('.config('slot_finder.timezone').')';
        }

        $lines[] = 'Address: '.($activity->location ?: 'not known yet');

        if ($phones !== '') {
            $lines[] = "Phone: {$phones}";
        }

        $lines[] = '';
        $lines[] = 'Open in Krayin: '.route('admin.activities.edit', $activity->id);

        return implode("\n", $lines);
    }

    /** Activity times are stored as slot-finder (Central) wall time. */
    private function when($time): string
    {
        return CarbonImmutable::parse($time)->format('D M j, g:i A');
    }

    private function send(Worker $worker, string $subject, string $body): void
    {
        try {
            Mail::raw($body, fn ($message) => $message->to($worker->email)->subject($subject));
        } catch (Throwable $exception) {
            Log::error('Krayin could not email a worker about a visit.', [
                'worker_id' => $worker->id,
                'subject' => $subject,
                'exception_class' => get_class($exception),
            ]);
        }
    }
}
