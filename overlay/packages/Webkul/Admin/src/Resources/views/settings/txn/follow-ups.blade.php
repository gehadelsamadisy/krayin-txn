{{-- Settings > TXN Settings > Follow-ups (TxnSettingsController). --}}
<x-admin::layouts>
    <x-slot:title>{{ $title }}</x-slot>

    @php
        $input = 'w-32 rounded border border-gray-300 px-2 py-1.5 dark:border-gray-700 dark:bg-gray-800 dark:text-white';
        $hours = [
            'reminder_after_hours' => 'Hours after the booking link was emailed, if the customer hasn\'t booked: a reminder with the times still open.',
            'final_after_hours' => 'Hours after the booking link: a last "if you still need help, call or text us" email.',
            'lost_after_hours' => 'Hours after the booking link: the lead is marked Lost (only leads still in Contacted).',
            'min_hours_before_slot' => 'The reminder leaves out times sooner than this, so the customer has time to book them.',
        ];
    @endphp

    <div class="mx-auto flex w-full max-w-4xl flex-col gap-4">
        @include('admin::settings.txn.partials.top')

        <form method="POST" action="{{ route('admin.settings.txn.follow_ups.update') }}" class="flex flex-col gap-5 rounded-lg border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-gray-900">
            @csrf
            @method('PUT')

            <p class="text-sm text-gray-500">Follow-ups are sent when someone runs them (the follow-ups command); nothing is sent automatically yet.</p>

            @foreach ($hours as $name => $help)
                @php($key = "follow_ups.{$name}")
                <div>
                    <label for="f-{{ $name }}" class="mb-1 block text-sm font-medium dark:text-gray-200">{{ $fields[$key][2] }}</label>
                    <input id="f-{{ $name }}" type="number" name="{{ $name }}" min="{{ $fields[$key][3]['min'] }}" max="{{ $fields[$key][3]['max'] }}" value="{{ old($name, $values[$key]) }}" class="{{ $input }}" @disabled(! $canEdit)>
                    <p class="mt-1 text-xs text-gray-500">{{ $help }}</p>
                    @include('admin::settings.txn.partials.field-meta', ['key' => $key])
                </div>
            @endforeach

            <div>
                <label for="f-lost_reason" class="mb-1 block text-sm font-medium dark:text-gray-200">{{ $fields['follow_ups.lost_reason'][2] }}</label>
                <input id="f-lost_reason" type="text" name="lost_reason" maxlength="200" value="{{ old('lost_reason', $values['follow_ups.lost_reason']) }}" class="w-full rounded border border-gray-300 px-2 py-1.5 dark:border-gray-700 dark:bg-gray-800 dark:text-white" @disabled(! $canEdit)>
                <p class="mt-1 text-xs text-gray-500">Written on the lead when it is marked Lost this way.</p>
                @include('admin::settings.txn.partials.field-meta', ['key' => 'follow_ups.lost_reason'])
            </div>

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
