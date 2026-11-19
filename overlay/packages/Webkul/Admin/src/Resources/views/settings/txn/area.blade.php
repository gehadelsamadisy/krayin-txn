{{-- Settings > TXN Settings > Service area & scheduling (TxnSettingsController). --}}
<x-admin::layouts>
    <x-slot:title>{{ $title }}</x-slot>

    @php
        $input = 'w-full rounded border border-gray-300 px-2 py-1.5 dark:border-gray-700 dark:bg-gray-800 dark:text-white';
        $small = 'w-32 rounded border border-gray-300 px-2 py-1.5 dark:border-gray-700 dark:bg-gray-800 dark:text-white';
        $area = $values['slot_finder.service_area'];
        $numbers = [
            'slots_to_offer' => 'How many times a customer is offered (the best ones, across all workers).',
            'search_days' => 'How many days ahead to look for open times.',
            'min_notice_minutes' => 'No time sooner than this from now.',
            'visit_minutes' => 'How long a visit blocks a worker.',
            'max_slots_per_day' => 'At most this many of the offered times on the same day.',
            'max_extra_drive_minutes' => 'Times that add more driving than this are offered only if nothing better is open.',
        ];
    @endphp

    <div class="mx-auto flex w-full max-w-4xl flex-col gap-4">
        @include('admin::settings.txn.partials.top')

        <form method="POST" action="{{ route('admin.settings.txn.area.update') }}" class="flex flex-col gap-6 rounded-lg border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-gray-900">
            @csrf
            @method('PUT')

            <section class="flex flex-col gap-3">
                <div>
                    <h2 class="text-lg font-semibold dark:text-white">Service area</h2>
                    <p class="text-sm text-gray-500">The lead check measures each customer's distance from the centre. Farther than the radius: Needs Review with the out-of-area message (never rejected).</p>
                </div>

                <div>
                    <label for="f-area-address" class="mb-1 block text-sm font-medium dark:text-gray-200">Centre: address, city or ZIP</label>
                    <input id="f-area-address" type="text" name="area_address" maxlength="200" value="{{ old('area_address', $area['address'] ?? '') }}" placeholder="e.g. Houston, TX" class="{{ $input }}" @disabled(! $canEdit)>
                    <p class="mt-1 text-xs text-gray-500">When you change it, it is found on the map once and the point below is filled in. Or leave it and type the point yourself.</p>
                </div>

                <div class="flex flex-wrap gap-4">
                    <div>
                        <label for="f-area-lat" class="mb-1 block text-xs dark:text-gray-300">Latitude</label>
                        <input id="f-area-lat" type="number" step="0.000001" name="area_lat" value="{{ old('area_lat', $area['center_lat']) }}" class="{{ $small }}" @disabled(! $canEdit)>
                    </div>
                    <div>
                        <label for="f-area-lon" class="mb-1 block text-xs dark:text-gray-300">Longitude</label>
                        <input id="f-area-lon" type="number" step="0.000001" name="area_lon" value="{{ old('area_lon', $area['center_lon']) }}" class="{{ $small }}" @disabled(! $canEdit)>
                    </div>
                    <div>
                        <label for="f-area-miles" class="mb-1 block text-xs dark:text-gray-300">Radius (miles)</label>
                        <input id="f-area-miles" type="number" min="5" max="1000" name="area_miles" value="{{ old('area_miles', $area['miles']) }}" class="{{ $small }}" @disabled(! $canEdit)>
                    </div>
                </div>
                @include('admin::settings.txn.partials.field-meta', ['key' => 'slot_finder.service_area'])

                <div>
                    <label for="f-ooa" class="mb-1 block text-sm font-medium dark:text-gray-200">{{ $fields['lead_triage.out_of_area_message'][2] }}</label>
                    <textarea id="f-ooa" name="out_of_area_message" rows="4" maxlength="2000" class="{{ $input }}" @disabled(! $canEdit)>{{ old('out_of_area_message', $values['lead_triage.out_of_area_message']) }}</textarea>
                    <p class="mt-1 text-xs text-gray-500">Shown on out-of-area leads, ready to copy and send.</p>
                    @include('admin::settings.txn.partials.field-meta', ['key' => 'lead_triage.out_of_area_message'])
                </div>
            </section>

            <section class="flex flex-col gap-3">
                <div>
                    <h2 class="text-lg font-semibold dark:text-white">Times offered to customers</h2>
                    <p class="text-sm text-gray-500">Used by "Suggest times" and the booking link. Workers' hours and company holidays are under Workers &gt; Company schedule.</p>
                </div>

                <div>
                    <label for="f-slot-times" class="mb-1 block text-sm font-medium dark:text-gray-200">{{ $fields['slot_finder.slot_times'][2] }}</label>
                    <input id="f-slot-times" type="text" name="slot_times" value="{{ old('slot_times', implode(', ', (array) $values['slot_finder.slot_times'])) }}" class="{{ $input }}" @disabled(! $canEdit)>
                    <p class="mt-1 text-xs text-gray-500">Start times on a 24-hour clock, separated by commas, e.g. 09:00, 11:00, 13:00, 15:00, 17:00.</p>
                    @include('admin::settings.txn.partials.field-meta', ['key' => 'slot_finder.slot_times'])
                </div>

                @foreach ($numbers as $name => $help)
                    @php($key = "slot_finder.{$name}")
                    <div>
                        <label for="f-{{ $name }}" class="mb-1 block text-sm font-medium dark:text-gray-200">{{ $fields[$key][2] }}</label>
                        <input id="f-{{ $name }}" type="number" name="{{ $name }}" min="{{ $fields[$key][3]['min'] }}" max="{{ $fields[$key][3]['max'] }}" value="{{ old($name, $values[$key]) }}" class="{{ $small }}" @disabled(! $canEdit)>
                        <p class="mt-1 text-xs text-gray-500">{{ $help }}</p>
                        @include('admin::settings.txn.partials.field-meta', ['key' => $key])
                    </div>
                @endforeach

                @php($currentTz = $values['slot_finder.timezone'])
                <div>
                    <label for="f-timezone" class="mb-1 block text-sm font-medium dark:text-gray-200">{{ $fields['slot_finder.timezone'][2] }}</label>
                    <select id="f-timezone" name="timezone" class="{{ $input }}" style="max-width: 20rem;" @disabled(! $canEdit)
                        onchange="document.getElementById('tz-confirm').style.display = this.value === @js($currentTz) ? 'none' : ''">
                        @foreach ($timezones as $zone => $label)
                            <option value="{{ $zone }}" @selected(old('timezone', $currentTz) === $zone)>{{ $label }} ({{ $zone }})</option>
                        @endforeach
                        @unless (isset($timezones[$currentTz]))
                            <option value="{{ $currentTz }}" @selected(old('timezone', $currentTz) === $currentTz)>{{ $currentTz }}</option>
                        @endunless
                    </select>
                    <p class="mt-1 text-xs text-gray-500">The times offered, the emails ("Times are in Central Time"), the calendars and the follow-ups use this zone.</p>
                    <label id="tz-confirm" class="mt-2 flex items-start gap-2 rounded border border-amber-300 bg-amber-50 p-2 text-sm text-amber-900" style="{{ old('timezone', $currentTz) === $currentTz ? 'display: none;' : '' }}">
                        <input type="checkbox" name="confirm_timezone" value="1" class="mt-1">
                        <span>I understand: appointments already in Krayin keep their clock times (a 9:00 AM visit stays 9:00 AM in the new zone). Only change this if the business really moved to another zone.</span>
                    </label>
                    @include('admin::settings.txn.partials.field-meta', ['key' => 'slot_finder.timezone'])
                </div>
            </section>

            @php
                $advancedHelp = [
                    'slot_finder.ranking.road_times' => 'On: times are ranked with real road drive times from the routing server. Off: a straight-line estimate (the two settings below it).',
                    'slot_finder.ranking.max_table_points' => 'If a suggestion needs more points than this, the estimate is used instead. The public OSRM server allows 100.',
                    'slot_finder.near_job_minutes' => 'On the booking page, a booked job this close to the customer is marked "near".',
                    'slot_finder.route_fit_tolerance_minutes' => 'Extra driving within this many minutes counts as equal, so a sooner time wins over a later one that saves a few minutes.',
                    'slot_finder.road_distance_factor' => 'Estimate only: straight-line miles are multiplied by this to get road miles.',
                    'slot_finder.average_speed_mph' => 'Estimate only: road miles are driven at this speed.',
                    'slot_finder.routing.osrm_url' => 'Where road routes and drive times come from. The public server (router.project-osrm.org) is for testing; use your own for production.',
                    'slot_finder.routing.base_traffic_factor' => 'Road drive times are multiplied by this outside the rush hours below.',
                    'slot_finder.routing.traffic_factors' => 'On weekdays, a drive starting inside one of these periods is multiplied by its factor instead. Tick Remove to delete a row; fill an empty row to add one.',
                    'slot_finder.routing.return_to_start' => 'Workers > Routes: draw the drive back to the start address after the last visit.',
                    'slot_finder.routing.cache_days' => 'Road routes already looked up are reused for this many days.',
                ];
                $advancedChanged = collect($advanced)->keys()->contains(fn ($key) => isset($saved[$key])) || $errors->any();
            @endphp

            <details class="flex flex-col gap-3" @if ($advancedChanged) open @endif>
                <summary class="cursor-pointer text-lg font-semibold dark:text-white">Ranking &amp; routes (advanced)</summary>
                <p class="mt-1 text-sm text-gray-500">How open times are ranked and how drive times are worked out. The defaults suit most cases.</p>

                @foreach ($advanced as $key => $name)
                    @php($type = $fields[$key][1])
                    <div class="mt-4">
                        @if ($type === 'bool')
                            <input type="hidden" name="{{ $name }}" value="0">
                            <label class="flex items-center gap-2 text-sm font-medium dark:text-gray-200">
                                <input type="checkbox" name="{{ $name }}" value="1" @checked(old($name, $values[$key] ? '1' : '0') === '1') @disabled(! $canEdit)>
                                {{ $fields[$key][2] }}
                            </label>
                        @elseif ($type === 'traffic')
                            <p class="mb-1 text-sm font-medium dark:text-gray-200">{{ $fields[$key][2] }}</p>
                            @php
                                // Saved rows (or what was typed, after an error) + two empty rows to add more.
                                $rows = is_array(old($name))
                                    ? array_values(array_map(fn ($row) => [$row['from'] ?? '', $row['to'] ?? '', $row['factor'] ?? ''], array_filter(old($name), fn ($row) => empty($row['remove']))))
                                    : (array) $values[$key];
                                $rows = array_merge($rows, [['', '', ''], ['', '', '']]);
                            @endphp
                            <table class="text-sm dark:text-gray-200">
                                <thead>
                                    <tr class="text-left text-xs text-gray-500">
                                        <th class="pb-1 pr-2 font-normal">From</th>
                                        <th class="pb-1 pr-2 font-normal">To</th>
                                        <th class="pb-1 pr-2 font-normal">Factor</th>
                                        <th class="pb-1 font-normal">Remove</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($rows as $i => [$from, $to, $factor])
                                        <tr>
                                            <td class="pb-1 pr-2"><input type="time" name="{{ $name }}[{{ $i }}][from]" value="{{ $from }}" class="{{ $small }}" aria-label="From" @disabled(! $canEdit)></td>
                                            <td class="pb-1 pr-2"><input type="time" name="{{ $name }}[{{ $i }}][to]" value="{{ $to }}" class="{{ $small }}" aria-label="To" @disabled(! $canEdit)></td>
                                            <td class="pb-1 pr-2"><input type="number" step="0.05" min="1" max="3" name="{{ $name }}[{{ $i }}][factor]" value="{{ $factor }}" class="{{ $small }}" style="width: 6rem;" aria-label="Factor" @disabled(! $canEdit)></td>
                                            <td class="pb-1 text-center"><input type="checkbox" name="{{ $name }}[{{ $i }}][remove]" value="1" aria-label="Remove" @disabled(! $canEdit)></td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        @else
                            <label for="f-{{ $name }}" class="mb-1 block text-sm font-medium dark:text-gray-200">{{ $fields[$key][2] }}</label>
                            @if ($type === 'url')
                                <input id="f-{{ $name }}" type="url" name="{{ $name }}" maxlength="255" value="{{ old($name, $values[$key]) }}" class="{{ $input }}" @disabled(! $canEdit)>
                            @else
                                <input id="f-{{ $name }}" type="number" name="{{ $name }}" step="{{ $type === 'decimal' ? '0.05' : '1' }}" min="{{ $fields[$key][3]['min'] }}" max="{{ $fields[$key][3]['max'] }}" value="{{ old($name, $values[$key]) }}" class="{{ $small }}" @disabled(! $canEdit)>
                            @endif
                        @endif
                        <p class="mt-1 text-xs text-gray-500">{{ $advancedHelp[$key] ?? '' }}</p>
                        @include('admin::settings.txn.partials.field-meta', ['key' => $key])
                    </div>
                @endforeach
            </details>

            @if ($canEdit)
                <div class="flex justify-between gap-3">
                    <button type="submit" name="reset" value="all" formnovalidate class="secondary-button" onclick="return confirm('Put every setting on this page back to its default?')">Reset this page to defaults</button>
                    <button type="submit" class="primary-button">Save</button>
                </div>
            @endif
        </form>

        @include('admin::settings.txn.partials.log')
    </div>
</x-admin::layouts>
