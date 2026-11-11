<x-admin::layouts>
    <x-slot:title>
        Edit time off
    </x-slot>

    @php
        $entry = old('time_off.0', [
            'start_date' => $timeOff->start_date?->format('Y-m-d'),
            'end_date' => $timeOff->end_date?->format('Y-m-d'),
            'start_time' => $timeOff->start_time ? substr($timeOff->start_time, 0, 5) : null,
            'end_time' => $timeOff->end_time ? substr($timeOff->end_time, 0, 5) : null,
            'type' => $timeOff->type,
            'note' => $timeOff->note,
        ]);
        $scheduleUrl = route('admin.contacts.workers.schedule', ['worker' => $worker->id, 'date' => $timeOff->start_date->format('Y-m-d')]);
    @endphp

    <style>
        .wto-form { display: flex; flex-direction: column; gap: 20px; padding: 24px; }
        .wto-grid { display: grid; gap: 14px 16px; grid-template-columns: repeat(3, minmax(0, 1fr)); }
        .wto-grid label { display: block; margin-bottom: 4px; font-size: 12px; font-weight: 500; color: #4b5563; }
        .wto-grid input, .wto-grid select { width: 100%; padding: 7px 10px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px; background: #fff; }
        .wto-help { font-size: 13px; color: #6b7280; }
        .wto-error { font-size: 13px; color: #dc2626; }
        .wto-actions { display: flex; align-items: center; justify-content: space-between; gap: 8px; padding-top: 16px; border-top: 1px solid #e5e7eb; }
        .wto-delete { font-size: 14px; color: #dc2626; }
        .wto-delete:hover { text-decoration: underline; }
        .dark .wto-grid label { color: #d1d5db; }
        .dark .wto-grid input, .dark .wto-grid select { background: #1f2937; border-color: #374151; color: #fff; }
        .dark .wto-actions { border-color: #1f2937; }
        @media (max-width: 768px) { .wto-grid { grid-template-columns: minmax(0, 1fr); } }
    </style>

    <div class="mx-auto flex w-full max-w-5xl flex-col gap-4">
        <div class="rounded-lg border border-gray-200 bg-white px-4 py-3 dark:border-gray-800 dark:bg-gray-900">
            <h1 class="text-xl font-bold dark:text-white">Edit time off</h1>
            <p class="text-sm text-gray-500">{{ $worker->name }}</p>
        </div>

        <form
            method="POST"
            action="{{ route('admin.contacts.workers.time_off.update', [$worker->id, $timeOff->id]) }}"
            class="wto-form rounded-lg border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900"
        >
            @csrf
            @method('PUT')

            <p class="wto-help">Leave both times blank for whole days, or set them for part of a single date.</p>

            <div class="wto-grid">
                <div><label for="start_date">From</label><input id="start_date" type="date" name="time_off[0][start_date]" value="{{ $entry['start_date'] ?? '' }}" required></div>
                <div><label for="end_date">To</label><input id="end_date" type="date" name="time_off[0][end_date]" value="{{ $entry['end_date'] ?? '' }}" required></div>
                <div><label for="type">Type</label><select id="type" name="time_off[0][type]" required>@foreach (['annual_leave' => 'Annual leave', 'sick' => 'Sick', 'personal' => 'Personal', 'other' => 'Other'] as $value => $label)<option value="{{ $value }}" @selected(($entry['type'] ?? '') === $value)>{{ $label }}</option>@endforeach</select></div>
                <div><label for="start_time">Part of day — start (optional)</label><input id="start_time" type="time" name="time_off[0][start_time]" value="{{ $entry['start_time'] ?? '' }}"></div>
                <div><label for="end_time">Part of day — end (optional)</label><input id="end_time" type="time" name="time_off[0][end_time]" value="{{ $entry['end_time'] ?? '' }}"></div>
                <div><label for="note">Note (optional)</label><input id="note" type="text" name="time_off[0][note]" maxlength="1000" value="{{ $entry['note'] ?? '' }}"></div>
            </div>

            @foreach (['start_date', 'end_date', 'start_time', 'type'] as $field)
                @error("time_off.0.{$field}") <p class="wto-error" role="alert">{{ $message }}</p> @enderror
            @endforeach

            <div class="wto-actions">
                <button type="submit" form="delete-time-off" class="wto-delete">Delete time off</button>

                <div class="flex items-center gap-2">
                    <a href="{{ $scheduleUrl }}" class="secondary-button">Cancel</a>
                    <button type="submit" class="primary-button">Save changes</button>
                </div>
            </div>
        </form>

        <form
            id="delete-time-off"
            method="POST"
            action="{{ route('admin.contacts.workers.time_off.delete', [$worker->id, $timeOff->id]) }}"
            onsubmit="return confirm('Delete this time off? The time becomes available for appointments again.')"
        >
            @csrf
            @method('DELETE')
        </form>
    </div>
</x-admin::layouts>
