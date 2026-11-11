<x-admin::layouts>
    <x-slot:title>
        Company schedule and holidays
    </x-slot>

    <div class="mx-auto flex w-full max-w-4xl flex-col gap-4">
        <div class="flex items-center justify-between rounded-lg border border-gray-200 bg-white px-4 py-3 dark:border-gray-800 dark:bg-gray-900">
            <div>
                <h1 class="text-xl font-bold dark:text-white">Company schedule and holidays</h1>
                <p class="text-sm text-gray-500">Used when a worker has no custom weekly hours.</p>
            </div>
            <a href="{{ route('admin.contacts.workers.index') }}" class="secondary-button">Back to workers</a>
        </div>

        <form method="POST" action="{{ route('admin.contacts.workers.settings.update') }}" class="flex flex-col gap-6 rounded-lg border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-gray-900">
            @csrf
            @method('PUT')

            <section class="flex flex-col gap-3">
                <div>
                    <h2 class="text-lg font-semibold dark:text-white">Default weekly hours</h2>
                    <p class="text-sm text-gray-500">Set a day off by leaving both times blank. New installs default to 9:00 AM–6:00 PM every day.</p>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="border-b text-gray-600 dark:border-gray-700 dark:text-gray-300">
                            <tr><th class="py-2 pr-3">Day</th><th class="py-2 pr-3">Start</th><th class="py-2">End</th></tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-800">
                            @foreach ($weekdays as $day => $dayName)
                                @php
                                    $start = old("default_work_hours.{$day}.start", $settings->default_work_hours[$day][0] ?? '');
                                    $end = old("default_work_hours.{$day}.end", $settings->default_work_hours[$day][1] ?? '');
                                @endphp
                                <tr>
                                    <td class="py-2 pr-3 dark:text-gray-200">{{ $dayName }}</td>
                                    <td class="py-2 pr-3"><input type="time" name="default_work_hours[{{ $day }}][start]" value="{{ $start }}" class="rounded border border-gray-300 px-2 py-1 dark:border-gray-700 dark:bg-gray-800 dark:text-white"></td>
                                    <td class="py-2"><input type="time" name="default_work_hours[{{ $day }}][end]" value="{{ $end }}" class="rounded border border-gray-300 px-2 py-1 dark:border-gray-700 dark:bg-gray-800 dark:text-white"></td>
                                </tr>
                                @error("default_work_hours.{$day}.start") <tr><td colspan="3" class="pb-2 text-sm text-red-600" role="alert">{{ $message }}</td></tr> @enderror
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="flex flex-col gap-3">
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <h2 class="text-lg font-semibold dark:text-white">Company holidays</h2>
                        <p class="text-sm text-gray-500">These dates apply to all workers.</p>
                    </div>
                    <button id="add-holiday" type="button" class="secondary-button">Add holiday</button>
                </div>
                @error('holidays') <p class="text-sm text-red-600" role="alert">{{ $message }}</p> @enderror
                <div id="holiday-list" class="flex flex-col gap-3">
                    @foreach (old('holidays', $settings->holidays ?? []) as $index => $holiday)
                        <div class="holiday-entry grid gap-3 rounded border border-gray-200 p-3 dark:border-gray-700 md:grid-cols-[1fr_2fr_auto]">
                            <div><label class="mb-1 block text-xs dark:text-gray-300">Date</label><input type="date" name="holidays[{{ $index }}][date]" value="{{ $holiday['date'] ?? '' }}" class="w-full rounded border border-gray-300 px-2 py-1.5 dark:border-gray-700 dark:bg-gray-800 dark:text-white"></div>
                            <div><label class="mb-1 block text-xs dark:text-gray-300">Name</label><input type="text" name="holidays[{{ $index }}][name]" maxlength="255" value="{{ $holiday['name'] ?? '' }}" placeholder="Company holiday" class="w-full rounded border border-gray-300 px-2 py-1.5 dark:border-gray-700 dark:bg-gray-800 dark:text-white"></div>
                            <button type="button" class="remove-holiday self-end text-sm text-red-600 hover:underline">Remove</button>
                        </div>
                    @endforeach
                </div>
            </section>

            <div class="flex justify-end">
                <button type="submit" class="primary-button">Save company schedule</button>
            </div>
        </form>
    </div>

    <template id="holiday-template">
        <div class="holiday-entry grid gap-3 rounded border border-gray-200 p-3 dark:border-gray-700 md:grid-cols-[1fr_2fr_auto]">
            <div><label class="mb-1 block text-xs dark:text-gray-300">Date</label><input type="date" data-field="date" class="w-full rounded border border-gray-300 px-2 py-1.5 dark:border-gray-700 dark:bg-gray-800 dark:text-white"></div>
            <div><label class="mb-1 block text-xs dark:text-gray-300">Name</label><input type="text" data-field="name" maxlength="255" placeholder="Company holiday" class="w-full rounded border border-gray-300 px-2 py-1.5 dark:border-gray-700 dark:bg-gray-800 dark:text-white"></div>
            <button type="button" class="remove-holiday self-end text-sm text-red-600 hover:underline">Remove</button>
        </div>
    </template>

    @pushOnce('scripts')
        <script>
            (() => {
                const list = document.getElementById('holiday-list');
                const template = document.getElementById('holiday-template');
                let nextIndex = {{ count(old('holidays', $settings->holidays ?? [])) }};

                document.getElementById('add-holiday').addEventListener('click', () => {
                    const entry = template.content.firstElementChild.cloneNode(true);
                    entry.querySelectorAll('[data-field]').forEach((input) => {
                        input.name = `holidays[${nextIndex}][${input.dataset.field}]`;
                    });
                    nextIndex += 1;
                    list.appendChild(entry);
                });

                list.addEventListener('click', (event) => {
                    if (event.target.closest('.remove-holiday')) {
                        event.target.closest('.holiday-entry').remove();
                    }
                });
            })();
        </script>
    @endPushOnce
</x-admin::layouts>
