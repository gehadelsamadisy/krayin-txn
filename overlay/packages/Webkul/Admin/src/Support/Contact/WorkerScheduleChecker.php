<?php

namespace Webkul\Admin\Support\Contact;

use Carbon\CarbonImmutable;
use Webkul\Admin\Models\Worker;
use Webkul\Admin\Models\WorkerScheduleSetting;

/**
 * Finds problems in the workers' schedules (php artisan txn:check-schedules):
 * two activities of one worker at the same time, an end not after the start,
 * scheduled work outside work hours or on a holiday, day off or time off,
 * work the route map can't place, and stops the worker can't reach in time
 * from the previous one (same drive times as the Routes page).
 *
 * Cancelled bookings and done busy blocks don't occupy the worker (same as
 * the slot finder). A done customer visit still does: the worker was there.
 */
final class WorkerScheduleChecker
{
    public function __construct(private WorkerRouteService $routes) {}

    /** @return array<int, array{worker: string, activity: int, start: string, problem: string}> */
    public function check(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $timezone = config('slot_finder.timezone');
        $settings = WorkerScheduleSetting::query()->find(1);
        $defaultHours = $settings?->default_work_hours ?? config('slot_finder.default_work_hours');
        $holidays = collect($settings?->holidays ?? [])->keyBy('date');
        $problems = [];

        foreach (Worker::query()->with('timeOffs')->orderBy('name')->get() as $worker) {
            $add = function ($activity, string $problem) use (&$problems, $worker) {
                $problems[] = [
                    'worker' => $worker->name,
                    'activity' => $activity->id,
                    'start' => $activity->schedule_from->format('D Y-m-d H:i'),
                    'problem' => $problem,
                ];
            };

            $activities = $worker->activitiesBetween($from, $to)
                ->orderBy('schedule_from')
                ->get()
                ->filter(fn ($activity) => $this->occupies($activity))
                ->map(function ($activity) use ($timezone) {
                    $start = CarbonImmutable::createFromFormat('Y-m-d H:i:s', $activity->schedule_from->format('Y-m-d H:i:s'), $timezone);
                    $end = $activity->schedule_to
                        ? CarbonImmutable::createFromFormat('Y-m-d H:i:s', $activity->schedule_to->format('Y-m-d H:i:s'), $timezone)
                        : null;

                    return ['activity' => $activity, 'start' => $start, 'end' => $end];
                })
                ->values();

            foreach ($activities as $index => $entry) {
                ['activity' => $activity, 'start' => $start, 'end' => $end] = $entry;

                if (! $end || $end->lte($start)) {
                    $add($activity, 'Ends '.($end ? $end->format('D Y-m-d H:i') : 'never').', not after it starts');
                    $end = $start->addHour();
                }

                foreach ($activities->slice($index + 1) as $other) {
                    if ($other['start']->gte($end)) {
                        break;
                    }

                    $add($activity, "Overlaps #{$other['activity']->id} \"{$other['activity']->title}\" at ".$other['start']->format('H:i'));
                }

                if (! $this->isVisit($activity)) {
                    continue;
                }

                $date = $start->toDateString();
                $hours = ($worker->work_hours ?: $defaultHours)[$start->dayOfWeekIso] ?? null;

                if ($holidays->has($date)) {
                    $add($activity, 'On the company holiday "'.($holidays[$date]['name'] ?? $date).'"');
                } elseif (! $hours) {
                    $add($activity, 'On a day off');
                } elseif ($start->format('H:i') < $hours[0] || $end->format('H:i') > $hours[1] || ! $end->isSameDay($start)) {
                    $add($activity, "Outside work hours {$hours[0]}-{$hours[1]}");
                }

                foreach ($worker->timeOffs as $timeOff) {
                    if ($date < $timeOff->start_date->toDateString() || $date > $timeOff->end_date->toDateString()) {
                        continue;
                    }

                    if (
                        ! $timeOff->start_time || ! $timeOff->end_time
                        || ($start->format('H:i') < substr($timeOff->end_time, 0, 5) && substr($timeOff->start_time, 0, 5) < $end->format('H:i'))
                    ) {
                        $add($activity, 'During time off ('.$timeOff->type.')');
                    }
                }

                if (! filled($activity->location)) {
                    $add($activity, 'No address, so it is not on the route map');
                } elseif ($activity->latitude === null) {
                    $add($activity, 'Address not located yet; the route page will try to locate it');
                }
            }

            $days = $activities
                ->filter(fn ($entry) => $this->isVisit($entry['activity']))
                ->map(fn ($entry) => $entry['start']->startOfDay())
                ->unique(fn ($day) => $day->toDateString());

            foreach ($days as $day) {
                foreach ($this->routes->dayRoute($worker, $day)['stops'] as $stop) {
                    if ($stop['late_minutes'] > 0) {
                        $problems[] = [
                            'worker' => $worker->name,
                            'activity' => $stop['activity_id'],
                            'start' => $stop['start']->format('D Y-m-d H:i'),
                            'problem' => "Arrives about {$stop['late_minutes']} min late ({$stop['leg']['minutes']} min drive from the previous stop)",
                        ];
                    }
                }
            }
        }

        return $problems;
    }

    private function occupies($activity): bool
    {
        if (! $activity->is_done) {
            return true;
        }

        return $this->isVisit($activity) && ! str_starts_with($activity->title ?? '', 'Cancelled');
    }

    /** A customer visit or scheduled internal work, not a busy block. */
    private function isVisit($activity): bool
    {
        $additional = is_array($activity->additional) ? $activity->additional : json_decode($activity->additional ?? '', true);

        return filled(data_get($additional, 'calcom.uid'))
            || data_get($additional, 'txn.kind') === 'visit'
            || (
                data_get($additional, 'txn.kind') === 'worker_task'
                && data_get($additional, 'txn.worker_task_type', 'work') === 'work'
            );
    }
}
