<?php

/*
 * Settings for suggesting appointment times ("Suggest times" on the booking
 * link page). Same rules as the Python prototype in TXN/Routing/config.py.
 *
 * Weekdays are numbers: 1 = Monday ... 7 = Sunday (ISO). Times are "HH:MM".
 * After changing this file in the container: php artisan config:clear
 */

return [

    'timezone' => 'America/Chicago',

    // ------------------------------------------------------------------
    // Appointment times
    // ------------------------------------------------------------------

    // The only start times.
    'slot_times' => ['09:00', '11:00', '13:00', '15:00', '17:00'],

    'visit_minutes' => 60,

    // Work hours for workers without their own. A slot is used only if the
    // visit fits inside them. To be confirmed with the client.
    'default_work_hours' => [
        1 => ['09:00', '18:00'],
        2 => ['09:00', '18:00'],
        3 => ['09:00', '18:00'],
        4 => ['09:00', '18:00'],
        5 => ['09:00', '18:00'],
        6 => ['09:00', '18:00'],
        7 => ['09:00', '18:00'],
    ],

    // A slot must start at least this long after "now".
    'min_notice_minutes' => 90,

    // How many days ahead (today included) to look for slots.
    'search_days' => 7,

    // ------------------------------------------------------------------
    // What to offer
    // ------------------------------------------------------------------

    'slots_to_offer' => 3,

    // At most this many of the offered times on the same day.
    'max_slots_per_day' => 2,

    // ------------------------------------------------------------------
    // Worker choice and route fit
    // ------------------------------------------------------------------
    // Every worker's free times are scored by the extra driving they add to
    // that worker's day. All offered times come from one worker: the one
    // whose best time adds the least driving (already working in the area,
    // or passing by the customer); about equal -> fewer jobs, then priority.

    // Times adding more extra driving than this are offered only when the
    // chosen worker has no cheaper time in the search window.
    'max_extra_drive_minutes' => 45,

    // Rank with road drive times: one OSRM table request per suggestion
    // (cached a day). Off, or more points than max_table_points (the public
    // OSRM server allows 100), or OSRM unreachable: the estimate below.
    'ranking' => [
        'road_times' => true,
        'max_table_points' => 100,
    ],

    // Shown on the booking page only: a booked job within this many minutes
    // of the customer is marked "near".
    'near_job_minutes' => 30,

    // Extra driving within the same band of this many minutes counts as
    // equal, so a sooner time wins over a later one that saves a few minutes.
    'route_fit_tolerance_minutes' => 10,

    // Drive estimate (when road times aren't available): straight-line
    // miles x road factor, at an average speed.
    'road_distance_factor' => 1.3,
    'average_speed_mph' => 35,

    // ------------------------------------------------------------------
    // Service area (straight-line radius)
    // ------------------------------------------------------------------

    'service_area' => [
        'center_lat' => 29.7604,   // Houston
        'center_lon' => -95.3698,
        'miles' => 120,
    ],

    // ------------------------------------------------------------------
    // Workers (sample data)
    // ------------------------------------------------------------------
    //   id          also used in the bookings file "person_id"
    //   home_lat/lon  where the day starts and ends (office for now)
    //   priority    1 = preferred; only breaks ties
    //   work_hours  [weekday => [start, end]]; empty = default_work_hours;
    //               a weekday missing from a non-empty list = day off

    'workers' => [
        [
            'id' => 1,
            'name' => 'Worker 1',
            'home_lat' => 30.157301,   // office, 2001 Timberloch Pl, The Woodlands
            'home_lon' => -95.467582,
            'priority' => 1,
            'active' => true,
            'work_hours' => [],
        ],
        [
            'id' => 2,
            'name' => 'Worker 2',
            'home_lat' => 30.157301,
            'home_lon' => -95.467582,
            'priority' => 2,
            'active' => true,
            'work_hours' => [],
        ],
    ],

    // ------------------------------------------------------------------
    // Existing jobs
    // ------------------------------------------------------------------
    // Read from Krayin activities that have a worker_id and are not done.
    // No longer used (the old demo JSON file):

    'bookings_file' => storage_path('app/slot-finder/demo_bookings.json'),

    // Geocodio key for turning addresses into coordinates.
    'geocodio_api_key' => env('GEOCODIO_API_KEY'),

    // ------------------------------------------------------------------
    // Worker routes (Contacts > Workers > Routes)
    // ------------------------------------------------------------------
    // Road routes and drive times come from OSRM (no live traffic). The
    // public server is for testing; point OSRM_URL at your own for production.
    // If OSRM can't be reached, straight lines and the estimate above are shown.

    'routing' => [
        'osrm_url' => env('OSRM_URL', 'https://router.project-osrm.org'),

        // OSRM drive times are multiplied by this outside rush hours...
        'base_traffic_factor' => 1.15,

        // ...and by these on weekdays, by departure time: [start, end, factor].
        'traffic_factors' => [
            ['07:00', '09:30', 1.5],
            ['16:00', '19:00', 1.5],
        ],

        // Draw the drive back to the start address after the last visit.
        'return_to_start' => true,

        // Saved road routes are reused for this many days.
        'cache_days' => 30,
    ],
];
