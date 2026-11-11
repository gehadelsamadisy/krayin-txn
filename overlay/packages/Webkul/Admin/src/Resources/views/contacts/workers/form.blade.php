<x-admin::layouts>
    <x-slot:title>
        {{ $worker ? 'Edit worker' : 'Register worker' }}
    </x-slot>

    <div class="mx-auto flex w-full max-w-5xl flex-col gap-4">
        <div class="rounded-lg border border-gray-200 bg-white px-4 py-3 dark:border-gray-800 dark:bg-gray-900">
            <h1 class="text-xl font-bold dark:text-white">
                {{ $worker ? 'Edit worker' : 'Register worker' }}
            </h1>
        </div>

        <form
            method="POST"
            action="{{ $worker ? route('admin.contacts.workers.update', $worker->id) : route('admin.contacts.workers.store') }}"
            class="flex flex-col gap-6 rounded-lg border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-gray-900"
        >
            @csrf
            @if ($worker)
                @method('PUT')
            @endif

            <section class="grid gap-4 md:grid-cols-2">
                <h2 class="text-lg font-semibold dark:text-white md:col-span-2">Worker details</h2>

                <div>
                    <label for="name" class="mb-1 block text-sm font-medium dark:text-gray-200">Name</label>
                    <input id="name" name="name" type="text" value="{{ old('name', $worker?->name ?? '') }}" required maxlength="255" class="w-full rounded border border-gray-300 px-3 py-2 dark:border-gray-700 dark:bg-gray-800 dark:text-white">
                    @error('name') <p class="mt-1 text-sm text-red-600" role="alert">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="phone" class="mb-1 block text-sm font-medium dark:text-gray-200">Phone</label>
                    <input id="phone" name="phone" type="text" value="{{ old('phone', $worker?->phone ?? '') }}" maxlength="50" class="w-full rounded border border-gray-300 px-3 py-2 dark:border-gray-700 dark:bg-gray-800 dark:text-white">
                    @error('phone') <p class="mt-1 text-sm text-red-600" role="alert">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="email" class="mb-1 block text-sm font-medium dark:text-gray-200">Email</label>
                    <input id="email" name="email" type="email" value="{{ old('email', $worker?->email ?? '') }}" maxlength="255" class="w-full rounded border border-gray-300 px-3 py-2 dark:border-gray-700 dark:bg-gray-800 dark:text-white">
                    @error('email') <p class="mt-1 text-sm text-red-600" role="alert">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="priority" class="mb-1 block text-sm font-medium dark:text-gray-200">Priority</label>
                    <input id="priority" name="priority" type="number" min="1" max="65535" value="{{ old('priority', $worker?->priority ?? 1) }}" required class="w-full rounded border border-gray-300 px-3 py-2 dark:border-gray-700 dark:bg-gray-800 dark:text-white">
                    <p class="mt-1 text-xs text-gray-500">1 is preferred; priority only breaks scheduling ties.</p>
                    @error('priority') <p class="mt-1 text-sm text-red-600" role="alert">{{ $message }}</p> @enderror
                </div>

                <div class="md:col-span-2">
                    <label for="notes" class="mb-1 block text-sm font-medium dark:text-gray-200">Notes</label>
                    <textarea id="notes" name="notes" rows="3" maxlength="2000" class="w-full rounded border border-gray-300 px-3 py-2 dark:border-gray-700 dark:bg-gray-800 dark:text-white">{{ old('notes', $worker?->notes ?? '') }}</textarea>
                    @error('notes') <p class="mt-1 text-sm text-red-600" role="alert">{{ $message }}</p> @enderror
                </div>

                <div class="md:col-span-2">
                    <label for="start_address" class="mb-1 block text-sm font-medium dark:text-gray-200">Start and end address</label>
                    <input id="start_address" name="start_address" type="text" value="{{ old('start_address', $worker?->start_address ?? '') }}" required maxlength="255" placeholder="Worker's home or office" class="w-full rounded border border-gray-300 px-3 py-2 dark:border-gray-700 dark:bg-gray-800 dark:text-white">
                    <p class="mt-1 text-xs text-gray-500">
                        Krayin looks up the map location when you save.
                        @if ($worker?->home_latitude !== null && $worker?->home_longitude !== null)
                            Saved location: {{ number_format($worker->home_latitude, 5) }}, {{ number_format($worker->home_longitude, 5) }}.
                        @endif
                    </p>
                    @error('start_address') <p class="mt-1 text-sm text-red-600" role="alert">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="services_text" class="mb-1 block text-sm font-medium dark:text-gray-200">Services they can do</label>
                    <textarea id="services_text" name="services_text" rows="3" maxlength="5000" placeholder="One service per line, e.g. Gutter" class="w-full rounded border border-gray-300 px-3 py-2 dark:border-gray-700 dark:bg-gray-800 dark:text-white">{{ old('services_text', implode("\n", $worker?->services ?? [])) }}</textarea>
                    <p class="mt-1 text-xs text-gray-500">Leave blank if all workers do every service.</p>
                    @error('services_text') <p class="mt-1 text-sm text-red-600" role="alert">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="max_visits_per_day" class="mb-1 block text-sm font-medium dark:text-gray-200">Max visits per day</label>
                    <input id="max_visits_per_day" name="max_visits_per_day" type="number" min="1" max="65535" value="{{ old('max_visits_per_day', $worker?->max_visits_per_day ?? '') }}" class="w-full rounded border border-gray-300 px-3 py-2 dark:border-gray-700 dark:bg-gray-800 dark:text-white">
                    <p class="mt-1 text-xs text-gray-500">Leave blank for no daily cap.</p>
                    @error('max_visits_per_day') <p class="mt-1 text-sm text-red-600" role="alert">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="calendar_color" class="mb-1 block text-sm font-medium dark:text-gray-200">Calendar color</label>
                    <input id="calendar_color" name="calendar_color" type="text" pattern="#[0-9A-Fa-f]{6}" maxlength="7" placeholder="#2563EB" value="{{ old('calendar_color', $worker?->calendar_color ?? '') }}" class="w-full rounded border border-gray-300 px-3 py-2 dark:border-gray-700 dark:bg-gray-800 dark:text-white">
                    <p class="mt-1 text-xs text-gray-500">Optional six-digit hex color.</p>
                    @error('calendar_color') <p class="mt-1 text-sm text-red-600" role="alert">{{ $message }}</p> @enderror
                </div>

                <div class="flex items-center gap-2">
                    <input type="hidden" name="is_active" value="0">
                    <input id="is_active" type="checkbox" name="is_active" value="1" @checked(old('is_active', $worker?->is_active ?? true))>
                    <label for="is_active" class="text-sm dark:text-gray-200">Active — can receive new appointments</label>
                </div>
            </section>

            <section class="flex flex-col gap-3">
                <div>
                    <h2 class="text-lg font-semibold dark:text-white">Weekly work hours</h2>
                    <p class="text-sm text-gray-500">Leave every day blank to use company default hours. A blank day in a custom schedule is a day off.</p>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="border-b text-gray-600 dark:border-gray-700 dark:text-gray-300">
                            <tr><th class="py-2 pr-3">Day</th><th class="py-2 pr-3">Start</th><th class="py-2">End</th></tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-800">
                            @foreach ($weekdays as $day => $dayName)
                                @php
                                    $hours = old("work_hours.{$day}", $worker?->work_hours[$day] ?? []);
                                    $start = is_array($hours) ? ($hours['start'] ?? $hours[0] ?? '') : '';
                                    $end = is_array($hours) ? ($hours['end'] ?? $hours[1] ?? '') : '';
                                @endphp
                                <tr>
                                    <td class="py-2 pr-3 dark:text-gray-200">{{ $dayName }}</td>
                                    <td class="py-2 pr-3"><input type="time" name="work_hours[{{ $day }}][start]" value="{{ $start }}" class="rounded border border-gray-300 px-2 py-1 dark:border-gray-700 dark:bg-gray-800 dark:text-white"></td>
                                    <td class="py-2"><input type="time" name="work_hours[{{ $day }}][end]" value="{{ $end }}" class="rounded border border-gray-300 px-2 py-1 dark:border-gray-700 dark:bg-gray-800 dark:text-white"></td>
                                </tr>
                                @error("work_hours.{$day}.start") <tr><td colspan="3" class="pb-2 text-sm text-red-600" role="alert">{{ $message }}</td></tr> @enderror
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="flex flex-col gap-3">
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <h2 class="text-lg font-semibold dark:text-white">Time off</h2>
                        <p class="text-sm text-gray-500">Add whole days or, for one date, a partial-day time range.</p>
                    </div>
                    <button id="add-time-off" type="button" class="secondary-button">Add time off</button>
                </div>

                @error('time_off') <p class="text-sm text-red-600" role="alert">{{ $message }}</p> @enderror
                <div id="time-off-list" class="flex flex-col gap-3">
                    @php
                        $timeOffValues = old('time_off', $worker?->timeOffs?->map(fn ($entry) => [
                            'start_date' => $entry->start_date?->format('Y-m-d'),
                            'end_date' => $entry->end_date?->format('Y-m-d'),
                            'start_time' => $entry->start_time,
                            'end_time' => $entry->end_time,
                            'type' => $entry->type,
                            'note' => $entry->note,
                        ])->all() ?? []);
                        $nextTimeOffIndex = $timeOffValues ? max(array_keys($timeOffValues)) + 1 : 0;
                    @endphp

                    @foreach ($timeOffValues as $index => $entry)
                        <div class="time-off-entry grid gap-3 rounded border border-gray-200 p-3 dark:border-gray-700 md:grid-cols-3">
                            <div><label class="mb-1 block text-xs dark:text-gray-300">From</label><input type="date" name="time_off[{{ $index }}][start_date]" value="{{ $entry['start_date'] ?? '' }}" class="w-full rounded border border-gray-300 px-2 py-1.5 dark:border-gray-700 dark:bg-gray-800 dark:text-white"></div>
                            <div><label class="mb-1 block text-xs dark:text-gray-300">To</label><input type="date" name="time_off[{{ $index }}][end_date]" value="{{ $entry['end_date'] ?? '' }}" class="w-full rounded border border-gray-300 px-2 py-1.5 dark:border-gray-700 dark:bg-gray-800 dark:text-white"></div>
                            <div><label class="mb-1 block text-xs dark:text-gray-300">Type</label><select name="time_off[{{ $index }}][type]" class="w-full rounded border border-gray-300 px-2 py-1.5 dark:border-gray-700 dark:bg-gray-800 dark:text-white"><option value="">Choose type</option>@foreach (['annual_leave' => 'Annual leave', 'sick' => 'Sick', 'personal' => 'Personal', 'other' => 'Other'] as $value => $label)<option value="{{ $value }}" @selected(($entry['type'] ?? '') === $value)>{{ $label }}</option>@endforeach</select></div>
                            <div><label class="mb-1 block text-xs dark:text-gray-300">Part of day — start (optional)</label><input type="time" name="time_off[{{ $index }}][start_time]" value="{{ $entry['start_time'] ?? '' }}" class="w-full rounded border border-gray-300 px-2 py-1.5 dark:border-gray-700 dark:bg-gray-800 dark:text-white"></div>
                            <div><label class="mb-1 block text-xs dark:text-gray-300">Part of day — end (optional)</label><input type="time" name="time_off[{{ $index }}][end_time]" value="{{ $entry['end_time'] ?? '' }}" class="w-full rounded border border-gray-300 px-2 py-1.5 dark:border-gray-700 dark:bg-gray-800 dark:text-white"></div>
                            <div><label class="mb-1 block text-xs dark:text-gray-300">Note (optional)</label><input type="text" name="time_off[{{ $index }}][note]" maxlength="1000" value="{{ $entry['note'] ?? '' }}" class="w-full rounded border border-gray-300 px-2 py-1.5 dark:border-gray-700 dark:bg-gray-800 dark:text-white"></div>
                            <button type="button" class="remove-time-off justify-self-start text-sm text-red-600 hover:underline md:col-span-3">Remove entry</button>
                        </div>
                    @endforeach
                </div>
            </section>

            <div class="flex items-center justify-end gap-2">
                <a href="{{ route('admin.contacts.workers.index') }}" class="secondary-button">Cancel</a>
                <button type="submit" class="primary-button">{{ $worker ? 'Save changes' : 'Register worker' }}</button>
            </div>
        </form>
    </div>

    <template id="time-off-template">
        <div class="time-off-entry grid gap-3 rounded border border-gray-200 p-3 dark:border-gray-700 md:grid-cols-3">
            <div><label class="mb-1 block text-xs dark:text-gray-300">From</label><input type="date" data-field="start_date" class="w-full rounded border border-gray-300 px-2 py-1.5 dark:border-gray-700 dark:bg-gray-800 dark:text-white"></div>
            <div><label class="mb-1 block text-xs dark:text-gray-300">To</label><input type="date" data-field="end_date" class="w-full rounded border border-gray-300 px-2 py-1.5 dark:border-gray-700 dark:bg-gray-800 dark:text-white"></div>
            <div><label class="mb-1 block text-xs dark:text-gray-300">Type</label><select data-field="type" class="w-full rounded border border-gray-300 px-2 py-1.5 dark:border-gray-700 dark:bg-gray-800 dark:text-white"><option value="">Choose type</option><option value="annual_leave">Annual leave</option><option value="sick">Sick</option><option value="personal">Personal</option><option value="other">Other</option></select></div>
            <div><label class="mb-1 block text-xs dark:text-gray-300">Part of day — start (optional)</label><input type="time" data-field="start_time" class="w-full rounded border border-gray-300 px-2 py-1.5 dark:border-gray-700 dark:bg-gray-800 dark:text-white"></div>
            <div><label class="mb-1 block text-xs dark:text-gray-300">Part of day — end (optional)</label><input type="time" data-field="end_time" class="w-full rounded border border-gray-300 px-2 py-1.5 dark:border-gray-700 dark:bg-gray-800 dark:text-white"></div>
            <div><label class="mb-1 block text-xs dark:text-gray-300">Note (optional)</label><input type="text" data-field="note" maxlength="1000" class="w-full rounded border border-gray-300 px-2 py-1.5 dark:border-gray-700 dark:bg-gray-800 dark:text-white"></div>
            <button type="button" class="remove-time-off justify-self-start text-sm text-red-600 hover:underline md:col-span-3">Remove entry</button>
        </div>
    </template>

    @pushOnce('scripts')
        <script>
            (() => {
                let nextIndex = {{ $nextTimeOffIndex }};

                document.addEventListener('click', (event) => {
                    if (!(event.target instanceof Element)) {
                        return;
                    }

                    if (event.target.closest('#add-time-off')) {
                        const list = document.getElementById('time-off-list');
                        const template = document.getElementById('time-off-template');

                        if (! list || ! template) {
                            return;
                        }

                        const entry = template.content.firstElementChild.cloneNode(true);
                        entry.querySelectorAll('[data-field]').forEach((input) => {
                            input.name = `time_off[${nextIndex}][${input.dataset.field}]`;
                        });
                        nextIndex += 1;
                        list.appendChild(entry);

                        return;
                    }

                    const removeButton = event.target.closest('.remove-time-off');

                    if (removeButton) {
                        removeButton.closest('.time-off-entry')?.remove();
                    }
                });
            })();
        </script>
    @endPushOnce
</x-admin::layouts>
