{{-- Settings > TXN Settings > Service area & scheduling (TxnSettingsController).
     Styles: partials/tc-styles (.tc, scoped). Use only PHP blocks (opening and
     closing directive) here, never the one-line form: Blade pairs a one-line
     form with the next closing directive and swallows the page. Don't write
     the directive names in comments either: Blade reads them before comments. --}}
<x-admin::layouts>
    <x-slot:title>{{ $title }}</x-slot>

    @php
        $area = $values['slot_finder.service_area'];
        $numbers = [
            'slots_to_offer' => ['times', 'How many times a customer is offered (the best ones, across all workers).'],
            'search_days' => ['days', 'How many days ahead to look for open times.'],
            'min_notice_minutes' => ['min', 'No time sooner than this from now.'],
            'visit_minutes' => ['min', 'How long a visit blocks a worker.'],
            'max_slots_per_day' => ['per day', 'At most this many of the offered times on the same day.'],
            'max_extra_drive_minutes' => ['min', 'Times that add more driving than this are offered only if nothing better is open.'],
        ];
        $currentTz = $values['slot_finder.timezone'];
        $advancedHelp = [
            'slot_finder.ranking.road_times' => [null, 'On: times are ranked with real road drive times from the routing server. Off: a straight-line estimate (road distance factor and average speed).'],
            'slot_finder.ranking.max_table_points' => ['points', 'If a suggestion needs more points than this, the estimate is used instead. The public OSRM server allows 100.'],
            'slot_finder.near_job_minutes' => ['min', 'On the booking page, a booked job this close to the customer is marked "near".'],
            'slot_finder.route_fit_tolerance_minutes' => ['min', 'Extra driving within this many minutes counts as equal, so a sooner time wins over a later one that saves a few minutes.'],
            'slot_finder.road_distance_factor' => ['×', 'Estimate only: straight-line miles are multiplied by this to get road miles.'],
            'slot_finder.average_speed_mph' => ['mph', 'Estimate only: road miles are driven at this speed.'],
            'slot_finder.routing.osrm_url' => [null, 'Where road routes and drive times come from. The public server (router.project-osrm.org) is for testing; use your own for production.'],
            'slot_finder.routing.base_traffic_factor' => ['×', 'Road drive times are multiplied by this outside the rush hours.'],
            'slot_finder.routing.traffic_factors' => [null, 'On weekdays, a drive starting inside one of these periods is multiplied by its factor instead.'],
            'slot_finder.routing.return_to_start' => [null, 'Workers > Routes: draw the drive back to the start address after the last visit.'],
            'slot_finder.routing.cache_days' => ['days', 'Road routes already looked up are reused for this many days.'],
        ];
        $advancedOpen = collect($advanced)->keys()->contains(fn ($key) => isset($saved[$key])) || $errors->any();
        $advancedChanged = collect($advanced)->keys()->filter(fn ($key) => isset($saved[$key]))->count();
    @endphp

    <div class="mx-auto flex w-full max-w-4xl flex-col gap-4">
        @include('admin::settings.txn.partials.top')

        <form method="POST" action="{{ route('admin.settings.txn.area.update') }}" class="tc tc-form" id="area-form">
            @csrf
            @method('PUT')

            {{-- Service area --}}
            <section class="tc-card is-plain">
                @include('admin::settings.txn.partials.card-head', [
                    'icon' => 'map',
                    'title' => 'Service area',
                    'text' => 'Each customer\'s distance from the centre is checked. Farther than the radius: Needs Review with the out-of-area message (never rejected).',
                    'aside' => '<span class="tc-pill" style="--tc-rail: var(--tc-ok)">'.e((int) $area['miles']).' mi around '.e(\Webkul\Admin\Support\Contact\TxnSettings::areaCentreName()).'</span>',
                ])
                <div class="tc-body">
                    <div class="tc-field">
                        <label for="f-area-address" class="tc-label">Centre: address, city or ZIP</label>
                        <input id="f-area-address" type="text" name="area_address" maxlength="200" value="{{ old('area_address', $area['address'] ?? '') }}" placeholder="e.g. Houston, TX" class="tc-input" @disabled(! $canEdit)>
                        <p class="tc-help">When you change it, it is found on the map once and the point below is filled in. Or leave it and type the point yourself.</p>
                    </div>

                    <div class="tc-grid-3">
                        <div class="tc-field">
                            <label for="f-area-lat" class="tc-label">Latitude</label>
                            <input id="f-area-lat" type="number" step="0.000001" name="area_lat" value="{{ old('area_lat', $area['center_lat']) }}" class="tc-input" @disabled(! $canEdit)>
                        </div>
                        <div class="tc-field">
                            <label for="f-area-lon" class="tc-label">Longitude</label>
                            <input id="f-area-lon" type="number" step="0.000001" name="area_lon" value="{{ old('area_lon', $area['center_lon']) }}" class="tc-input" @disabled(! $canEdit)>
                        </div>
                        <div class="tc-field">
                            <label for="f-area-miles" class="tc-label">Radius</label>
                            <div class="tc-unit">
                                <input id="f-area-miles" type="number" min="5" max="1000" name="area_miles" value="{{ old('area_miles', $area['miles']) }}" class="tc-input" @disabled(! $canEdit)>
                                <span>miles</span>
                            </div>
                        </div>
                    </div>
                    @include('admin::settings.txn.partials.field-meta', ['key' => 'slot_finder.service_area'])

                    <div class="tc-field">
                        <label for="f-ooa" class="tc-label">{{ $fields['lead_triage.out_of_area_message'][2] }}</label>
                        <textarea id="f-ooa" name="out_of_area_message" rows="4" maxlength="2000" class="tc-input" @disabled(! $canEdit)>{{ old('out_of_area_message', $values['lead_triage.out_of_area_message']) }}</textarea>
                        <p class="tc-help">Shown on out-of-area leads, ready to copy and send.</p>
                        @include('admin::settings.txn.partials.field-meta', ['key' => 'lead_triage.out_of_area_message'])
                    </div>
                </div>
            </section>

            {{-- Times offered --}}
            <section class="tc-card is-plain">
                @include('admin::settings.txn.partials.card-head', ['icon' => 'clock', 'title' => 'Times offered to customers', 'text' => 'Used by "Suggest times" and the booking link. Workers\' hours and company holidays are under Workers > Company schedule.'])
                <div class="tc-body">
                    <div class="tc-field">
                        <label for="f-slot-times" class="tc-label">{{ $fields['slot_finder.slot_times'][2] }}</label>
                        <input id="f-slot-times" type="text" name="slot_times" value="{{ old('slot_times', implode(', ', (array) $values['slot_finder.slot_times'])) }}" class="tc-input" @disabled(! $canEdit)>
                        <div class="tc-chips" id="slot-chips" aria-live="polite"></div>
                        <p class="tc-help">Start times on a 24-hour clock, separated by commas, e.g. 09:00, 11:00, 13:00, 15:00, 17:00.</p>
                        @include('admin::settings.txn.partials.field-meta', ['key' => 'slot_finder.slot_times'])
                    </div>

                    <div class="tc-grid">
                        @foreach ($numbers as $name => [$unit, $help])
                            @php $key = "slot_finder.{$name}"; @endphp
                            <div class="tc-field">
                                <label for="f-{{ $name }}" class="tc-label">{{ $fields[$key][2] }}</label>
                                <div class="tc-unit">
                                    <input id="f-{{ $name }}" type="number" name="{{ $name }}" min="{{ $fields[$key][3]['min'] }}" max="{{ $fields[$key][3]['max'] }}" value="{{ old($name, $values[$key]) }}" class="tc-input" @disabled(! $canEdit)>
                                    <span>{{ $unit }}</span>
                                </div>
                                <p class="tc-help">{{ $help }}</p>
                                @include('admin::settings.txn.partials.field-meta', ['key' => $key])
                            </div>
                        @endforeach
                    </div>
                </div>
            </section>

            {{-- Time zone --}}
            <section class="tc-card is-plain">
                @include('admin::settings.txn.partials.card-head', ['icon' => 'globe', 'title' => $fields['slot_finder.timezone'][2], 'text' => 'The times offered, the emails ("Times are in Central Time"), the calendars and the follow-ups use this zone.'])
                <div class="tc-body">
                    <div class="tc-field">
                        <label for="f-timezone" class="tc-label">Company time zone</label>
                        <select id="f-timezone" name="timezone" class="tc-input" style="max-width: 22rem;" @disabled(! $canEdit)
                            onchange="document.getElementById('tz-confirm').style.display = this.value === @js($currentTz) ? 'none' : ''">
                            @foreach ($timezones as $zone => $label)
                                <option value="{{ $zone }}" @selected(old('timezone', $currentTz) === $zone)>{{ $label }} ({{ $zone }})</option>
                            @endforeach
                            @unless (isset($timezones[$currentTz]))
                                <option value="{{ $currentTz }}" @selected(old('timezone', $currentTz) === $currentTz)>{{ $currentTz }}</option>
                            @endunless
                        </select>
                        <label id="tz-confirm" class="tc-warnbox" style="{{ old('timezone', $currentTz) === $currentTz ? 'display: none;' : '' }}">
                            <input type="checkbox" name="confirm_timezone" value="1">
                            <span><strong>Confirm the change.</strong> Appointments already in Krayin keep their clock times (a 9:00 AM visit stays 9:00 AM in the new zone). Only change this if the business really moved to another zone.</span>
                        </label>
                        @include('admin::settings.txn.partials.field-meta', ['key' => 'slot_finder.timezone'])
                    </div>
                </div>
            </section>

            {{-- Ranking & routes (advanced), folded --}}
            <details class="tc-card is-plain" @if ($advancedOpen) open @endif>
                <summary class="tc-head">
                    <span class="tc-mark">@include('admin::settings.txn.partials.icon', ['name' => 'route'])</span>
                    <div class="tc-title">
                        <h2>Ranking &amp; routes (advanced)</h2>
                        <p>How open times are ranked and how drive times are worked out. The defaults suit most cases.</p>
                    </div>
                    @if ($advancedChanged)
                        <span class="tc-badge tc-badge-saved">{{ $advancedChanged }} changed</span>
                    @endif
                    <span class="tc-chevron">@include('admin::settings.txn.partials.icon', ['name' => 'chevron'])</span>
                </summary>

                <div class="tc-body">
                    <div class="tc-grid">
                        @foreach ($advanced as $key => $name)
                            @php
                                $type = $fields[$key][1];
                                [$unit, $help] = $advancedHelp[$key] ?? [null, ''];
                            @endphp
                            <div class="tc-field {{ in_array($type, ['url', 'traffic'], true) ? 'tc-wide' : '' }}">
                                @if ($type === 'bool')
                                    <span class="tc-label">{{ $fields[$key][2] }}</span>
                                    <input type="hidden" name="{{ $name }}" value="0">
                                    <label class="tc-toggle is-plain">
                                        <input type="checkbox" name="{{ $name }}" value="1" @checked(old($name, $values[$key] ? '1' : '0') === '1') @disabled(! $canEdit)>
                                        <span class="tc-toggle-track"></span>
                                        <span>On</span>
                                    </label>
                                @elseif ($type === 'traffic')
                                    @php
                                        // Saved rows, or what was typed after an error; at least one row to fill in.
                                        $rows = is_array(old($name))
                                            ? array_values(array_map(fn ($row) => [$row['from'] ?? '', $row['to'] ?? '', $row['factor'] ?? ''], array_filter(old($name), fn ($row) => empty($row['remove']))))
                                            : (array) $values[$key];
                                        $rows = $rows ?: [['', '', '']];
                                    @endphp
                                    <span class="tc-label">{{ $fields[$key][2] }}</span>
                                    <div class="tc-rows" id="rush-rows">
                                        @foreach ($rows as $i => [$from, $to, $factor])
                                            <div class="tc-rowline">
                                                <input type="time" name="{{ $name }}[{{ $i }}][from]" value="{{ $from }}" class="tc-input" aria-label="From" @disabled(! $canEdit)>
                                                <span class="tc-arrow">to</span>
                                                <input type="time" name="{{ $name }}[{{ $i }}][to]" value="{{ $to }}" class="tc-input" aria-label="To" @disabled(! $canEdit)>
                                                <div class="tc-unit" style="max-width: 130px;">
                                                    <input type="number" step="0.05" min="1" max="3" name="{{ $name }}[{{ $i }}][factor]" value="{{ $factor }}" class="tc-input" aria-label="Factor" @disabled(! $canEdit)>
                                                    <span>×</span>
                                                </div>
                                                <label class="tc-remove"><input type="checkbox" name="{{ $name }}[{{ $i }}][remove]" value="1" aria-label="Remove" @disabled(! $canEdit)> Remove</label>
                                            </div>
                                        @endforeach
                                    </div>
                                    @if ($canEdit)
                                        <button type="button" class="tc-add" id="add-rush">@include('admin::settings.txn.partials.icon', ['name' => 'plus', 'size' => 15]) Add period</button>
                                    @endif
                                @else
                                    <label for="f-{{ $name }}" class="tc-label">{{ $fields[$key][2] }}</label>
                                    @if ($type === 'url')
                                        <input id="f-{{ $name }}" type="url" name="{{ $name }}" maxlength="255" value="{{ old($name, $values[$key]) }}" class="tc-input" @disabled(! $canEdit)>
                                    @else
                                        <div class="tc-unit">
                                            <input id="f-{{ $name }}" type="number" name="{{ $name }}" step="{{ $type === 'decimal' ? '0.05' : '1' }}" min="{{ $fields[$key][3]['min'] }}" max="{{ $fields[$key][3]['max'] }}" value="{{ old($name, $values[$key]) }}" class="tc-input" @disabled(! $canEdit)>
                                            <span>{{ $unit }}</span>
                                        </div>
                                    @endif
                                @endif
                                <p class="tc-help">{{ $help }}</p>
                                @include('admin::settings.txn.partials.field-meta', ['key' => $key])
                            </div>
                        @endforeach
                    </div>
                </div>
            </details>

            @include('admin::settings.txn.partials.savebar')
        </form>

        @include('admin::settings.txn.partials.log')
    </div>

    @pushOnce('scripts')
        <script>
            (() => {
                // Slot times as chips ("9:00 AM"); a time that isn't valid shows red.
                const times = document.getElementById('f-slot-times');
                const chips = document.getElementById('slot-chips');
                const showTimes = () => {
                    const items = times.value.split(/[\s,;]+/).filter(Boolean).map((raw) => {
                        const match = raw.match(/^(\d{1,2}):([0-5]\d)$/);
                        const chip = document.createElement('span');

                        if (match && +match[1] < 24) {
                            const h = +match[1];
                            chip.className = 'tc-chip';
                            chip.textContent = `${(h % 12) || 12}:${match[2]} ${h < 12 ? 'AM' : 'PM'}`;
                            chip.dataset.sort = String(h * 60 + +match[2]);
                        } else {
                            chip.className = 'tc-chip is-bad';
                            chip.textContent = `${raw}?`;
                            chip.dataset.sort = '9999';
                        }

                        return chip;
                    }).sort((a, b) => a.dataset.sort - b.dataset.sort);

                    chips.replaceChildren(...items);
                };
                times?.addEventListener('input', showTimes);
                times && showTimes();

                // Rush hours: "Add period" copies a row, empty.
                const rows = document.getElementById('rush-rows');
                document.getElementById('add-rush')?.addEventListener('click', () => {
                    const row = rows.querySelector('.tc-rowline:last-child').cloneNode(true);
                    const index = Date.now();

                    row.querySelectorAll('input').forEach((field) => {
                        field.name = field.name.replace(/\[\d+\]\[(from|to|factor|remove)\]$/, `[${index}][$1]`);
                        field.type === 'checkbox' ? (field.checked = false) : (field.value = '');
                    });

                    rows.appendChild(row);
                    row.querySelector('input').focus();
                });
            })();
        </script>
    @endPushOnce
</x-admin::layouts>
