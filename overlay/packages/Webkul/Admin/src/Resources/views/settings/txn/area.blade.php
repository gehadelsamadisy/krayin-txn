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
            </section>

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
