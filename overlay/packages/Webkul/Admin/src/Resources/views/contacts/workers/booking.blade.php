<x-admin::layouts>
    <x-slot:title>
        Book {{ $worker->name }}
    </x-slot>

    @php
        $scheduleUrl = route('admin.contacts.workers.schedule', ['worker' => $worker->id, 'date' => $date->toDateString()]);
        $startValue = old('schedule_from', $date->setTime(9, 0)->format('Y-m-d\TH:i'));
        $endValue = old('schedule_to', $date->setTime(10, 0)->format('Y-m-d\TH:i'));
        $bookingType = old('booking_type', 'work');
    @endphp

    <div class="mx-auto flex w-full max-w-5xl flex-col gap-4">
        <div class="rounded-lg border border-gray-200 bg-white px-4 py-3 dark:border-gray-800 dark:bg-gray-900">
            <h1 class="text-xl font-bold dark:text-white">Book worker</h1>
            <p class="text-sm text-gray-500">{{ $worker->name }} · Schedule work visits or other bookings</p>
        </div>

        <form
            method="POST"
            action="{{ route('admin.contacts.workers.bookings.store', $worker->id) }}"
            class="flex flex-col gap-5 rounded-lg border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-gray-900"
        >
            @csrf

            @error('worker')
                <p class="text-sm text-red-600" role="alert">{{ $message }}</p>
            @enderror

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="title" class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">What does the worker need to do?</label>
                    <input id="title" name="title" type="text" maxlength="255" value="{{ old('title') }}" required class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-800 dark:text-white">
                    @error('title') <p class="mt-1 text-sm text-red-600" role="alert">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="booking_type" class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Booking type</label>
                    <select id="booking_type" name="booking_type" required class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-800 dark:text-white">
                        <option value="work" @selected($bookingType === 'work')>Work-related visit (include in route)</option>
                        <option value="other" @selected($bookingType === 'other')>Other / personal booking</option>
                    </select>
                    @error('booking_type') <p class="mt-1 text-sm text-red-600" role="alert">{{ $message }}</p> @enderror
                </div>

                <div id="work-location-group">
                    <label for="location" class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Location <span id="location-requirement">(required for work route)</span></label>
                    <input id="location" name="location" type="text" maxlength="255" value="{{ old('location') }}" @required($bookingType === 'work') class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-800 dark:text-white">
                    @error('location') <p class="mt-1 text-sm text-red-600" role="alert">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="schedule_from" class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Start</label>
                    <input id="schedule_from" name="schedule_from" type="datetime-local" value="{{ $startValue }}" required class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-800 dark:text-white">
                    @error('schedule_from') <p class="mt-1 text-sm text-red-600" role="alert">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="schedule_to" class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">End</label>
                    <input id="schedule_to" name="schedule_to" type="datetime-local" value="{{ $endValue }}" required class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-800 dark:text-white">
                    @error('schedule_to') <p class="mt-1 text-sm text-red-600" role="alert">{{ $message }}</p> @enderror
                </div>

                <div class="sm:col-span-2">
                    <label for="comment" class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Notes (optional)</label>
                    <textarea id="comment" name="comment" rows="4" maxlength="2000" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-800 dark:text-white">{{ old('comment') }}</textarea>
                    @error('comment') <p class="mt-1 text-sm text-red-600" role="alert">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="flex items-center justify-end gap-2 border-t border-gray-200 pt-4 dark:border-gray-800">
                <a href="{{ $scheduleUrl }}" class="secondary-button">Cancel</a>
                <button type="submit" class="primary-button">Book worker</button>
            </div>
        </form>
    </div>

    @pushOnce('scripts')
        <script>
            (() => {
                const updateLocationRequirement = () => {
                    const bookingType = document.getElementById('booking_type');
                    const location = document.getElementById('location');
                    const requirement = document.getElementById('location-requirement');

                    if (!bookingType || !location || !requirement) {
                        return;
                    }

                    const isWorkVisit = bookingType.value === 'work';
                    location.required = isWorkVisit;
                    requirement.textContent = isWorkVisit
                        ? '(required; included in route)'
                        : '(optional; not included in route)';
                };

                document.addEventListener('change', (event) => {
                    if (event.target instanceof Element && event.target.closest('#booking_type')) {
                        updateLocationRequirement();
                    }
                });

                updateLocationRequirement();
            })();
        </script>
    @endPushOnce
</x-admin::layouts>
