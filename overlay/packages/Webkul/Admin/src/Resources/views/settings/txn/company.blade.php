{{-- Settings > TXN Settings > Company & sender (TxnSettingsController). --}}
<x-admin::layouts>
    <x-slot:title>{{ $title }}</x-slot>

    @php
        $input = 'w-full rounded border border-gray-300 px-2 py-1.5 dark:border-gray-700 dark:bg-gray-800 dark:text-white';
        $rows = [
            'company' => ['lead_messages.company', 'Shown in every customer email and booking confirmation.'],
            'rep_name' => ['lead_messages.rep_name', 'The person customers hear from ("This is ... with ...") and the signature.'],
            'rep_cell' => ['lead_messages.rep_cell', 'Customers are asked to call or text this number. Leave empty to say "give us a call".'],
            'office_phone' => ['lead_messages.office_phone', 'Shown in the email signature.'],
            'sms_signature' => ['sms.signature', 'Who the customer texts are from: {signature} in the text message ("Hi Jane, this is ...").'],
        ];
    @endphp

    <div class="mx-auto flex w-full max-w-4xl flex-col gap-4">
        @include('admin::settings.txn.partials.top')

        <form method="POST" action="{{ route('admin.settings.txn.company.update') }}" class="flex flex-col gap-5 rounded-lg border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-gray-900">
            @csrf
            @method('PUT')

            @foreach ($rows as $name => [$key, $help])
                <div>
                    <label for="f-{{ $name }}" class="mb-1 block text-sm font-medium dark:text-gray-200">{{ $fields[$key][2] }}</label>
                    <input id="f-{{ $name }}" type="text" name="{{ $name }}" maxlength="{{ $fields[$key][3]['max'] }}" value="{{ old($name, $values[$key]) }}" class="{{ $input }}" @disabled(! $canEdit)>
                    <p class="mt-1 text-xs text-gray-500">{{ $help }}</p>
                    @include('admin::settings.txn.partials.field-meta', ['key' => $key])
                </div>
            @endforeach

            <div>
                <label for="f-accent" class="mb-1 block text-sm font-medium dark:text-gray-200">{{ $fields['lead_messages.accent'][2] }}</label>
                <div class="flex items-center gap-3">
                    <input id="f-accent" type="color" name="accent" value="{{ old('accent', $values['lead_messages.accent']) }}" class="h-9 w-16 rounded border border-gray-300" @disabled(! $canEdit)>
                    <span class="text-sm text-gray-500">The top line and the button of the emails.</span>
                </div>
                @include('admin::settings.txn.partials.field-meta', ['key' => 'lead_messages.accent'])
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
