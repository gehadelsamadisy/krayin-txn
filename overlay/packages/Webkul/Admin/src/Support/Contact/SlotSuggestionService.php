<?php

namespace Webkul\Admin\Support\Contact;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Webkul\Admin\Models\Worker;
use Webkul\Admin\Models\WorkerScheduleSetting;
use Webkul\Contact\Models\Person;

/**
 * Suggests the appointment times that best fit the workers' routes.
 * PHP port of TXN/Routing/slot_finder.py; settings in config/slot_finder.php.
 *
 * Every worker's free times are scored by the extra driving they add to that
 * worker's day (road times from OSRM) and pooled: the times adding the least
 * driving are offered, whichever worker each belongs to (one already working
 * in the area or passing by). The worker of the time the customer books gets
 * the visit. Times adding more than max_extra_drive_minutes are offered only
 * when nothing cheaper is left. Driving never rules a time out completely.
 */
final class SlotSuggestionService
{
    public const OK = 'OK';

    public const NO_LOCATION = 'NO_LOCATION';

    public const OUT_OF_AREA = 'OUT_OF_AREA';

    public const NO_STAFF = 'NO_STAFF';

    public const NO_SLOTS = 'NO_SLOTS';

    // Geocodio accuracy types that point at the actual property.
    private const ADDRESS_ACCURACY = [
        'rooftop', 'point', 'range_interpolation', 'nearest_rooftop_match',
        'intersection', 'street_center',
    ];

    /** Road drive minutes for the current request, "lat,lon|lat,lon" => minutes. */
    private array $roadMinutes = [];

    /**
     * Times for a Krayin person. $addressOverride (any "street, city, TX zip"
     * or a ZIP) replaces the person's address, for testing.
     */
    public function suggestForPerson(
        Person $person,
        ?string $addressOverride,
        CarbonImmutable $now,
        ?string $requiredService = null
    ): array
    {
        if (filled($addressOverride)) {
            $address = trim($addressOverride);
            $location = $this->locate($this->hasStreetAddress($address) ? $address : null, $this->lastPostalCode($address));
            $location['address'] = $address;
        } else {
            $location = $this->locatePerson($person);
        }

        if ($location['precision'] === null) {
            return ['status' => self::NO_LOCATION, 'location' => $location, 'workers' => [], 'options' => []];
        }

        return $this->suggest($location, $now, $requiredService);
    }

    /**
     * Times for a located customer. $location: lat, lon, precision
     * ("address" or "zip").
     */
    public function suggest(
        array $location,
        CarbonImmutable $now,
        ?string $requiredService = null
    ): array
    {
        $now = $now->setTimezone($this->timezone());
        $point = [$location['lat'], $location['lon']];
        $area = config('slot_finder.service_area');
        $miles = $this->milesBetween([$area['center_lat'], $area['center_lon']], $point);
        $location['miles_from_houston'] = round($miles, 1);
        $result = ['status' => self::OK, 'location' => $location, 'workers' => [], 'options' => []];

        if ($miles > $area['miles']) {
            return ['status' => self::OUT_OF_AREA] + $result;
        }

        $workers = $this->availableWorkers($requiredService);

        if ($workers->isEmpty()) {
            return ['status' => self::NO_STAFF] + $result;
        }

        $scheduleSettings = WorkerScheduleSetting::query()->find(1);
        $defaultWorkHours = $scheduleSettings?->default_work_hours
            ?? config('slot_finder.default_work_hours');
        $holidays = collect($scheduleSettings?->holidays ?? [])
            ->pluck('date')
            ->filter()
            ->all();
        $bookings = $this->loadBookings($now);
        $this->loadRoadTimes($point, $workers->all(), $bookings);

        // Every worker's free times, scored by the driving each adds, in one
        // pool. Each time goes to the worker it suits best; the customer's
        // choice of time decides the worker.
        $candidates = [];
        $pool = [];

        foreach ($this->workersByArea($point, $workers->all(), $bookings, $now) as $entry) {
            $ranked = $this->scoredOptions($point, $entry['worker'], $bookings, $now, $defaultWorkHours, $holidays);
            $onTime = array_filter($ranked, fn ($option) => ! $option['late']);
            $candidates[] = $entry + [
                'on_time' => (bool) $onTime,
                'best_extra' => ($onTime ?: $ranked) ? min(array_column($onTime ?: $ranked, 'extra')) : null,
            ];

            foreach ($ranked as $option) {
                $pool[] = $option + ['entry' => $entry];
            }
        }

        $result['workers'] = array_map(fn ($entry) => [
            'name' => $entry['worker']['name'],
            'color' => $entry['worker']['calendar_color'],
            'jobs' => $entry['jobs'],
            'nearest_job_minutes' => $entry['nearest'],
            'near' => $entry['near'],
            'best_extra_minutes' => $entry['best_extra'],
            // False: every free time would make this worker late.
            'reachable' => $entry['on_time'],
        ], $candidates);

        if (! $pool) {
            return ['status' => self::NO_SLOTS] + $result;
        }

        // Reachable times within the detour cap first, topped up with the next
        // cheapest reachable times, and late ones only as a last resort.
        $tolerance = config('slot_finder.route_fit_tolerance_minutes');
        usort($pool, fn ($a, $b) => $this->optionKey($a, $tolerance) <=> $this->optionKey($b, $tolerance));

        // One worker per time: the best one for it (two workers free at the
        // same hour would be one time for the customer).
        $best = [];

        foreach ($pool as $option) {
            $best[$option['start']->getTimestamp()] ??= $option;
        }

        $result['options'] = array_map(
            fn ($option) => $this->present($option, $option['entry']['worker'], $bookings),
            $this->pick(array_values($best))
        );

        return $result;
    }

    /**
     * Within the detour cap and reachable first, then over the cap, then late;
     * then least extra driving (in tolerance bands, so a few minutes don't
     * decide), sooner, the less busy worker, priority.
     */
    private function optionKey(array $option, int $tolerance): array
    {
        $tier = $option['late'] ? 2 : ($option['extra'] > config('slot_finder.max_extra_drive_minutes') ? 1 : 0);

        return [
            $tier, intdiv($option['extra'], $tolerance), $option['start']->getTimestamp(), $option['extra'],
            $option['entry']['jobs'], $option['entry']['worker']['priority'] ?? 1, $option['entry']['worker']['id'],
        ];
    }

    /**
     * The admin roster is the source of truth for workers eligible for new
     * appointments. An incomplete address cannot be used for route ranking.
     */
    private function availableWorkers(?string $requiredService)
    {
        return Worker::query()
            ->with('timeOffs')
            ->where('is_active', true)
            ->whereNotNull('home_latitude')
            ->whereNotNull('home_longitude')
            ->orderBy('priority')
            ->orderBy('id')
            ->get()
            ->map(fn (Worker $worker) => [
                'id' => $worker->id,
                'name' => $worker->name,
                'home_lat' => (float) $worker->home_latitude,
                'home_lon' => (float) $worker->home_longitude,
                'priority' => (int) $worker->priority,
                'active' => (bool) $worker->is_active,
                'work_hours' => $worker->work_hours ?? [],
                'max_visits_per_day' => $worker->max_visits_per_day,
                'services' => $worker->services ?? [],
                'calendar_color' => $worker->calendar_color,
                'time_off' => $worker->timeOffs->map(fn ($entry) => [
                    'start_date' => $entry->start_date->toDateString(),
                    'end_date' => $entry->end_date->toDateString(),
                    'start_time' => $entry->start_time ? substr($entry->start_time, 0, 5) : null,
                    'end_time' => $entry->end_time ? substr($entry->end_time, 0, 5) : null,
                ])->all(),
            ])
            ->filter(function (array $worker) use ($requiredService) {
                if (! filled($requiredService) || ! $worker['services']) {
                    return true;
                }

                return in_array(
                    mb_strtolower($requiredService),
                    array_map('mb_strtolower', $worker['services']),
                    true
                );
            })
            ->values();
    }

    // =========================================================================
    // Choosing the worker
    // =========================================================================

    /**
     * Each worker's booked jobs in the window and the nearest one to the
     * customer (shown on the booking page; the choice itself is by driving).
     */
    private function workersByArea(array $point, array $workers, array $bookings, CarbonImmutable $now): array
    {
        $windowStart = $now->startOfDay();
        $windowEnd = $windowStart->addDays(config('slot_finder.search_days'));

        $entries = array_map(function ($worker) use ($point, $bookings, $windowStart, $windowEnd) {
            $jobs = array_filter(
                $this->appointmentsFor($worker, $bookings, $windowStart, $windowEnd),
                fn ($a) => $a['lat'] !== null
            );
            $distances = array_map(fn ($a) => $this->driveMinutes([$a['lat'], $a['lon']], $point), $jobs);
            $nearest = $distances ? min($distances) : null;

            return [
                'worker' => $worker,
                'jobs' => count($jobs),
                'nearest' => $nearest,
                'near' => $nearest !== null && $nearest <= config('slot_finder.near_job_minutes'),
            ];
        }, $workers);

        return $entries;
    }

    // =========================================================================
    // Scoring and picking the times
    // =========================================================================

    /** Every free slot of the worker in the search window, best first. */
    private function scoredOptions(
        array $point,
        array $worker,
        array $bookings,
        CarbonImmutable $now,
        array $defaultWorkHours,
        array $holidays
    ): array
    {
        $options = [];

        for ($offset = 0; $offset < config('slot_finder.search_days'); $offset++) {
            $day = $now->startOfDay()->addDays($offset);
            $appointments = $this->appointmentsFor($worker, $bookings, $day, $day->addDay());

            if (
                $worker['max_visits_per_day']
                && count($appointments) >= $worker['max_visits_per_day']
            ) {
                continue;
            }

            foreach ($this->slotTimes($worker, $day, $defaultWorkHours, $holidays) as $start) {
                $option = $this->evaluate($point, $worker, $start, $appointments, $now);

                if ($option) {
                    $options[] = $option;
                }
            }
        }

        $tolerance = config('slot_finder.route_fit_tolerance_minutes');
        usort($options, fn ($a, $b) => [
            $a['late'], intdiv($a['extra'], $tolerance), $a['start']->getTimestamp(), $a['extra'],
        ] <=> [
            $b['late'], intdiv($b['extra'], $tolerance), $b['start']->getTimestamp(), $b['extra'],
        ]);

        return $options;
    }

    /** The best options, at most max_slots_per_day on one day. */
    private function pick(array $ranked): array
    {
        $perDay = [];
        $picked = [];

        foreach ($ranked as $option) {
            $day = $option['start']->toDateString();

            if (($perDay[$day] ?? 0) < config('slot_finder.max_slots_per_day')) {
                $perDay[$day] = ($perDay[$day] ?? 0) + 1;
                $picked[] = $option;

                if (count($picked) === config('slot_finder.slots_to_offer')) {
                    break;
                }
            }
        }

        return $picked;
    }

    /** The slot with its extra driving, or null if the worker is not free. */
    private function evaluate(array $point, array $worker, CarbonImmutable $start, array $appointments, CarbonImmutable $now): ?array
    {
        $end = $start->addMinutes(config('slot_finder.visit_minutes'));

        if ($start->lt($now->addMinutes(config('slot_finder.min_notice_minutes')))) {
            return null;
        }

        foreach ($appointments as $a) {
            if ($a['start']->lt($end) && $start->lt($a['end'])) {
                return null;
            }
        }

        $previous = null;
        $following = null;

        foreach ($appointments as $a) {
            if ($a['end']->lte($start) && (! $previous || $a['end']->gt($previous['end']))) {
                $previous = $a;
            }

            if ($a['start']->gte($end) && (! $following || $a['start']->lt($following['start']))) {
                $following = $a;
            }
        }

        // No job before / after on that day: the worker starts / ends at home.
        $origin = $this->locationOf($previous, $worker);
        $destination = $this->locationOf($following, $worker);
        $driveIn = $this->driveMinutes($origin, $point);
        $driveOut = $this->driveMinutes($point, $destination);
        $direct = $this->driveMinutes($origin, $destination);

        // Can the worker get here from the previous job, and on to the next
        // one, in time? Same rush-hour factors as the Routes page. A late
        // time is still offered, after the ones the worker can reach.
        $withTraffic = fn (int $minutes, CarbonImmutable $depart) => (int) ceil($minutes * WorkerRouteService::trafficFactor($depart));
        $late = ($previous && $previous['end']->addMinutes($withTraffic($driveIn, $previous['end']))->gt($start))
            || ($following && $end->addMinutes($withTraffic($driveOut, $end))->gt($following['start']));

        return [
            'start' => $start,
            'end' => $end,
            'drive_in' => $driveIn,
            'drive_out' => $driveOut,
            'extra' => max(0, $driveIn + $driveOut - $direct),
            'late' => $late,
        ];
    }

    /** slot_times whose visit fits inside the worker's work hours that day. */
    private function slotTimes(
        array $worker,
        CarbonImmutable $day,
        array $defaultWorkHours,
        array $holidays
    ): array
    {
        if (in_array($day->toDateString(), $holidays, true)) {
            return [];
        }

        $workHours = $worker['work_hours'] ?: $defaultWorkHours;
        $hours = $workHours[$day->dayOfWeekIso] ?? null;

        if (! $hours) {
            return [];
        }

        $workStart = $this->at($day, $hours[0]);
        $workEnd = $this->at($day, $hours[1]);
        $times = [];

        foreach (config('slot_finder.slot_times') as $time) {
            $start = $this->at($day, $time);
            $end = $start->addMinutes(config('slot_finder.visit_minutes'));

            if (
                $start->gte($workStart)
                && $end->lte($workEnd)
                && ! $this->isTimeOff($worker, $day, $start, $end)
            ) {
                $times[] = $start;
            }
        }

        return $times;
    }

    private function isTimeOff(
        array $worker,
        CarbonImmutable $day,
        CarbonImmutable $slotStart,
        CarbonImmutable $slotEnd
    ): bool {
        foreach ($worker['time_off'] ?? [] as $timeOff) {
            if (
                $day->toDateString() < $timeOff['start_date']
                || $day->toDateString() > $timeOff['end_date']
            ) {
                continue;
            }

            if (! $timeOff['start_time'] || ! $timeOff['end_time']) {
                return true;
            }

            $offStart = $this->at($day, $timeOff['start_time']);
            $offEnd = $this->at($day, $timeOff['end_time']);

            if ($slotStart->lt($offEnd) && $offStart->lt($slotEnd)) {
                return true;
            }
        }

        return false;
    }

    private function locationOf(?array $appointment, array $worker): array
    {
        // Busy blocks without an address: assume the worker is at home.
        if (! $appointment || $appointment['lat'] === null) {
            return [$worker['home_lat'], $worker['home_lon']];
        }

        return [$appointment['lat'], $appointment['lon']];
    }

    // =========================================================================
    // Existing jobs
    // =========================================================================

    /**
     * Krayin appointments with a worker in the search window. Cancelled
     * (done) ones and ones without a worker do not occupy anyone. Times are
     * stored as wall time in the slot finder's time zone. "person_id" is the
     * worker id.
     */
    private function loadBookings(CarbonImmutable $now): array
    {
        $from = $now->startOfDay();
        $to = $from->addDays(config('slot_finder.search_days'));
        $parse = fn (string $value) => CarbonImmutable::createFromFormat('Y-m-d H:i:s', $value, $this->timezone());

        $legacyAssignments = DB::table('activities')
            ->whereNotNull('activities.worker_id')
            ->where('activities.is_done', false)
            ->whereNotNull('activities.schedule_to')
            ->where('activities.schedule_from', '<', $to->format('Y-m-d H:i:s'))
            ->where('activities.schedule_to', '>', $from->format('Y-m-d H:i:s'))
            ->select([
                'activities.id',
                'activities.worker_id',
                'activities.schedule_from',
                'activities.schedule_to',
                'activities.location',
                'activities.latitude',
                'activities.longitude',
            ]);

        $multiWorkerAssignments = DB::table('activities')
            ->join('activity_workers', 'activity_workers.activity_id', '=', 'activities.id')
            ->where('activities.is_done', false)
            ->whereNotNull('activities.schedule_to')
            ->where('activities.schedule_from', '<', $to->format('Y-m-d H:i:s'))
            ->where('activities.schedule_to', '>', $from->format('Y-m-d H:i:s'))
            ->select([
                'activities.id',
                'activity_workers.worker_id',
                'activities.schedule_from',
                'activities.schedule_to',
                'activities.location',
                'activities.latitude',
                'activities.longitude',
            ]);

        return $legacyAssignments
            ->union($multiWorkerAssignments)
            ->get()
            ->map(fn ($row) => [
                'person_id' => (int) $row->worker_id,
                'start' => $parse($row->schedule_from),
                'end' => $parse($row->schedule_to),
                'address' => $row->location,
                'lat' => $row->latitude !== null ? (float) $row->latitude : null,
                'lon' => $row->longitude !== null ? (float) $row->longitude : null,
            ])
            ->all();
    }

    private function appointmentsFor(array $worker, array $bookings, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $appointments = array_values(array_filter(
            $bookings,
            fn ($a) => $a['person_id'] === $worker['id'] && $a['start']->lt($to) && $from->lt($a['end'])
        ));
        usort($appointments, fn ($a, $b) => $a['start'] <=> $b['start']);

        return $appointments;
    }

    // =========================================================================
    // Output
    // =========================================================================

    private function present(array $option, array $worker, array $bookings): array
    {
        $day = $option['start']->startOfDay();
        $schedule = array_map(fn ($a) => [
            'time' => $a['start']->format('H:i').'-'.$a['end']->format('H:i'),
            'label' => $a['address'] ?? 'busy (no address)',
            'new' => false,
        ], $this->appointmentsFor($worker, $bookings, $day, $day->addDay()));
        $schedule[] = [
            'time' => $option['start']->format('H:i').'-'.$option['end']->format('H:i'),
            'label' => 'NEW CUSTOMER',
            'new' => true,
        ];
        usort($schedule, fn ($a, $b) => $a['time'] <=> $b['time']);

        return [
            'value' => $option['start']->format('Y-m-d\TH:i'),
            'label' => $option['start']->format('D M j, g:i A'),
            'worker_id' => $worker['id'],
            'worker' => $worker['name'],
            'worker_color' => $worker['calendar_color'],
            'drive_in_minutes' => $option['drive_in'],
            'drive_out_minutes' => $option['drive_out'],
            'extra_drive_minutes' => $option['extra'],
            'late' => $option['late'],
            'day_schedule' => $schedule,
        ];
    }

    // =========================================================================
    // Geography
    // =========================================================================

    /** Straight-line miles from the service-area centre (slot_finder.service_area). */
    public function milesFromServiceCentre(float $lat, float $lon): float
    {
        $area = config('slot_finder.service_area');

        return $this->milesBetween([$area['center_lat'], $area['center_lon']], [$lat, $lon]);
    }

    private function milesBetween(array $a, array $b): float
    {
        $phi1 = deg2rad($a[0]);
        $phi2 = deg2rad($b[0]);
        $dPhi = deg2rad($b[0] - $a[0]);
        $dLambda = deg2rad($b[1] - $a[1]);
        $h = sin($dPhi / 2) ** 2 + cos($phi1) * cos($phi2) * sin($dLambda / 2) ** 2;

        return 2 * 3958.8 * asin(sqrt($h));
    }

    /** Road drive minutes from OSRM when loaded for this request, else the straight-line estimate. */
    private function driveMinutes(array $from, array $to): int
    {
        $road = $this->roadMinutes[$this->pointKey($from).'|'.$this->pointKey($to)] ?? null;

        if ($road !== null) {
            return $road;
        }

        $roadMiles = $this->milesBetween($from, $to) * config('slot_finder.road_distance_factor');

        return (int) round($roadMiles / config('slot_finder.average_speed_mph') * 60, 0, PHP_ROUND_HALF_EVEN);
    }

    /**
     * Road drive times between the customer, the workers' start addresses and
     * the booked jobs: one OSRM table request (cached a day). No traffic
     * factor: only the order of the options matters here. Too many points or
     * OSRM unreachable: the estimate is used.
     */
    private function loadRoadTimes(array $point, array $workers, array $bookings): void
    {
        $this->roadMinutes = [];

        if (! config('slot_finder.ranking.road_times')) {
            return;
        }

        $points = [$this->pointKey($point) => $point];

        foreach ($workers as $worker) {
            $points[$this->pointKey([$worker['home_lat'], $worker['home_lon']])] = [$worker['home_lat'], $worker['home_lon']];
        }

        foreach ($bookings as $booking) {
            if ($booking['lat'] !== null) {
                $points[$this->pointKey([$booking['lat'], $booking['lon']])] = [$booking['lat'], $booking['lon']];
            }
        }

        if (count($points) > config('slot_finder.ranking.max_table_points')) {
            return;
        }

        $coordinates = implode(';', array_map(fn (array $p) => sprintf('%.6f,%.6f', $p[1], $p[0]), $points));
        $cacheKey = 'slot-finder.osrm-table.'.md5($coordinates);
        $durations = Cache::get($cacheKey);

        if (! is_array($durations)) {
            try {
                $response = Http::timeout(15)->get(
                    rtrim(config('slot_finder.routing.osrm_url'), '/')."/table/v1/driving/{$coordinates}",
                    ['annotations' => 'duration']
                );
                $durations = $response->successful() && $response->json('code') === 'Ok' ? $response->json('durations') : null;
            } catch (ConnectionException) {
                $durations = null;
            }

            if (! is_array($durations)) {
                return;
            }

            Cache::put($cacheKey, $durations, now()->addDay());
        }

        $keys = array_keys($points);

        foreach ($keys as $i => $from) {
            foreach ($keys as $j => $to) {
                if (isset($durations[$i][$j])) {
                    $this->roadMinutes["{$from}|{$to}"] = (int) round($durations[$i][$j] / 60);
                }
            }
        }
    }

    private function pointKey(array $point): string
    {
        return sprintf('%.5f,%.5f', $point[0], $point[1]);
    }

    /** Street address if usable, else the ZIP centre. */
    private function locate(?string $street, ?string $postalCode): array
    {
        if ($street) {
            $result = $this->geocode($street);

            if ($result && in_array($result['accuracy_type'], self::ADDRESS_ACCURACY, true)) {
                return ['lat' => $result['lat'], 'lon' => $result['lon'], 'precision' => 'address'];
            }
        }

        if ($postalCode) {
            $result = $this->geocodioRequest(['postal_code' => $postalCode, 'country' => 'US']);

            if ($result) {
                return ['lat' => $result['lat'], 'lon' => $result['lon'], 'precision' => 'zip'];
            }
        }

        return ['lat' => null, 'lon' => null, 'precision' => null];
    }

    private function geocode(string $address): ?array
    {
        $withoutUnit = preg_replace('/\b(?:suite|ste|unit|apt|apartment)\s*#?\s*[\w-]+/i', '', $address);
        $withoutUnit = trim(preg_replace('/\s*,\s*/', ', ', $withoutUnit), ' ,');

        foreach (array_unique([$address, $withoutUnit]) as $query) {
            if ($query !== '' && ($result = $this->geocodioRequest(['q' => $query]))) {
                return $result;
            }
        }

        return null;
    }

    /**
     * Locate the worker's day-start address using the same address-quality
     * checks and cached Geocodio requests used for customer addresses.
     *
     * @return array{lat: float, lon: float}
     */
    public function locateWorkerAddress(string $address): array
    {
        $result = $this->geocode(trim($address));

        if (! $result || ! in_array($result['accuracy_type'], self::ADDRESS_ACCURACY, true)) {
            throw new SlotSuggestionException('Geocodio could not locate that address precisely. Check the address and try again.');
        }

        return ['lat' => $result['lat'], 'lon' => $result['lon']];
    }

    /**
     * The contact's address as stored in Krayin, and where it is: the street
     * address if usable, else the ZIP centre. Used for suggestions and as the
     * visit location of Cal.com bookings.
     *
     * @return array{address: ?string, lat: ?float, lon: ?float, precision: ?string}
     */
    public function locatePerson(Person $person): array
    {
        $stored = $this->storedAddress($person);

        $location = $this->locate(
            $stored['has_street'] ? $stored['address'] : null,
            $stored['postal_code']
        );

        return ['address' => $location['precision'] ? $stored['address'] : null] + $location;
    }

    /**
     * The contact's stored address as one line ("123 Main St, Katy, TX 77494",
     * or "Katy, TX 77494" without a usable street). has_street: a worker can
     * drive to it ("319" or a bare street name doesn't count). No lookup.
     *
     * @return array{address: ?string, has_street: bool, postal_code: ?string}
     */
    public function storedAddress(Person $person): array
    {
        $fields = $this->personAddress($person);
        $hasStreet = $this->hasStreetAddress($fields['csv_address']);
        $postalCode = $this->lastPostalCode((string) $fields['csv_postal_code']);
        $address = collect([
            $hasStreet ? $fields['csv_address'] : null,
            $fields['csv_city'],
            ($fields['csv_city'] || $postalCode) ? trim(($fields['csv_state_province'] ?: 'TX').' '.$postalCode) : null,
        ])->filter()->implode(', ');

        return ['address' => $address ?: null, 'has_street' => $hasStreet, 'postal_code' => $postalCode];
    }

    /**
     * Locate a free-text visit address (e.g. an activity location): the street
     * address if Geocodio finds it precisely, else its ZIP centre.
     *
     * @return array{lat: ?float, lon: ?float, precision: ?string}
     */
    public function locateAddress(string $address): array
    {
        $address = trim($address);

        return $this->locate($this->hasStreetAddress($address) ? $address : null, $this->lastPostalCode($address));
    }

    /** Geocodio lookup, cached forever (a miss is cached too). */
    private function geocodioRequest(array $params): ?array
    {
        $key = 'slot-finder.geocode.'.md5(strtolower(json_encode($params)));

        if (Cache::has($key)) {
            return Cache::get($key) ?: null;
        }

        $apiKey = config('slot_finder.geocodio_api_key');

        if (! filled($apiKey)) {
            throw new SlotSuggestionException('Set GEOCODIO_API_KEY in the Krayin environment to locate addresses.');
        }

        try {
            $response = Http::timeout(10)->get('https://api.geocod.io/v1.7/geocode', $params + ['api_key' => $apiKey]);
        } catch (ConnectionException) {
            throw new SlotSuggestionException('Could not reach Geocodio to locate the address. Try again.');
        }

        if ($response->status() !== 422 && ! $response->successful()) {
            throw new SlotSuggestionException("Geocodio returned HTTP {$response->status()}. Check the API key.");
        }

        $first = $response->json('results.0');
        $result = $response->successful() && $first ? [
            'lat' => (float) $first['location']['lat'],
            'lon' => (float) $first['location']['lng'],
            'accuracy_type' => $first['accuracy_type'] ?? null,
        ] : false;
        Cache::forever($key, $result);

        return $result ?: null;
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function personAddress(Person $person): array
    {
        $fields = array_fill_keys(['csv_address', 'csv_city', 'csv_state_province', 'csv_postal_code'], null);

        foreach (PersonGridColumnPreferences::customAttributes() as $attribute) {
            if (array_key_exists($attribute->code, $fields)) {
                $value = $person->getCustomAttributeValue($attribute);
                $fields[$attribute->code] = is_string($value) && trim($value) !== '' ? trim($value) : null;
            }
        }

        return $fields;
    }

    /** True for "123 Main St" or "75051 150 Eagle Road"; false for "319", "Fm 1484" or a bare street name. */
    private function hasStreetAddress(?string $address): bool
    {
        return (bool) ($address && preg_match('/^\d+[A-Za-z]?\s.*[A-Za-z]/', $address));
    }

    private function lastPostalCode(string $text): ?string
    {
        return preg_match_all('/\b(\d{5})\b/', $text, $matches) ? end($matches[1]) : null;
    }

    private function at(CarbonImmutable $day, string $time): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d H:i', $day->toDateString().' '.$time, $this->timezone());
    }

    private function timezone(): string
    {
        return config('slot_finder.timezone');
    }
}
