<x-admin::layouts>
    <x-slot:title>
        {{ $worker->name }} schedule
    </x-slot>

    @php
        $accent = $worker->calendar_color ?: '#2563EB';
        // White text on dark worker colors, dark text on light ones.
        [$red, $green, $blue] = array_map(fn ($part) => hexdec($part) / 255, str_split(ltrim($accent, '#'), 2));
        $onAccent = (0.2126 * $red + 0.7152 * $green + 0.0722 * $blue) > 0.55 ? '#111827' : '#ffffff';
        $weekEnd = $weekStart->addDays(6);
        $weekUrl = fn ($date) => route('admin.contacts.workers.schedule', ['worker' => $worker->id, 'date' => $date->toDateString()]);
        $timeOffTypes = ['annual_leave' => 'Annual leave', 'sick' => 'Sick', 'personal' => 'Personal', 'other' => 'Time off'];
        $canEditTimeOff = bouncer()->hasPermission('contacts.workers.edit');

        $totalMinutes = ($gridEnd - $gridStart) * 60;
        $minutesOf = fn (string $time) => (int) substr($time, 0, 2) * 60 + (int) substr($time, 3, 2);
        $pct = fn (int $minutes) => round(($minutes - $gridStart * 60) / $totalMinutes * 100, 3);
        $pctAt = fn ($time, $date) => $pct((int) abs($date->diffInMinutes($time)));
        $clock = fn ($time) => $time->format($time->minute ? 'g:i' : 'g');
        $clockOf = fn (string $time) => \Carbon\CarbonImmutable::createFromFormat('H:i', substr($time, 0, 5))->format('g:i A');

        $weekLabel = $weekStart->month === $weekEnd->month
            ? $weekStart->format('M j').' – '.$weekEnd->format('j, Y')
            : ($weekStart->year === $weekEnd->year
                ? $weekStart->format('M j').' – '.$weekEnd->format('M j, Y')
                : $weekStart->format('M j, Y').' – '.$weekEnd->format('M j, Y'));

        $visitCount = $days->sum('visits');
        $workTaskCount = $days->sum('workTasks');
        $otherBookingCount = $days->sum('otherBookings');
        $openCount = $days->sum(fn ($day) => count($day['openSlots']));
        $isThisWeek = $today->between($weekStart, $weekEnd->endOfDay());
    @endphp

    

    <div class="ws flex flex-col gap-4" style="--ws-accent: {{ $accent }}; --ws-on-accent: {{ $onAccent }}; --ws-grid-hours: {{ $gridEnd - $gridStart }};">
        <div class="ws-panel ws-head">
            <div class="ws-who">
                <span class="ws-swatch" aria-hidden="true">{{ mb_strtoupper(mb_substr($worker->name, 0, 1)) }}</span>
                <div style="min-width: 0">
                    <p class="ws-kicker">Worker schedule</p>
                    <h1 class="ws-name">
                        {{ $worker->name }}
                        @unless ($worker->is_active)
                            <span class="ws-badge">Inactive</span>
                        @endunless
                    </h1>
                    <div class="ws-contact">
                        @if ($worker->phone) <span>{{ $worker->phone }}</span> @endif
                        @if ($worker->email) <span>{{ $worker->email }}</span> @endif
                        <span>Starts from {{ $worker->start_address ?: 'an address not set yet' }}</span>
                    </div>
                </div>
            </div>

            <div class="ws-actions">
                <label for="ws-worker" class="sr-only">Show another worker</label>
                <select id="ws-worker" class="ws-select">
                    @foreach ($workers as $option)
                        <option value="{{ route('admin.contacts.workers.schedule', ['worker' => $option->id, 'date' => $weekStart->toDateString()]) }}" @selected($option->id === $worker->id)>{{ $option->name }}</option>
                    @endforeach
                </select>

                <a href="{{ route('admin.contacts.workers.routes', ['worker' => $worker->id, 'date' => ($isThisWeek ? $today : $weekStart)->toDateString()]) }}" class="secondary-button">Routes</a>

                @if (bouncer()->hasPermission('contacts.workers.edit'))
                    @if ($worker->is_active)
                        <a href="{{ route('admin.contacts.workers.bookings.create', ['worker' => $worker->id, 'date' => ($isThisWeek ? $today : $weekStart)->toDateString()]) }}" class="primary-button">Book worker</a>
                    @endif
                    <a href="{{ route('admin.contacts.workers.edit', $worker->id) }}" class="secondary-button">Edit worker</a>
                @endif

                <a href="{{ route('admin.contacts.workers.index') }}" class="secondary-button">All workers</a>

                @include('admin::contacts.calcom-sync-button')
            </div>
        </div>

        <div class="ws-panel">
            <div class="ws-toolbar">
                <nav class="ws-nav" aria-label="Change week">
                    <a href="{{ $weekUrl($weekStart->subWeek()) }}" aria-label="Previous week">&lsaquo;</a>
                    <a href="{{ $weekUrl($today) }}" @if ($isThisWeek) aria-current="date" @endif>Today</a>
                    <a href="{{ $weekUrl($weekStart->addWeek()) }}" aria-label="Next week">&rsaquo;</a>
                </nav>

                <h2 class="ws-range">{{ $weekLabel }}</h2>

                <form method="GET" action="{{ route('admin.contacts.workers.schedule', $worker->id) }}" class="ws-jump">
                    <input id="ws-date" type="date" name="date" value="{{ $weekStart->toDateString() }}" tabindex="-1" aria-hidden="true">
                    <button type="button" id="ws-jump" class="secondary-button">Jump to date</button>
                </form>

                <div class="ws-stats">
                    <span><strong>{{ $visitCount }}</strong> {{ \Illuminate\Support\Str::plural('visit', $visitCount) }}</span>
                    <span><strong>{{ $workTaskCount }}</strong> {{ \Illuminate\Support\Str::plural('work visit', $workTaskCount) }}</span>
                    <span><strong>{{ $otherBookingCount }}</strong> {{ \Illuminate\Support\Str::plural('other booking', $otherBookingCount) }}</span>
                    <span><strong>{{ $openCount }}</strong> open {{ \Illuminate\Support\Str::plural('time', $openCount) }}</span>
                    @if ($worker->max_visits_per_day)
                        <span>Up to <strong>{{ $worker->max_visits_per_day }}</strong> a day</span>
                    @endif
                </div>
            </div>

            <div class="ws-scroll">
                <div class="ws-calendar">
                    <div style="border-bottom: 1px solid var(--ws-line)"></div>

                    @foreach ($days as $day)
                        @php
                            $isToday = $day['date']->isSameDay($today);
                        @endphp
                        <div @class(['ws-dayhead', 'is-today' => $isToday]) title="{{ $day['hours'] ? 'Works '.$clockOf($day['hours'][0]).' – '.$clockOf($day['hours'][1]) : 'Day off' }}">
                            <div class="ws-daynum">{{ $day['date']->format('j') }}</div>
                            <div class="ws-dayname">{{ $day['date']->format('l') }}</div>
                            @if ($day['holiday'])
                                <div class="ws-daymeta is-off">{{ $day['holiday']['name'] ?? 'Company holiday' }}</div>
                            @elseif ($day['fullDayOff'])
                                <div class="ws-daymeta is-off">{{ $timeOffTypes[$day['fullDayOff']->type] ?? 'Time off' }}</div>
                            @elseif (! $day['hours'])
                                <div class="ws-daymeta">Day off</div>
                            @else
                                <div class="ws-daymeta">
                                    @if ($day['visits'] || $day['workTasks'] || $day['otherBookings'])
                                        @if ($day['visits']){{ $day['visits'] }} {{ \Illuminate\Support\Str::plural('visit', $day['visits']) }}@endif
                                        @if ($day['workTasks']){{ $day['visits'] ? ', ' : '' }}{{ $day['workTasks'] }} {{ \Illuminate\Support\Str::plural('work visit', $day['workTasks']) }}@endif
                                        @if ($day['otherBookings']){{ $day['visits'] || $day['workTasks'] ? ', ' : '' }}{{ $day['otherBookings'] }} {{ \Illuminate\Support\Str::plural('other booking', $day['otherBookings']) }}@endif
                                    @else
                                        No visits
                                    @endif
                                    @if (count($day['openSlots'])), {{ count($day['openSlots']) }} open @endif
                                </div>
                            @endif
                            @if ($day['visits'] || $day['workRouteStops'])
                                <a class="ws-dayroute" href="{{ route('admin.contacts.workers.routes', ['worker' => $worker->id, 'date' => $day['date']->toDateString()]) }}">Route &rsaquo;</a>
                            @endif
                        </div>
                    @endforeach

                    <div class="ws-gutter" aria-hidden="true">
                        @for ($hour = $gridStart; $hour < $gridEnd; $hour++)
                            <span style="top: {{ $pct($hour * 60) }}%">{{ \Carbon\CarbonImmutable::createFromTime($hour)->format('g A') }}</span>
                        @endfor
                    </div>

                    @foreach ($days as $day)
                        @php
                            $isToday = $day['date']->isSameDay($today);
                        @endphp
                        <div @class(['ws-col', 'is-today' => $isToday, 'is-past' => $day['date']->lt($today)]) aria-label="{{ $day['date']->format('l, F j') }}: {{ $day['visits'] }} {{ \Illuminate\Support\Str::plural('visit', $day['visits']) }}, {{ $day['workTasks'] }} {{ \Illuminate\Support\Str::plural('work visit', $day['workTasks']) }}, {{ $day['otherBookings'] }} {{ \Illuminate\Support\Str::plural('other booking', $day['otherBookings']) }}">
                            @if ($day['hours'])
                                @php
                                    $top = max(0, $pct($minutesOf($day['hours'][0])));
                                    $bottom = min(100, $pct($minutesOf($day['hours'][1])));
                                @endphp
                                <div class="ws-work" style="top: {{ $top }}%; height: {{ $bottom - $top }}%"></div>
                            @endif

                            @if ($day['holiday'])
                                <div class="ws-dayoff"><span>{{ $day['holiday']['name'] ?? 'Company holiday' }}</span></div>
                            @elseif ($day['fullDayOff'])
                                <div class="ws-dayoff">
                                    @if ($canEditTimeOff)
                                        <a href="{{ route('admin.contacts.workers.time_off.edit', [$worker->id, $day['fullDayOff']->id]) }}" title="Edit or delete this time off{{ $day['fullDayOff']->note ? "\n".$day['fullDayOff']->note : '' }}">{{ $timeOffTypes[$day['fullDayOff']->type] ?? 'Time off' }}</a>
                                    @else
                                        <span>{{ $timeOffTypes[$day['fullDayOff']->type] ?? 'Time off' }}</span>
                                    @endif
                                </div>
                            @endif

                            @foreach ($day['timeOffs'] as $entry)
                                @if ($entry->start_time && $entry->end_time)
                                    @php
                                        $top = max(0, $pct($minutesOf($entry->start_time)));
                                        $bottom = min(100, $pct($minutesOf($entry->end_time)));
                                    @endphp
                                    @if ($canEditTimeOff)
                                        <a href="{{ route('admin.contacts.workers.time_off.edit', [$worker->id, $entry->id]) }}" class="ws-timeoff" style="top: {{ $top }}%; height: {{ $bottom - $top }}%" title="Edit or delete this time off{{ $entry->note ? "\n".$entry->note : '' }}">
                                            <span>{{ $timeOffTypes[$entry->type] ?? 'Time off' }}</span>
                                        </a>
                                    @else
                                        <div class="ws-timeoff" style="top: {{ $top }}%; height: {{ $bottom - $top }}%" title="{{ $entry->note }}">
                                            <span>{{ $timeOffTypes[$entry->type] ?? 'Time off' }}</span>
                                        </div>
                                    @endif
                                @endif
                            @endforeach

                            @foreach ($day['openSlots'] as $slot)
                                @php
                                    $top = $pctAt($slot, $day['date']);
                                    $height = config('slot_finder.visit_minutes') / $totalMinutes * 100;
                                @endphp
                                <div class="ws-open" style="top: calc({{ $top }}% + 2px); height: calc({{ $height }}% - 4px)" title="Open at {{ $slot->format('g A') }}">Open {{ $slot->format('g A') }}</div>
                            @endforeach

                            @foreach ($day['events'] as $event)
                                @php
                                    $activity = $event['activity'];
                                    $top = max(0, $pctAt($event['start'], $day['date']));
                                    $bottom = min(100, $pctAt($event['end'], $day['date']));
                                    $width = 100 / $event['lanes'];
                                    $busy = ! $activity->location && $event['customers']->isEmpty();
                                    $names = $event['customers']->pluck('name')->implode(', ');
                                    $status = $event['cancelled'] ? 'Cancelled' : ($activity->is_done ? 'Done' : null);
                                @endphp
                                <a
                                    href="{{ route('admin.activities.edit', $activity->id) }}"
                                    @class([
                                        'ws-event',
                                        'is-busy' => $busy,
                                        'is-done' => $activity->is_done && ! $event['cancelled'],
                                        'is-cancelled' => $event['cancelled'],
                                        'is-task' => $event['worker_task'],
                                    ])
                                    style="top: calc({{ $top }}% + 1px); height: max(22px, calc({{ $bottom - $top }}% - 2px)); left: calc({{ $width * $event['lane'] }}% + 3px); width: calc({{ $width }}% - 6px)"
                                    title="{{ $event['real_start']->format('D g:i A') }} – {{ $event['real_end']->format('g:i A') }}: {{ $activity->title }}{{ $names ? "\n".$names : '' }}{{ $activity->location ? "\n".$activity->location : '' }}{{ $status ? "\n".$status : '' }}"
                                >
                                    <div class="ws-event-title">{{ $names ?: ($activity->title ?: 'Busy') }}</div>
                                    <div class="ws-event-time">{{ $clock($event['real_start']) }}–{{ $event['real_end']->format($event['real_end']->minute ? 'g:i A' : 'g A') }}@if ($status), {{ strtolower($status) }}@endif</div>
                                    @if ($event['worker_task'])
                                        <div class="ws-event-sub">{{ $event['worker_task_type'] === 'work' ? 'Work visit · Route stop' : 'Other booking' }}</div>
                                    @endif
                                    @if ($activity->location)
                                        <div class="ws-event-sub">{{ $activity->location }}</div>
                                    @endif
                                </a>
                            @endforeach

                            @if ($isToday && $now->hour >= $gridStart && $now->hour < $gridEnd)
                                <div class="ws-now" style="top: {{ $pct($now->hour * 60 + $now->minute) }}%" aria-hidden="true"></div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="ws-legend">
                <span><i class="ws-key-visit"></i>Booked visit</span>
                <span><i class="ws-key-task"></i>Internal work visit</span>
                <span><i class="ws-key-open"></i>Open time a customer can be offered</span>
                <span><i class="ws-key-hours"></i>Outside work hours</span>
                <span><i class="ws-key-off"></i>Holiday or time off</span>
                <span>{{ $usesDefaultHours ? 'Company default hours.' : 'Own weekly hours.' }} Times are {{ config('slot_finder.timezone') }}.</span>
            </div>
        </div>
    </div>

    @pushOnce('scripts')
        <script>
            // Outside #app and delegated: Vue re-renders the page content when it
            // mounts, which drops listeners bound to the original elements.
            document.addEventListener('click', (event) => {
                if (! event.target.closest('#ws-jump')) {
                    return;
                }

                const input = document.getElementById('ws-date');

                try {
                    input.showPicker();
                } catch (error) {
                    input.focus();
                    input.click();
                }
            });

            document.addEventListener('change', (event) => {
                if (event.target.id === 'ws-worker') {
                    window.location = event.target.value;
                }

                if (event.target.id === 'ws-date' && event.target.value) {
                    event.target.form.submit();
                }
            });
        </script>
    @endPushOnce
</x-admin::layouts>
