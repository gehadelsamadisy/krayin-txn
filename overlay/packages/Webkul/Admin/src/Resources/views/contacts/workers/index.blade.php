<x-admin::layouts>
    <x-slot:title>
        Workers
    </x-slot>

    <div class="flex flex-col gap-4">
        <div class="flex items-center justify-between rounded-lg border border-gray-200 bg-white px-4 py-3 dark:border-gray-800 dark:bg-gray-900">
            <div>
                <h1 class="text-xl font-bold dark:text-white">Workers</h1>
                <p class="text-sm text-gray-500">Manage workers and scheduling availability.</p>
            </div>

            <div class="flex flex-wrap gap-2">
                <a href="{{ route('admin.contacts.workers.routes') }}" class="secondary-button">
                    Routes
                </a>

                @include('admin::contacts.calcom-sync-button')

                @if (bouncer()->hasPermission('contacts.workers.settings'))
                    <a href="{{ route('admin.contacts.workers.settings') }}" class="secondary-button">
                        Company schedule & holidays
                    </a>
                @endif

                @if (bouncer()->hasPermission('contacts.workers.create'))
                    <a href="{{ route('admin.contacts.workers.create') }}" class="primary-button">
                        Register worker
                    </a>
                @endif
            </div>
        </div>

        <div class="overflow-hidden rounded-lg border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="border-b bg-gray-50 text-gray-600 dark:border-gray-800 dark:bg-gray-950 dark:text-gray-300">
                    <tr>
                        <th class="px-4 py-3">Name</th>
                        <th class="px-4 py-3">Start address</th>
                        <th class="px-4 py-3">Services</th>
                        <th class="px-4 py-3">Priority</th>
                        <th class="px-4 py-3">Hours / time off</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3 text-right">Actions</th>
                    </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-800 dark:text-gray-200">
                        @forelse ($workers as $worker)
                            <tr>
                                <td class="px-4 py-3 font-medium">
                                    @if ($worker->calendar_color)
                                        <span class="mr-2 inline-block h-3 w-3 rounded-full align-middle" style="background-color: {{ $worker->calendar_color }}"></span>
                                    @endif
                                    {{ $worker->name }}
                                    <div class="mt-1 text-xs text-gray-500">{{ $worker->phone ?: '—' }} · {{ $worker->email ?: '—' }}</div>
                                </td>
                                <td class="px-4 py-3">{{ $worker->start_address ?: 'Not set' }}</td>
                                <td class="px-4 py-3">{{ $worker->services ? implode(', ', $worker->services) : 'All services' }}</td>
                                <td class="px-4 py-3">{{ $worker->priority }}</td>
                                <td class="px-4 py-3">
                                    @if ($worker->work_hours)
                                        @foreach ([1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'] as $day => $label)
                                            @php($hours = $worker->work_hours[$day] ?? null)
                                            <span class="mr-1 whitespace-nowrap">{{ $label }} {{ $hours ? $hours[0].'–'.$hours[1] : 'Off' }}</span>
                                        @endforeach
                                    @else
                                        Company default
                                    @endif
                                    <div class="mt-1 text-xs text-gray-500">{{ $worker->timeOffs->count() }} time-off entries</div>
                                </td>
                                <td class="px-4 py-3">{{ $worker->is_active ? 'Active' : 'Inactive' }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-right">
                                    <a href="{{ route('admin.contacts.workers.schedule', $worker->id) }}" class="text-blue-600 hover:underline">Schedule</a>

                                    @if (bouncer()->hasPermission('contacts.workers.edit'))
                                        <a href="{{ route('admin.contacts.workers.edit', $worker->id) }}" class="ml-3 text-blue-600 hover:underline">Edit</a>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-4 py-8 text-center text-gray-500">No workers registered yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div>{{ $workers->links() }}</div>
    </div>
</x-admin::layouts>
