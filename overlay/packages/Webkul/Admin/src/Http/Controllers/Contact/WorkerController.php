<?php

namespace Webkul\Admin\Http\Controllers\Contact;

use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Admin\Models\Worker;
use Webkul\Admin\Models\WorkerScheduleSetting;
use Webkul\Admin\Models\WorkerTimeOff;
use Webkul\Admin\Support\Contact\SlotSuggestionException;
use Webkul\Admin\Support\Contact\SlotSuggestionService;
use Webkul\Admin\Support\Contact\WorkerRouteService;
use Webkul\Activity\Models\ActivityProxy;

class WorkerController extends Controller
{
    private const WEEKDAYS = [
        1 => 'Monday',
        2 => 'Tuesday',
        3 => 'Wednesday',
        4 => 'Thursday',
        5 => 'Friday',
        6 => 'Saturday',
        7 => 'Sunday',
    ];

    public function index(): View
    {
        $workers = Worker::query()
            ->with('timeOffs')
            ->orderBy('name')
            ->paginate(25);

        return view('admin::contacts.workers.index', compact('workers'));
    }

    public function create(): View
    {
        return view('admin::contacts.workers.form', [
            'worker' => null,
            'weekdays' => self::WEEKDAYS,
        ]);
    }

    public function store(Request $request, SlotSuggestionService $geocoder): RedirectResponse
    {
        $worker = $this->saveWorker($request, null, $geocoder);

        return redirect()
            ->route('admin.contacts.workers.index')
            ->with('success', "Worker {$worker->name} was registered.");
    }

    public function edit(Worker $worker): View
    {
        $worker->load('timeOffs');

        return view('admin::contacts.workers.form', [
            'worker' => $worker,
            'weekdays' => self::WEEKDAYS,
        ]);
    }

    public function update(Request $request, Worker $worker, SlotSuggestionService $geocoder): RedirectResponse
    {
        $worker = $this->saveWorker($request, $worker, $geocoder);

        return redirect()
            ->route('admin.contacts.workers.index')
            ->with('success', "Worker {$worker->name} was updated.");
    }

    /**
     * One worker's week (Monday to Sunday) as a calendar: work hours,
     * holidays, time off, appointments and the slot times still open.
     * Activity times are stored as wall time in the slot finder's time zone,
     * so they are compared and shown as stored.
     */
    public function schedule(Request $request, Worker $worker): View
    {
        $timezone = config('slot_finder.timezone');

        $validated = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $now = CarbonImmutable::now($timezone);
        $today = $now->startOfDay();
        $day = filled($validated['date'] ?? null)
            ? CarbonImmutable::createFromFormat('!Y-m-d', $validated['date'], $timezone)
            : $today;
        $weekStart = $day->startOfWeek(CarbonImmutable::MONDAY);

        $settings = $this->scheduleSettings();
        $workHours = $worker->work_hours ?: ($settings->default_work_hours ?? []);
        $holidays = collect($settings->holidays ?? [])->keyBy('date');
        $worker->load('timeOffs');

        $appointments = $worker->activitiesBetween($weekStart, $weekStart->addDays(7))
            ->orderBy('schedule_from')
            ->get();

        $customers = DB::table('person_activities')
            ->join('persons', 'persons.id', '=', 'person_activities.person_id')
            ->whereIn('person_activities.activity_id', $appointments->pluck('id'))
            ->orderBy('persons.name')
            ->get(['person_activities.activity_id', 'persons.id', 'persons.name'])
            ->groupBy('activity_id');

        $wall = fn ($value) => CarbonImmutable::createFromFormat('Y-m-d H:i:s', $value->format('Y-m-d H:i:s'), $timezone);

        $days = collect(range(0, 6))->map(function (int $offset) use ($weekStart, $workHours, $holidays, $worker, $appointments, $customers, $wall, $now) {
            $date = $weekStart->addDays($offset);
            $dayEnd = $date->addDay();
            $hours = $workHours[$date->dayOfWeekIso] ?? null;
            $holiday = $holidays->get($date->toDateString());

            $timeOffs = $worker->timeOffs
                ->filter(fn ($entry) => $entry->start_date->toDateString() <= $date->toDateString()
                    && $entry->end_date->toDateString() >= $date->toDateString())
                ->values();

            $events = $appointments
                ->map(function ($activity) use ($wall) {
                    $start = $wall($activity->schedule_from);
                    $end = $activity->schedule_to ? $wall($activity->schedule_to) : null;

                    return [
                        'activity' => $activity,
                        'worker_task' => $this->isWorkerTask($activity),
                        'worker_task_type' => $this->workerTaskType($activity),
                        'real_start' => $start,
                        // A missing end, or one not after the start, shows as one hour.
                        'real_end' => $end && $end->gt($start) ? $end : $start->addHour(),
                    ];
                })
                ->filter(fn ($event) => $event['real_start']->lt($dayEnd) && $event['real_end']->gt($date))
                ->map(fn ($event) => $event + [
                    'start' => $event['real_start']->max($date),
                    'end' => $event['real_end']->min($dayEnd),
                    'customers' => $customers[$event['activity']->id] ?? collect(),
                    'cancelled' => $event['activity']->is_done && str_starts_with($event['activity']->title ?? '', 'Cancelled'),
                ])
                ->values();

            $dayActivities = $events->pluck('activity');

            return [
                'date' => $date,
                'hours' => $hours,
                'holiday' => $holiday,
                'timeOffs' => $timeOffs,
                'fullDayOff' => $timeOffs->first(fn ($entry) => ! $entry->start_time || ! $entry->end_time),
                'events' => $this->withLanes($events->all()),
                'visits' => $dayActivities->filter(fn ($activity) => ! $activity->is_done && ! $this->isWorkerTask($activity))->count(),
                'workTasks' => $dayActivities->filter(fn ($activity) => ! $activity->is_done && $this->isWorkerTask($activity) && $this->workerTaskType($activity) === 'work')->count(),
                'workRouteStops' => $events->filter(fn ($event) => $event['worker_task'] && $event['worker_task_type'] === 'work' && ! $event['cancelled'])->count(),
                'otherBookings' => $dayActivities->filter(fn ($activity) => ! $activity->is_done && $this->workerTaskType($activity) === 'other')->count(),
                // Only times a customer could still be offered.
                'openSlots' => $holiday ? [] : array_values(array_filter(
                    $this->openSlotTimes($date, $hours, $timeOffs, $dayActivities),
                    fn (CarbonImmutable $slot) => $slot->gt($now)
                )),
            ];
        });

        // Visible hours: the usual working day, stretched to fit anything outside it.
        $toHour = fn (CarbonImmutable $time) => (int) ceil(($time->hour * 60 + $time->minute) / 60);
        $gridStart = 8;
        $gridEnd = 19;

        foreach ($days as $entry) {
            if ($entry['hours']) {
                $gridStart = min($gridStart, (int) substr($entry['hours'][0], 0, 2));
                $gridEnd = max($gridEnd, $toHour(CarbonImmutable::createFromFormat('H:i', $entry['hours'][1])));
            }

            foreach ($entry['events'] as $event) {
                $gridStart = min($gridStart, $event['start']->hour);
                $gridEnd = max($gridEnd, $event['end']->isSameDay($entry['date']) ? $toHour($event['end']) : 24);
            }
        }

        return view('admin::contacts.workers.schedule', [
            'worker' => $worker,
            'workers' => Worker::query()->orderBy('name')->get(['id', 'name']),
            'weekStart' => $weekStart,
            'today' => $today,
            'now' => $now,
            'days' => $days,
            'usesDefaultHours' => ! $worker->work_hours,
            'gridStart' => $gridStart,
            'gridEnd' => $gridEnd,
        ]);
    }

    public function createBooking(Request $request, Worker $worker): View
    {
        $validated = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $timezone = config('slot_finder.timezone');
        $date = filled($validated['date'] ?? null)
            ? CarbonImmutable::createFromFormat('!Y-m-d', $validated['date'], $timezone)
            : CarbonImmutable::now($timezone)->startOfDay();

        return view('admin::contacts.workers.booking', [
            'worker' => $worker,
            'date' => $date,
        ]);
    }

    public function storeBooking(Request $request, Worker $worker): RedirectResponse
    {
        if (! $worker->is_active) {
            throw ValidationException::withMessages([
                'worker' => 'An inactive worker cannot be scheduled.',
            ]);
        }

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'schedule_from' => ['required', 'date_format:Y-m-d\TH:i'],
            'schedule_to' => ['required', 'date_format:Y-m-d\TH:i', 'after:schedule_from'],
            'comment' => ['nullable', 'string', 'max:2000'],
            'booking_type' => ['required', Rule::in(['work', 'other'])],
            'location' => ['required_if:booking_type,work', 'nullable', 'string', 'max:255'],
        ]);

        $timezone = config('slot_finder.timezone');
        $start = CarbonImmutable::createFromFormat('Y-m-d\TH:i', $validated['schedule_from'], $timezone);
        $end = CarbonImmutable::createFromFormat('Y-m-d\TH:i', $validated['schedule_to'], $timezone);

        if ($worker->activitiesBetween($start, $end)->where('activities.is_done', false)->exists()) {
            throw ValidationException::withMessages([
                'schedule_from' => 'This worker already has an activity scheduled during this time.',
            ]);
        }

        if ($this->hasTimeOff($worker, $start, $end)) {
            throw ValidationException::withMessages([
                'schedule_from' => 'This worker has time off during this time.',
            ]);
        }

        Event::dispatch('activity.create.before');

        $activity = DB::transaction(function () use ($validated, $worker, $start, $end) {
            $activity = ActivityProxy::modelClass()::query()->create([
                'title' => $validated['title'],
                'type' => 'task',
                'comment' => $validated['comment'] ?? null,
                'location' => $validated['location'] ?? null,
                'additional' => json_encode([
                    'txn' => [
                        'kind' => 'worker_task',
                        'worker_task_type' => $validated['booking_type'],
                    ],
                ]),
                'schedule_from' => $start->format('Y-m-d H:i:s'),
                'schedule_to' => $end->format('Y-m-d H:i:s'),
                'is_done' => false,
                'user_id' => auth()->guard('user')->user()->id,
                'worker_id' => $worker->id,
            ]);

            $activity->workers()->sync([$worker->id]);

            return $activity;
        });

        Event::dispatch('activity.create.after', $activity);

        return redirect()
            ->to($this->scheduleUrl($worker, $start))
            ->with('success', 'Worker booking was created.');
    }

    /**
     * Every worker's route for one day (or one worker's): their visits in
     * time order from the start address, drawn on a map with drive times.
     */
    public function routes(Request $request, WorkerRouteService $routes): View
    {
        $timezone = config('slot_finder.timezone');

        $validated = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'worker' => ['nullable', 'integer', 'exists:workers,id'],
        ]);

        $now = CarbonImmutable::now($timezone);
        $today = $now->startOfDay();
        $day = filled($validated['date'] ?? null)
            ? CarbonImmutable::createFromFormat('!Y-m-d', $validated['date'], $timezone)
            : $today;
        $selected = $validated['worker'] ?? null;

        $workers = Worker::query()->orderBy('name')->get();
        $shown = $workers
            ->filter(fn (Worker $worker) => $selected ? $worker->id === (int) $selected : $worker->is_active)
            ->values();

        return view('admin::contacts.workers.routes', [
            'day' => $day,
            'today' => $today,
            'now' => $now,
            'workers' => $workers,
            'selected' => $selected ? (int) $selected : null,
            'routes' => $routes->dayRoutes($shown, $day),
        ]);
    }

    public function editTimeOff(Worker $worker, WorkerTimeOff $timeOff): View
    {
        return view('admin::contacts.workers.time-off', compact('worker', 'timeOff'));
    }

    public function updateTimeOff(Request $request, Worker $worker, WorkerTimeOff $timeOff): RedirectResponse
    {
        $validated = $request->validate([
            'time_off' => ['required', 'array', 'size:1'],
            'time_off.0' => ['required', 'array'],
            'time_off.0.start_date' => ['nullable', 'date_format:Y-m-d'],
            'time_off.0.end_date' => ['nullable', 'date_format:Y-m-d'],
            'time_off.0.start_time' => ['nullable', 'date_format:H:i'],
            'time_off.0.end_time' => ['nullable', 'date_format:H:i'],
            'time_off.0.type' => ['nullable', Rule::in(['annual_leave', 'sick', 'personal', 'other'])],
            'time_off.0.note' => ['nullable', 'string', 'max:1000'],
        ]);

        $entries = $this->normalizeTimeOff($validated['time_off']);

        if (! $entries) {
            throw ValidationException::withMessages([
                'time_off.0.start_date' => 'Enter the date range and type, or delete this time off instead.',
            ]);
        }

        $timeOff->update($entries[0]);

        return redirect()
            ->to($this->scheduleUrl($worker, $timeOff->start_date))
            ->with('success', 'Time off was updated.');
    }

    public function destroyTimeOff(Worker $worker, WorkerTimeOff $timeOff): RedirectResponse
    {
        $timeOff->delete();

        return redirect()
            ->to($this->scheduleUrl($worker, $timeOff->start_date))
            ->with('success', 'Time off was deleted.');
    }

    private function scheduleUrl(Worker $worker, $date): string
    {
        return route('admin.contacts.workers.schedule', ['worker' => $worker->id, 'date' => $date->format('Y-m-d')]);
    }

    private function isWorkerTask($activity): bool
    {
        $additional = is_array($activity->additional)
            ? $activity->additional
            : json_decode($activity->additional ?? '', true);

        return data_get($additional, 'txn.kind') === 'worker_task';
    }

    private function workerTaskType($activity): string
    {
        $additional = is_array($activity->additional)
            ? $activity->additional
            : json_decode($activity->additional ?? '', true);

        return data_get($additional, 'txn.worker_task_type', 'work');
    }

    private function hasTimeOff(Worker $worker, CarbonImmutable $start, CarbonImmutable $end): bool
    {
        $timeOffs = $worker->timeOffs()
            ->whereDate('start_date', '<=', $end->toDateString())
            ->whereDate('end_date', '>=', $start->toDateString())
            ->get();

        for ($day = $start->startOfDay(); $day->lt($end); $day = $day->addDay()) {
            $dayStart = $start->max($day);
            $dayEnd = $end->min($day->addDay());

            if ($dayStart->gte($dayEnd)) {
                continue;
            }

            foreach ($timeOffs as $timeOff) {
                if ($day->toDateString() < $timeOff->start_date->toDateString()
                    || $day->toDateString() > $timeOff->end_date->toDateString()) {
                    continue;
                }

                if (! $timeOff->start_time || ! $timeOff->end_time) {
                    return true;
                }

                $offStart = CarbonImmutable::createFromFormat(
                    '!Y-m-d H:i',
                    $day->toDateString().' '.substr($timeOff->start_time, 0, 5),
                    $start->getTimezone()
                );
                $offEnd = CarbonImmutable::createFromFormat(
                    '!Y-m-d H:i',
                    $day->toDateString().' '.substr($timeOff->end_time, 0, 5),
                    $start->getTimezone()
                );

                if ($dayStart->lt($offEnd) && $offStart->lt($dayEnd)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Side-by-side columns for overlapping events: each gets a lane and the
     * number of lanes in its overlapping group. Events must be sorted by start.
     */
    private function withLanes(array $events): array
    {
        $result = [];
        $group = [];
        $groupEnd = null;

        $flush = function () use (&$group, &$result) {
            $laneEnds = [];

            foreach ($group as $index => $event) {
                $lane = 0;

                while (isset($laneEnds[$lane]) && $laneEnds[$lane]->gt($event['start'])) {
                    $lane++;
                }

                $laneEnds[$lane] = $event['end'];
                $group[$index]['lane'] = $lane;
            }

            foreach ($group as $event) {
                $result[] = $event + ['lanes' => count($laneEnds)];
            }

            $group = [];
        };

        foreach ($events as $event) {
            if ($group && $event['start']->gte($groupEnd)) {
                $flush();
                $groupEnd = null;
            }

            $group[] = $event;
            $groupEnd = $groupEnd && $groupEnd->gt($event['end']) ? $groupEnd : $event['end'];
        }

        if ($group) {
            $flush();
        }

        return $result;
    }

    /** Slot times inside the work hours that time off and open appointments leave free. */
    private function openSlotTimes(CarbonImmutable $day, ?array $hours, $timeOffs, $appointments): array
    {
        if (! $hours) {
            return [];
        }

        $at = fn (string $time) => CarbonImmutable::createFromFormat('!Y-m-d H:i', $day->toDateString().' '.$time, $day->getTimezone());
        $wall = fn ($value) => CarbonImmutable::createFromFormat('Y-m-d H:i:s', $value->format('Y-m-d H:i:s'), $day->getTimezone());
        $open = [];

        foreach (config('slot_finder.slot_times') as $time) {
            $start = $at($time);
            $end = $start->addMinutes(config('slot_finder.visit_minutes'));

            if ($start->lt($at($hours[0])) || $end->gt($at($hours[1]))) {
                continue;
            }

            $offOverlap = $timeOffs->contains(fn ($entry) => ! $entry->start_time || ! $entry->end_time
                || ($start->lt($at(substr($entry->end_time, 0, 5))) && $at(substr($entry->start_time, 0, 5))->lt($end)));

            $busy = $appointments->contains(fn ($activity) => ! $activity->is_done
                && $activity->schedule_to
                && $start->lt($wall($activity->schedule_to))
                && $wall($activity->schedule_from)->lt($end));

            if (! $offOverlap && ! $busy) {
                $open[] = $start;
            }
        }

        return $open;
    }

    public function settings(): View
    {
        $settings = $this->scheduleSettings();

        return view('admin::contacts.workers.settings', [
            'settings' => $settings,
            'weekdays' => self::WEEKDAYS,
        ]);
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'default_work_hours' => ['nullable', 'array'],
            'default_work_hours.*' => ['array'],
            'default_work_hours.*.start' => ['nullable', 'date_format:H:i'],
            'default_work_hours.*.end' => ['nullable', 'date_format:H:i'],
            'holidays' => ['nullable', 'array', 'max:100'],
            'holidays.*' => ['array'],
            'holidays.*.date' => ['nullable', 'date_format:Y-m-d'],
            'holidays.*.name' => ['nullable', 'string', 'max:255'],
        ]);

        $workHours = $this->normalizeWorkHours($validated['default_work_hours'] ?? [], 'default_work_hours');
        $holidays = $this->normalizeHolidays($validated['holidays'] ?? []);

        WorkerScheduleSetting::query()->updateOrCreate(
            ['id' => 1],
            ['default_work_hours' => $workHours, 'holidays' => $holidays]
        );

        return redirect()
            ->route('admin.contacts.workers.settings')
            ->with('success', 'Company schedule settings were saved.');
    }

    private function saveWorker(
        Request $request,
        ?Worker $worker,
        SlotSuggestionService $geocoder
    ): Worker {
        $validated = $this->validatedData($request, $worker);

        try {
            $location = $geocoder->locateWorkerAddress($validated['start_address']);
        } catch (SlotSuggestionException $exception) {
            throw ValidationException::withMessages(['start_address' => $exception->getMessage()]);
        }

        $workHours = $this->normalizeWorkHours($validated['work_hours'] ?? [], 'work_hours');
        $timeOffs = $this->normalizeTimeOff($validated['time_off'] ?? []);
        $services = collect(preg_split('/\R/', $validated['services_text'] ?? ''))
            ->map(fn (string $service) => trim($service))
            ->filter()
            ->unique(fn (string $service) => mb_strtolower($service))
            ->values()
            ->all();

        return DB::transaction(function () use ($validated, $worker, $location, $workHours, $timeOffs, $services) {
            $attributes = [
                'name' => $validated['name'],
                'phone' => $validated['phone'] ?? null,
                'email' => $validated['email'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'is_active' => (bool) $validated['is_active'],
                'start_address' => trim($validated['start_address']),
                'home_latitude' => $location['lat'],
                'home_longitude' => $location['lon'],
                'work_hours' => $workHours,
                'priority' => (int) $validated['priority'],
                'services' => $services,
                'max_visits_per_day' => $validated['max_visits_per_day'] ?: null,
                'calendar_color' => $validated['calendar_color'] ?: null,
            ];

            $worker = $worker
                ? tap($worker)->update($attributes)
                : Worker::query()->create($attributes);

            $worker->timeOffs()->delete();

            foreach ($timeOffs as $timeOff) {
                $worker->timeOffs()->create($timeOff);
            }

            return $worker;
        });
    }

    private function validatedData(Request $request, ?Worker $worker = null): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => [
                'nullable',
                'email',
                'max:255',
                Rule::unique('workers', 'email')->ignore($worker?->id),
            ],
            'notes' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['required', 'boolean'],
            'start_address' => ['required', 'string', 'max:255'],
            'work_hours' => ['nullable', 'array'],
            'work_hours.*' => ['array'],
            'work_hours.*.start' => ['nullable', 'date_format:H:i'],
            'work_hours.*.end' => ['nullable', 'date_format:H:i'],
            'priority' => ['required', 'integer', 'min:1', 'max:65535'],
            'services_text' => ['nullable', 'string', 'max:5000'],
            'max_visits_per_day' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'calendar_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'time_off' => ['nullable', 'array', 'max:100'],
            'time_off.*' => ['array'],
            'time_off.*.start_date' => ['nullable', 'date_format:Y-m-d'],
            'time_off.*.end_date' => ['nullable', 'date_format:Y-m-d'],
            'time_off.*.start_time' => ['nullable', 'date_format:H:i'],
            'time_off.*.end_time' => ['nullable', 'date_format:H:i'],
            'time_off.*.type' => ['nullable', Rule::in(['annual_leave', 'sick', 'personal', 'other'])],
            'time_off.*.note' => ['nullable', 'string', 'max:1000'],
        ]);

        $this->normalizeWorkHours($validated['work_hours'] ?? [], 'work_hours');
        $this->normalizeTimeOff($validated['time_off'] ?? []);

        return $validated;
    }

    private function normalizeWorkHours(array $hours, string $field): array
    {
        $normalized = [];

        foreach (self::WEEKDAYS as $day => $name) {
            $start = $hours[$day]['start'] ?? null;
            $end = $hours[$day]['end'] ?? null;

            if (blank($start) && blank($end)) {
                continue;
            }

            if (blank($start) || blank($end) || $start >= $end) {
                throw ValidationException::withMessages([
                    "{$field}.{$day}.start" => "{$name}: enter both times, and make the end time later than the start time.",
                ]);
            }

            $normalized[$day] = [$start, $end];
        }

        return $normalized;
    }

    private function normalizeTimeOff(array $entries): array
    {
        $normalized = [];

        foreach ($entries as $index => $entry) {
            if (collect($entry)->every(fn ($value) => blank($value))) {
                continue;
            }

            if (blank($entry['start_date'] ?? null) || blank($entry['end_date'] ?? null) || blank($entry['type'] ?? null)) {
                throw ValidationException::withMessages([
                    "time_off.{$index}.start_date" => 'Enter the date range and type for each time-off entry.',
                ]);
            }

            if ($entry['start_date'] > $entry['end_date']) {
                throw ValidationException::withMessages([
                    "time_off.{$index}.end_date" => 'The end date must be on or after the start date.',
                ]);
            }

            $startTime = $entry['start_time'] ?? null;
            $endTime = $entry['end_time'] ?? null;

            if (filled($startTime) !== filled($endTime)) {
                throw ValidationException::withMessages([
                    "time_off.{$index}.start_time" => 'Enter both times for partial-day time off, or leave both blank for the whole day.',
                ]);
            }

            if (filled($startTime) && ($entry['start_date'] !== $entry['end_date'] || $startTime >= $endTime)) {
                throw ValidationException::withMessages([
                    "time_off.{$index}.start_time" => 'Partial-day time off must be on one date and end after it starts.',
                ]);
            }

            $normalized[] = [
                'start_date' => $entry['start_date'],
                'end_date' => $entry['end_date'],
                'start_time' => $startTime ?: null,
                'end_time' => $endTime ?: null,
                'type' => $entry['type'],
                'note' => $entry['note'] ?? null,
            ];
        }

        return $normalized;
    }

    private function normalizeHolidays(array $entries): array
    {
        $normalized = [];

        foreach ($entries as $index => $entry) {
            if (collect($entry)->every(fn ($value) => blank($value))) {
                continue;
            }

            if (blank($entry['date'] ?? null)) {
                throw ValidationException::withMessages([
                    "holidays.{$index}.date" => 'Select a date for each company holiday.',
                ]);
            }

            $normalized[] = [
                'date' => CarbonImmutable::createFromFormat('!Y-m-d', $entry['date'])->toDateString(),
                'name' => trim($entry['name'] ?? '') ?: 'Company holiday',
            ];
        }

        return collect($normalized)
            ->unique('date')
            ->sortBy('date')
            ->values()
            ->all();
    }

    private function scheduleSettings(): WorkerScheduleSetting
    {
        return WorkerScheduleSetting::query()->firstOrCreate(
            ['id' => 1],
            [
                'default_work_hours' => config('slot_finder.default_work_hours', []),
                'holidays' => [],
            ]
        );
    }
}
