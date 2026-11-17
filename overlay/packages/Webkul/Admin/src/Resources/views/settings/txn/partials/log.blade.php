{{-- TXN Settings screens: change log (txn_settings_log). Data: $log. --}}
<div class="rounded-lg border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-gray-900">
    <h2 class="text-lg font-semibold dark:text-white">Change log</h2>
    @if ($log->isEmpty())
        <p class="text-sm text-gray-500">No changes yet: everything here is the default.</p>
    @else
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="border-b text-gray-600 dark:border-gray-700 dark:text-gray-300">
                    <tr><th class="py-2 pr-3">When</th><th class="py-2 pr-3">Who</th><th class="py-2 pr-3">Setting</th><th class="py-2">Change</th></tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-800">
                    @foreach ($log as $entry)
                        <tr>
                            <td class="py-2 pr-3 align-top text-gray-500">{{ $entry->created_at }}</td>
                            <td class="py-2 pr-3 align-top dark:text-gray-200">{{ $entry->user ?? 'System' }}</td>
                            <td class="py-2 pr-3 align-top dark:text-gray-200">{{ $entry->item }}</td>
                            <td class="py-2 align-top text-gray-600 dark:text-gray-300">{{ $entry->old_value }} → {{ $entry->new_value }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
