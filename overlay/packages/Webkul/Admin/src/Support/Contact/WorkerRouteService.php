<?php

namespace Webkul\Admin\Support\Contact;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Webkul\Admin\Models\Worker;

/**
 * A worker's day as a route: the start address, the day's customer visits
 * and internal work stops in time order, then back to the start. Booked times
 * are never reordered.
 *
 * Road geometry and drive times come from OSRM (one request per route,
 * cached), scaled by the rush-hour factors in config('slot_finder.routing').
 * If OSRM can't be reached, straight lines and the slot finder's drive
 * estimate are used instead and the route is marked "estimated".
 */
class WorkerRouteService
{
    /** Route colours for workers without a calendar colour (same as routes.py). */
    private const COLORS = ['#1769aa', '#d1495b', '#2a9d8f', '#f4a261', '#7b2cbf'];

    private const METERS_PER_MILE = 1609.344;

    public function __construct(private SlotSuggestionService $locator) {}

    /**
     * @param  Collection<int, Worker>  $workers
     */
    public function dayRoutes(Collection $workers, CarbonImmutable $day): array
    {
        return $workers->values()
            ->map(fn (Worker $worker, int $index) => $this->dayRoute($worker, $day, $index))
            ->all();
    }

    public function dayRoute(Worker $worker, CarbonImmutable $day, int $index = 0): array
    {
        [$stops, $offMap] = $this->stopsFor($worker, $day);

        $start = $worker->home_latitude !== null && $worker->home_longitude !== null
            ? [$worker->home_latitude, $worker->home_longitude]
            : null;
        $returns = $start && $stops && config('slot_finder.routing.return_to_start');

        $points = array_map(fn (array $stop) => [$stop['lat'], $stop['lon']], $stops);

        if ($start && $stops) {
            array_unshift($points, $start);
        }

        if ($returns) {
            $points[] = $start;
        }

        $road = count($points) > 1 ? $this->road($points) : ['estimated' => false, 'legs' => [], 'geometry' => []];
        $legs = $road['legs'];

        // Leave in time for the first visit; after that, leave when the previous one ends.
        foreach ($stops as $i => &$stop) {
            $stop['leg'] = null;
            $stop['late_minutes'] = 0;

            if ($i === 0 && ! $start) {
                continue;
            }

            [$seconds, $meters] = array_shift($legs);
            $depart = $i === 0 ? null : $stops[$i - 1]['end'];
            $minutes = $this->legMinutes($seconds, $depart ?? $stop['start']->subSeconds((int) $seconds), $road['estimated']);
            $depart ??= $stop['start']->subMinutes($minutes);
            $arrive = $depart->addMinutes($minutes);

            $stop['leg'] = $this->leg($minutes, $meters, $depart, $arrive);
            $stop['late_minutes'] = max(0, intdiv($arrive->getTimestamp() - $stop['start']->getTimestamp(), 60));
        }
        unset($stop);

        $back = null;

        if ($returns) {
            [$seconds, $meters] = array_shift($legs);
            $depart = end($stops)['end'];
            $minutes = $this->legMinutes($seconds, $depart, $road['estimated']);
            $back = $this->leg($minutes, $meters, $depart, $depart->addMinutes($minutes));
        }

        $allLegs = array_filter([...array_column($stops, 'leg'), $back]);

        return [
            'worker' => $worker,
            'color' => $worker->calendar_color ?: self::COLORS[$index % count(self::COLORS)],
            'start' => $start,
            'stops' => $stops,
            'back' => $back,
            'off_map' => $offMap,
            'estimated' => $road['estimated'],
            'geometry' => $road['geometry'],
            'drive_minutes' => array_sum(array_column($allLegs, 'minutes')),
            'miles' => round(array_sum(array_column($allLegs, 'miles')), 1),
        ];
    }

    /**
     * The worker's visits that day, in time order. Cancelled visits are left
     * out; visits without a location (e.g. busy blocks) are listed separately.
     *
     * @return array{0: array, 1: array}
     */
    private function stopsFor(Worker $worker, CarbonImmutable $day): array
    {
        $timezone = config('slot_finder.timezone');
        $wall = fn ($value) => CarbonImmutable::createFromFormat('Y-m-d H:i:s', $value->format('Y-m-d H:i:s'), $timezone);

        $activities = $worker->activitiesBetween($day, $day->addDay())
            ->orderBy('schedule_from')
            ->get();

        $customers = DB::table('person_activities')
            ->join('persons', 'persons.id', '=', 'person_activities.person_id')
            ->whereIn('person_activities.activity_id', $activities->pluck('id'))
            ->orderBy('persons.name')
            ->get(['person_activities.activity_id', 'persons.name'])
            ->groupBy('activity_id');

        $stops = [];
        $offMap = [];

        foreach ($activities as $activity) {
            $additional = is_array($activity->additional) ? $activity->additional : json_decode($activity->additional ?? '', true);

            if (
                data_get($additional, 'txn.kind') === 'worker_task'
                && data_get($additional, 'txn.worker_task_type', 'work') !== 'work'
            ) {
                continue;
            }

            if ($activity->is_done && str_starts_with($activity->title ?? '', 'Cancelled')) {
                continue;
            }

            $start = $wall($activity->schedule_from);
            $end = $activity->schedule_to ? $wall($activity->schedule_to) : null;
            $names =($customers[$activity->id] ?? collect())->pluck('name')->implode(', ');

            $entry = [
                'activity_id' => $activity->id,
                'customer' => $names ?: ($activity->title ?: 'Busy'),
                'title' => $activity->title,
                'address' => $activity->location,
                'start' => $start,
                'end' => $end && $end->gt($start) ? $end : $start->addHour(),
                'done' => (bool) $activity->is_done,
            ];

            if ($point = $this->pointOf($activity)) {
                $stops[] = $entry + ['lat' => $point[0], 'lon' => $point[1]];
            } else {
                $offMap[] = $entry + ['reason' => $activity->location ? 'address not found' : 'no address'];
            }
        }

        return [$stops, $offMap];
    }

    /** Saved coordinates, or the location geocoded once and saved on the activity. */
    private function pointOf($activity): ?array
    {
        if ($activity->latitude !== null && $activity->longitude !== null) {
            return [(float) $activity->latitude, (float) $activity->longitude];
        }

        if (! filled($activity->location)) {
            return null;
        }

        try {
            $found = $this->locator->locateAddress($activity->location);
        } catch (SlotSuggestionException) {
            return null;
        }

        if ($found['lat'] === null) {
            return null;
        }

        DB::table('activities')
            ->where('id', $activity->id)
            ->update(['latitude' => $found['lat'], 'longitude' => $found['lon']]);

        return [$found['lat'], $found['lon']];
    }

    /**
     * One OSRM route through all points: per leg [seconds, meters] and the
     * road line as [lon, lat] pairs. Falls back to straight lines.
     */
    private function road(array $points): array
    {
        $coordinates = implode(';', array_map(fn (array $point) => sprintf('%.6f,%.6f', $point[1], $point[0]), $points));
        $key = 'worker-routes.osrm.'.md5($coordinates);

        if (is_array($cached = Cache::get($key))) {
            return $cached;
        }

        try {
            $response = Http::timeout(15)->get(
                rtrim(config('slot_finder.routing.osrm_url'), '/')."/route/v1/driving/{$coordinates}",
                ['overview' => 'full', 'geometries' => 'geojson', 'steps' => 'false']
            );
            $route = $response->successful() && $response->json('code') === 'Ok' ? $response->json('routes.0') : null;
        } catch (ConnectionException) {
            $route = null;
        }

        if ($route && count($route['legs'] ?? []) === count($points) - 1) {
            $result = [
                'estimated' => false,
                'legs' => array_map(fn (array $leg) => [(float) $leg['duration'], (float) $leg['distance']], $route['legs']),
                'geometry' => $route['geometry']['coordinates'],
            ];
            Cache::put($key, $result, now()->addDays(config('slot_finder.routing.cache_days')));

            return $result;
        }

        // Not cached, so the next page load tries OSRM again.
        $legs = [];

        for ($i = 1; $i < count($points); $i++) {
            $roadMiles = $this->milesBetween($points[$i - 1], $points[$i]) * config('slot_finder.road_distance_factor');
            $legs[] = [$roadMiles / config('slot_finder.average_speed_mph') * 3600, $roadMiles * self::METERS_PER_MILE];
        }

        return [
            'estimated' => true,
            'legs' => $legs,
            'geometry' => array_map(fn (array $point) => [$point[1], $point[0]], $points),
        ];
    }

    private function leg(int $minutes, float $meters, CarbonImmutable $depart, CarbonImmutable $arrive): array
    {
        return [
            'minutes' => $minutes,
            'miles' => round($meters / self::METERS_PER_MILE, 1),
            'depart' => $depart,
            'arrive' => $arrive,
        ];
    }

    /** OSRM has no traffic: scale by the rush-hour factor at departure. Estimates aren't scaled. */
    private function legMinutes(float $seconds, CarbonImmutable $depart, bool $estimated): int
    {
        $factor = $estimated ? 1 : self::trafficFactor($depart);

        return (int) round($seconds / 60 * $factor);
    }

    /** Rush-hour factor for OSRM drive times by departure time (also used by the slot finder). */
    public static function trafficFactor(CarbonImmutable $depart): float
    {
        if ($depart->isWeekday()) {
            $time = $depart->format('H:i');

            foreach (config('slot_finder.routing.traffic_factors') as [$from, $to, $factor]) {
                if ($time >= $from && $time < $to) {
                    return $factor;
                }
            }
        }

        return config('slot_finder.routing.base_traffic_factor');
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
}
