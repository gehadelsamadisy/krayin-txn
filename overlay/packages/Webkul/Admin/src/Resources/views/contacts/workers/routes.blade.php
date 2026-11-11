<x-admin::layouts>
    <x-slot:title>
        Worker routes
    </x-slot>

    @php
        $dayUrl = fn ($date) => route('admin.contacts.workers.routes', array_filter(['date' => $date->toDateString(), 'worker' => $selected]));
        $time = fn ($value) => $value->format($value->minute ? 'g:i A' : 'g A');
        $duration = fn (int $minutes) => $minutes >= 60
            ? intdiv($minutes, 60).' h'.($minutes % 60 ? ' '.($minutes % 60).' min' : '')
            : $minutes.' min';
        $isToday = $day->isSameDay($today);

        $visitCount = array_sum(array_map(fn ($route) => count($route['stops']) + count($route['off_map']), $routes));
        $driveTotal = array_sum(array_column($routes, 'drive_minutes'));
        $milesTotal = round(array_sum(array_column($routes, 'miles')), 1);
        $estimated = array_filter($routes, fn ($route) => $route['estimated']);

        // Map data: one line per worker, a start marker per start address, a marker per stop.
        $markers = [];
        $starts = [];

        foreach ($routes as $route) {
            if ($route['start'] && $route['stops']) {
                $key = implode(',', $route['start']);
                $starts[$key] ??= [
                    'id' => 'start-'.count($starts),
                    'start' => true,
                    'lat' => $route['start'][0],
                    'lon' => $route['start'][1],
                    'title' => 'Start',
                    'lines' => [],
                ];
                $starts[$key]['lines'][] = $route['worker']->name.': '.$route['worker']->start_address;
            }

            foreach ($route['stops'] as $number => $stop) {
                $markers[] = [
                    'id' => 'w'.$route['worker']->id.'-'.($number + 1),
                    'label' => (string) ($number + 1),
                    'color' => $route['color'],
                    'done' => $stop['done'],
                    'lat' => $stop['lat'],
                    'lon' => $stop['lon'],
                    'title' => $route['worker']->name.', stop '.($number + 1).': '.$stop['customer'],
                    'lines' => array_values(array_filter([
                        $time($stop['start']).' – '.$time($stop['end']).($stop['done'] ? ' (done)' : ''),
                        $stop['address'],
                        $stop['late_minutes'] ? 'Arrives about '.$stop['late_minutes'].' min late' : null,
                    ])),
                ];
            }
        }

        $mapData = [
            'markers' => [...array_values($starts), ...$markers],
            'routes' => array_values(array_map(fn ($route) => [
                'id' => $route['worker']->id,
                'worker' => $route['worker']->name,
                'color' => $route['color'],
                'geometry' => $route['geometry'],
            ], array_filter($routes, fn ($route) => $route['stops']))),
        ];
    @endphp

    <div class="wr flex flex-col gap-4" v-pre>
        <div class="wr-panel wr-head">
            <div style="min-width: 0">
                <p class="wr-kicker">Worker routes</p>
                <h1 class="wr-title">{{ $day->format('l, F j, Y') }}</h1>
                <p class="wr-summary">
                    <strong>{{ $visitCount }}</strong> {{ \Illuminate\Support\Str::plural('visit', $visitCount) }}
                    · <strong>{{ $duration($driveTotal) }}</strong> driving
                    · <strong>{{ $milesTotal }}</strong> mi
                </p>
            </div>

            <div class="wr-actions">
                <nav class="wr-nav" aria-label="Change day">
                    <a href="{{ $dayUrl($day->subDay()) }}" aria-label="Previous day">&lsaquo;</a>
                    <a href="{{ $dayUrl($today) }}" @if ($isToday) aria-current="date" @endif>Today</a>
                    <a href="{{ $dayUrl($day->addDay()) }}" aria-label="Next day">&rsaquo;</a>
                </nav>

                <form method="GET" action="{{ route('admin.contacts.workers.routes') }}" class="wr-jump">
                    @if ($selected)
                        <input type="hidden" name="worker" value="{{ $selected }}">
                    @endif
                    <input id="wr-date" type="date" name="date" value="{{ $day->toDateString() }}" tabindex="-1" aria-hidden="true">
                    <button type="button" id="wr-jump" class="secondary-button">Jump to date</button>
                </form>

                <label for="wr-worker" class="sr-only">Worker</label>
                <select id="wr-worker" class="wr-select">
                    <option value="{{ route('admin.contacts.workers.routes', ['date' => $day->toDateString()]) }}" @selected(! $selected)>All active workers</option>
                    @foreach ($workers as $option)
                        <option value="{{ route('admin.contacts.workers.routes', ['date' => $day->toDateString(), 'worker' => $option->id]) }}" @selected($option->id === $selected)>
                            {{ $option->name }}{{ $option->is_active ? '' : ' (inactive)' }}
                        </option>
                    @endforeach
                </select>

                <a href="{{ route('admin.contacts.workers.index') }}" class="secondary-button">All workers</a>
            </div>
        </div>

        @if ($estimated)
            <div class="wr-notice" role="status">
                The road map service could not be reached, so
                {{ collect($estimated)->pluck('worker.name')->implode(', ') }}
                {{ count($estimated) === 1 ? 'is' : 'are' }} shown with straight lines and estimated drive times. Reload to try again.
            </div>
        @endif

        <div class="wr-body">
            <div class="wr-list">
                @forelse ($routes as $route)
                    @php
                        $worker = $route['worker'];
                        $late = count(array_filter($route['stops'], fn ($stop) => $stop['late_minutes'] > 0));
                    @endphp

                    <section class="wr-panel wr-card" style="--wr-color: {{ $route['color'] }}" aria-label="{{ $worker->name }} route">
                        <div class="wr-card-head">
                            <span class="wr-dot" style="background: {{ $route['color'] }}" aria-hidden="true"></span>
                            <button type="button" class="wr-card-name" data-wr-fit="{{ $worker->id }}" title="Show this route on the map">{{ $worker->name }}</button>
                            <a class="wr-card-link" href="{{ route('admin.contacts.workers.schedule', ['worker' => $worker->id, 'date' => $day->toDateString()]) }}">Week</a>
                        </div>

                        @if (! $route['stops'] && ! $route['off_map'])
                            <p class="wr-empty">No visits on this day.</p>
                        @else
                            @if ($route['stops'])
                                <div class="wr-stats">
                                    <span><strong>{{ count($route['stops']) }}</strong> {{ \Illuminate\Support\Str::plural('stop', count($route['stops'])) }}</span>
                                    <span><strong>{{ $duration($route['drive_minutes']) }}</strong> driving</span>
                                    <span><strong>{{ $route['miles'] }}</strong> mi</span>
                                    @if ($route['stops'][0]['leg'])
                                        <span>Leave by <strong>{{ $route['stops'][0]['leg']['depart']->format('g:i A') }}</strong></span>
                                    @endif
                                    @if ($late)
                                        <span style="color: var(--wr-late)"><strong style="color: inherit">{{ $late }}</strong> late</span>
                                    @endif
                                </div>
                            @endif

                            <ol class="wr-steps">
                                @if ($route['stops'] && $route['start'])
                                    <li class="wr-step">
                                        <span class="wr-pin is-start" aria-hidden="true">S</span>
                                        <div class="wr-stop" style="cursor: default">
                                            <div class="wr-stop-time">Start</div>
                                            <div class="wr-stop-addr">{{ $worker->start_address }}</div>
                                        </div>
                                    </li>
                                @elseif ($route['stops'])
                                    <li class="wr-empty" style="padding: 0 0 6px">No start address saved for this worker, so the route begins at the first visit.</li>
                                @endif

                                @foreach ($route['stops'] as $number => $stop)
                                    @if ($stop['leg'])
                                        <li class="wr-drive" aria-label="Drive">
                                            <span>{{ $duration($stop['leg']['minutes']) }} · {{ $stop['leg']['miles'] }} mi</span>
                                        </li>
                                    @endif

                                    <li @class(['wr-step', 'is-done' => $stop['done']])>
                                        <span class="wr-pin" aria-hidden="true">{{ $number + 1 }}</span>
                                        <button type="button" class="wr-stop" data-wr-stop="w{{ $worker->id }}-{{ $number + 1 }}" title="Show on the map">
                                            <div class="wr-stop-time">
                                                {{ $time($stop['start']) }} – {{ $time($stop['end']) }}
                                                @if ($stop['done'])
                                                    <span class="wr-badge is-done">Done</span>
                                                @endif
                                                @if ($stop['late_minutes'])
                                                    <span class="wr-badge is-late" title="Arrives about {{ $stop['leg']['arrive']->format('g:i A') }}">{{ $stop['late_minutes'] }} min late</span>
                                                @endif
                                            </div>
                                            <div class="wr-stop-name">{{ $stop['customer'] }}</div>
                                            <div class="wr-stop-addr">{{ $stop['address'] }}</div>
                                        </button>
                                    </li>
                                @endforeach

                                @if ($route['back'])
                                    <li class="wr-drive" aria-label="Drive">
                                        <span>{{ $duration($route['back']['minutes']) }} · {{ $route['back']['miles'] }} mi</span>
                                    </li>
                                    <li class="wr-step">
                                        <span class="wr-pin is-start" aria-hidden="true">S</span>
                                        <div class="wr-stop" style="cursor: default">
                                            <div class="wr-stop-time">Back about {{ $route['back']['arrive']->format('g:i A') }}</div>
                                        </div>
                                    </li>
                                @endif
                            </ol>

                            @if ($route['off_map'])
                                <div class="wr-offmap">
                                    Not on the map:
                                    @foreach ($route['off_map'] as $entry)
                                        <div>
                                            <a href="{{ route('admin.activities.edit', $entry['activity_id']) }}" class="wr-card-link">{{ $time($entry['start']) }} – {{ $time($entry['end']) }}</a>
                                            {{ $entry['customer'] }} ({{ $entry['reason'] }})
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        @endif
                    </section>
                @empty
                    <div class="wr-panel wr-empty">No active workers. Register a worker or pick one from the list.</div>
                @endforelse
            </div>

            <div class="wr-panel wr-mapwrap">
                <div id="wr-map" role="region" aria-label="Route map"></div>
            </div>
        </div>
    </div>

    @pushOnce('scripts')
        <script type="application/json" id="wr-data">@json($mapData)</script>
        <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
        <script>
            (() => {
                const data = JSON.parse(document.getElementById('wr-data').textContent);
                const markers = {};
                let map = null;

                // Data coordinates are [lon, lat] (GeoJSON order, as OSRM returns them).
                const latLng = ([lon, lat]) => [lat, lon];

                const fit = (coordinates) => {
                    if (coordinates.length === 1) {
                        map.setView(latLng(coordinates[0]), 13);
                    } else if (coordinates.length > 1) {
                        map.fitBounds(coordinates.map(latLng), { padding: [40, 40], maxZoom: 14 });
                    }
                };

                const popupFor = (point) => {
                    const content = document.createElement('div');
                    content.className = 'wr-popup';
                    const title = document.createElement('strong');
                    title.textContent = point.title;
                    content.appendChild(title);

                    point.lines.forEach((line, index) => {
                        const row = document.createElement('div');
                        row.textContent = line;
                        if (index > 0) {
                            row.className = 'wr-popup-muted';
                        }
                        content.appendChild(row);
                    });

                    return content;
                };

                const iconFor = (point) => {
                    const pin = document.createElement('div');
                    pin.className = 'wr-marker' + (point.start ? ' is-start' : '') + (point.done ? ' is-done' : '');
                    pin.textContent = point.start ? 'S' : point.label;
                    if (! point.start) {
                        pin.style.backgroundColor = point.color;
                    }
                    const size = point.start ? 30 : 26;

                    return L.divIcon({ html: pin, className: 'wr-marker-icon', iconSize: [size, size], iconAnchor: [size / 2, size / 2], popupAnchor: [0, -size / 2] });
                };

                const init = () => {
                    const element = document.getElementById('wr-map');
                    if (! element || ! window.L) {
                        return;
                    }

                    map = L.map(element).setView([29.76, -95.37], 9);
                    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
                        maxZoom: 19,
                        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
                    }).addTo(map);

                    data.routes
                        .filter((route) => route.geometry.length > 1)
                        .forEach((route) => {
                            const path = route.geometry.map(latLng);
                            L.polyline(path, { color: '#ffffff', weight: 8, opacity: 0.9, interactive: false }).addTo(map);
                            L.polyline(path, { color: route.color, weight: 5, opacity: 0.9 })
                                .bindTooltip(route.worker, { sticky: true })
                                .addTo(map);
                        });

                    // Stops above start markers, so a visit at the office stays clickable.
                    data.markers.forEach((point) => {
                        markers[point.id] = L.marker([point.lat, point.lon], {
                            icon: iconFor(point),
                            zIndexOffset: point.start ? 0 : 1000,
                            title: point.title,
                        })
                            .bindPopup(popupFor(point))
                            .addTo(map);
                    });

                    if (data.routes.length) {
                        const legend = L.control({ position: 'bottomleft' });
                        legend.onAdd = () => {
                            const box = L.DomUtil.create('div', 'wr-legend');
                            data.routes.forEach((route) => {
                                const row = document.createElement('div');
                                const swatch = document.createElement('i');
                                swatch.style.backgroundColor = route.color;
                                row.appendChild(swatch);
                                row.appendChild(document.createTextNode(route.worker));
                                box.appendChild(row);
                            });
                            return box;
                        };
                        legend.addTo(map);
                    }

                    fit(data.markers.map((point) => [point.lon, point.lat]));
                };

                // Vue re-renders the page content when it mounts on "load", so the
                // map is created after that, and listeners are delegated.
                window.addEventListener('load', () => setTimeout(init, 0));

                document.addEventListener('click', (event) => {
                    if (event.target.closest('#wr-jump')) {
                        const input = document.getElementById('wr-date');
                        try {
                            input.showPicker();
                        } catch (error) {
                            input.focus();
                            input.click();
                        }
                        return;
                    }

                    if (! map) {
                        return;
                    }

                    const stop = event.target.closest('[data-wr-stop]');
                    if (stop && markers[stop.dataset.wrStop]) {
                        const marker = markers[stop.dataset.wrStop];
                        map.setView(marker.getLatLng(), Math.max(map.getZoom(), 13));
                        marker.openPopup();
                        document.getElementById('wr-map').scrollIntoView({ block: 'nearest', behavior: 'smooth' });
                        return;
                    }

                    const worker = event.target.closest('[data-wr-fit]');
                    if (worker) {
                        const route = data.routes.find((entry) => String(entry.id) === worker.dataset.wrFit);
                        if (route) {
                            fit(route.geometry);
                            document.getElementById('wr-map').scrollIntoView({ block: 'nearest', behavior: 'smooth' });
                        }
                    }
                });

                document.addEventListener('change', (event) => {
                    if (event.target.id === 'wr-worker') {
                        window.location = event.target.value;
                    }

                    if (event.target.id === 'wr-date' && event.target.value) {
                        event.target.form.submit();
                    }
                });
            })();
        </script>
    @endPushOnce
</x-admin::layouts>
