{{-- Settings > TXN Settings > Stage rules (TxnStageRulesController). Uses only
     classes already in Krayin's compiled CSS (same as the worker settings page). --}}
<x-admin::layouts>
    <x-slot:title>
        Stage rules
    </x-slot>

    @php
        $canEdit = bouncer()->hasPermission('settings.txn.stage_rules.edit');
    @endphp

    <div class="mx-auto flex w-full max-w-4xl flex-col gap-4">
        <div class="flex items-center justify-between rounded-lg border border-gray-200 bg-white px-4 py-3 dark:border-gray-800 dark:bg-gray-900">
            <div>
                <h1 class="text-xl font-bold dark:text-white">Stage rules</h1>
                <p class="text-sm text-gray-500">
                    Which stage each automatic move uses. Add, remove, rename or reorder stages in
                    <a href="{{ route('admin.settings.pipelines.index') }}" class="text-blue-600 hover:underline">Settings &gt; Pipelines</a>,
                    then give each new or replaced stage its role here.
                </p>
            </div>
            <a href="{{ route('admin.settings.index') }}" class="secondary-button">Back to settings</a>
        </div>

        @if ($errors->has('roles'))
            <div class="rounded-lg border border-red-200 bg-white p-4 text-sm text-red-600 dark:border-red-900 dark:bg-gray-900" role="alert">
                <p class="font-semibold">Not saved:</p>
                @foreach ($errors->get('roles') as $message)
                    <p>{{ $message }}</p>
                @endforeach
            </div>
        @endif

        @if ($warnings)
            <div class="rounded-lg border border-gray-200 bg-white p-4 text-sm dark:border-gray-800 dark:bg-gray-900">
                <p class="font-semibold text-red-600">Needs attention</p>
                @foreach ($warnings as $warning)
                    <p class="text-gray-600 dark:text-gray-300">• {{ $warning }}</p>
                @endforeach
            </div>
        @endif

        <form method="POST" action="{{ route('admin.settings.txn.stage_rules.update') }}" class="flex flex-col gap-6 rounded-lg border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-gray-900">
            @csrf
            @method('PUT')

            @foreach ($pipelines as $pipeline)
                <section class="flex flex-col gap-3">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <h2 class="text-lg font-semibold dark:text-white">{{ $pipeline->name }}</h2>
                            <p class="text-sm text-gray-500">{{ $pipeline->is_default ? 'Main pipeline: new leads arrive here.' : 'Leads come here only when a rule or staff move them.' }}</p>
                        </div>
                        @if (bouncer()->hasPermission('settings.lead.pipelines.edit'))
                            <a href="{{ route('admin.settings.pipelines.edit', $pipeline->id) }}" class="secondary-button">Edit stages</a>
                        @endif
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm">
                            <thead class="border-b text-gray-600 dark:border-gray-700 dark:text-gray-300">
                                <tr><th class="py-2 pr-3">#</th><th class="py-2 pr-3">Stage</th><th class="py-2 pr-3">Leads</th><th class="py-2">Role</th></tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 dark:divide-gray-800">
                                @foreach ($stages->get($pipeline->id, collect()) as $stage)
                                    @php
                                        $isClosing = in_array($stage->code, $closing, true);
                                        $selected = old("roles.{$stage->id}", $roles[$stage->id] ?? '');
                                    @endphp
                                    <tr>
                                        <td class="py-2 pr-3 text-gray-500">{{ $stage->sort_order }}</td>
                                        <td class="py-2 pr-3 dark:text-gray-200">{{ $stage->name }}</td>
                                        <td class="py-2 pr-3 text-gray-500">{{ $leadCounts[$stage->id] ?? 0 }}</td>
                                        <td class="py-2">
                                            @if ($isClosing)
                                                <span class="text-gray-500">Krayin closing stage ({{ $stage->code === 'won' ? 'won' : 'lost' }})</span>
                                            @else
                                                <select name="roles[{{ $stage->id }}]" class="rounded border border-gray-300 px-2 py-1 dark:border-gray-700 dark:bg-gray-800 dark:text-white" @disabled(! $canEdit) aria-label="Role of {{ $stage->name }}">
                                                    <option value="" @selected($selected === '')>None (staff move leads here by hand)</option>
                                                    @foreach ($roleOptions as $role => [$label])
                                                        <option value="{{ $role }}" @selected($selected === $role)>{{ $label }}</option>
                                                    @endforeach
                                                </select>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>
            @endforeach

            @if ($canEdit)
                <div class="flex justify-end">
                    <button type="submit" class="primary-button">Save stage rules</button>
                </div>
            @endif
        </form>

        <div class="rounded-lg border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-gray-900">
            <h2 class="text-lg font-semibold dark:text-white">What each role does</h2>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-800">
                        @foreach ($roleOptions as $role => [$label, $what])
                            <tr>
                                <td class="py-2 pr-3 font-medium dark:text-gray-200">{{ $label }}</td>
                                <td class="py-2 text-gray-600 dark:text-gray-300">{{ $what }}</td>
                            </tr>
                        @endforeach
                        <tr>
                            <td class="py-2 pr-3 font-medium dark:text-gray-200">None</td>
                            <td class="py-2 text-gray-600 dark:text-gray-300">The automation never moves leads into or out of this stage; staff do it on the board (e.g. Visit Done, Estimate Sent).</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="rounded-lg border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-gray-900">
            <h2 class="text-lg font-semibold dark:text-white">Change log</h2>
            @if ($log->isEmpty())
                <p class="text-sm text-gray-500">No changes yet.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="border-b text-gray-600 dark:border-gray-700 dark:text-gray-300">
                            <tr><th class="py-2 pr-3">When</th><th class="py-2 pr-3">Who</th><th class="py-2 pr-3">Stage</th><th class="py-2">Change</th></tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-800">
                            @foreach ($log as $entry)
                                <tr>
                                    <td class="py-2 pr-3 text-gray-500">{{ $entry->created_at }}</td>
                                    <td class="py-2 pr-3 dark:text-gray-200">{{ $entry->user ?? 'System' }}</td>
                                    <td class="py-2 pr-3 dark:text-gray-200">{{ $entry->item }}</td>
                                    <td class="py-2 text-gray-600 dark:text-gray-300">{{ $entry->old_value }} → {{ $entry->new_value }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
</x-admin::layouts>
