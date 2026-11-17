{{-- Settings > TXN Settings > Services (TxnSettingsController). Rows are added
     with plain JS (same pattern as the holidays list on the worker settings page). --}}
<x-admin::layouts>
    <x-slot:title>{{ $title }}</x-slot>

    @php
        $input = 'w-full rounded border border-gray-300 px-2 py-1 dark:border-gray-700 dark:bg-gray-800 dark:text-white';
        $lines = (array) $values['lead_messages.service_lines'];
        $rows = old('rows', collect((array) $values['lead_triage.services'])->values()->map(fn ($rule, $i) => [
            'order' => $i + 1,
            'keywords' => implode(', ', (array) $rule['match']),
            'category' => $rule['category'],
            'flow' => $rule['flow'] ?? 'standard',
            'line' => $lines[$rule['category']] ?? '',
        ])->all());
        $flows = \Webkul\Admin\Support\Contact\TxnSettings::FLOWS;
    @endphp

    <div class="mx-auto flex w-full max-w-5xl flex-col gap-4">
        @include('admin::settings.txn.partials.top')

        <form method="POST" action="{{ route('admin.settings.txn.services.update') }}" class="flex flex-col gap-5 rounded-lg border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-gray-900">
            @csrf
            @method('PUT')

            <div class="text-sm text-gray-500">
                <p>The lead check reads the service a customer asked for and goes down this list: the <strong>first</strong> service with a keyword in it wins, so put specific ones ("roof replacement") above general ones ("roof").</p>
                <p>No match: the lead goes to Needs Review. <strong>Roof report first</strong> sends roof leads to the roof pipeline before a booking link.</p>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="border-b text-gray-600 dark:border-gray-700 dark:text-gray-300">
                        <tr>
                            <th class="py-2 pr-2">Order</th>
                            <th class="py-2 pr-2">Keywords (comma-separated)</th>
                            <th class="py-2 pr-2">Category</th>
                            <th class="py-2 pr-2">Flow</th>
                            <th class="py-2 pr-2">Line in the booking confirmation</th>
                            <th class="py-2">Remove</th>
                        </tr>
                    </thead>
                    <tbody id="service-rows" class="divide-y divide-gray-200 dark:divide-gray-800">
                        @foreach ($rows as $i => $row)
                            <tr>
                                <td class="py-2 pr-2 align-top"><input type="number" step="any" name="rows[{{ $i }}][order]" value="{{ $row['order'] ?? '' }}" class="{{ $input }}" style="width: 4.5rem;" aria-label="Order" @disabled(! $canEdit)></td>
                                <td class="py-2 pr-2 align-top"><input type="text" name="rows[{{ $i }}][keywords]" value="{{ $row['keywords'] ?? '' }}" class="{{ $input }}" aria-label="Keywords" @disabled(! $canEdit)></td>
                                <td class="py-2 pr-2 align-top"><input type="text" name="rows[{{ $i }}][category]" maxlength="60" value="{{ $row['category'] ?? '' }}" class="{{ $input }}" aria-label="Category" @disabled(! $canEdit)></td>
                                <td class="py-2 pr-2 align-top">
                                    <select name="rows[{{ $i }}][flow]" class="{{ $input }}" aria-label="Flow" @disabled(! $canEdit)>
                                        @foreach ($flows as $flow => $label)
                                            <option value="{{ $flow }}" @selected(($row['flow'] ?? 'standard') === $flow)>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </td>
                                <td class="py-2 pr-2 align-top"><input type="text" name="rows[{{ $i }}][line]" maxlength="500" value="{{ $row['line'] ?? '' }}" class="{{ $input }}" aria-label="Confirmation line" @disabled(! $canEdit)></td>
                                <td class="py-2 align-top text-center"><input type="checkbox" name="rows[{{ $i }}][remove]" value="1" aria-label="Remove" @checked(! empty($row['remove'])) @disabled(! $canEdit)></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($canEdit)
                <div>
                    <button id="add-service" type="button" class="secondary-button">Add service</button>
                </div>
            @endif
            @include('admin::settings.txn.partials.field-meta', ['key' => 'lead_triage.services'])

            <div>
                <label for="f-default-line" class="mb-1 block text-sm font-medium dark:text-gray-200">Line for any other service</label>
                <input id="f-default-line" type="text" name="default_line" maxlength="500" value="{{ old('default_line', $lines['default'] ?? '') }}" class="{{ $input }}" @disabled(! $canEdit)>
                <p class="mt-1 text-xs text-gray-500">Used in the booking confirmation when a service has no line of its own.</p>
                @include('admin::settings.txn.partials.field-meta', ['key' => 'lead_messages.service_lines'])
            </div>

            @if ($canEdit)
                <div class="flex justify-between gap-3">
                    <button type="submit" name="reset" value="all" formnovalidate class="secondary-button" onclick="return confirm('Put the service list and lines back to their defaults?')">Reset this page to defaults</button>
                    <button type="submit" class="primary-button">Save</button>
                </div>
            @endif
        </form>

        @include('admin::settings.txn.partials.log')
    </div>

    @if ($canEdit)
        @pushOnce('scripts')
            <script>
                (() => {
                    const body = document.getElementById('service-rows');
                    const button = document.getElementById('add-service');

                    if (! body || ! button) {
                        return;
                    }

                    button.addEventListener('click', () => {
                        const last = body.querySelector('tr:last-child');
                        const row = last.cloneNode(true);
                        const index = Date.now();

                        row.querySelectorAll('input, select').forEach((field) => {
                            field.name = field.name.replace(/rows\[[^\]]+\]/, `rows[${index}]`);

                            if (field.type === 'checkbox') {
                                field.checked = false;
                            } else if (field.tagName === 'SELECT') {
                                field.selectedIndex = 0;
                            } else {
                                field.value = '';
                            }
                        });

                        row.querySelector('input[name$="[order]"]').value = body.querySelectorAll('tr').length + 1;
                        body.appendChild(row);
                        row.querySelector('input[name$="[keywords]"]').focus();
                    });
                })();
            </script>
        @endPushOnce
    @endif
</x-admin::layouts>
