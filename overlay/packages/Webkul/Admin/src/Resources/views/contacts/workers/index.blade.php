{{-- Contacts > Workers (WorkerController). Styles: settings/txn/partials/tc-styles (.tc, scoped). --}}
<x-admin::layouts>
    <x-slot:title>
        Workers
    </x-slot>

    @php
        $days = [1 => 'M', 2 => 'T', 3 => 'W', 4 => 'T', 5 => 'F', 6 => 'S', 7 => 'S'];
        $dayNames = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];
        $initials = fn ($name) => collect(preg_split('/\s+/', trim((string) $name)))->filter()->take(2)->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))->implode('') ?: '?';
        $activeCount = $workers->getCollection()->where('is_active', true)->count();
        $total = method_exists($workers, 'total') ? $workers->total() : $workers->count();
    @endphp

    @include('admin::settings.txn.partials.tc-styles')

    <div class="flex flex-col gap-4">
        <div class="tc tc-top">
            <span class="tc-top-icon">@include('admin::settings.txn.partials.icon', ['name' => 'users', 'size' => 22])</span>
            <div class="tc-top-text">
                <span class="tc-crumb">Contacts</span>
                <h1>Workers</h1>
                <p>The people who do the home visits: their start address, services, hours and time off decide which times customers are offered.</p>
                <div class="tc-stats">
                    <span class="tc-stat"><b>{{ $total }}</b> {{ $total === 1 ? 'worker' : 'workers' }}</span>
                    <span class="tc-stat"><b>{{ $activeCount }}</b> active on this page</span>
                    @if ($workers->getCollection()->count() - $activeCount)
                        <span class="tc-stat"><b>{{ $workers->getCollection()->count() - $activeCount }}</b> inactive</span>
                    @endif
                </div>
            </div>

            <div class="tc-top-actions">
                <a href="{{ route('admin.contacts.workers.routes') }}" class="secondary-button">Routes</a>

                @include('admin::contacts.calcom-sync-button')

                @if (bouncer()->hasPermission('contacts.workers.settings'))
                    <a href="{{ route('admin.contacts.workers.settings') }}" class="secondary-button">Company schedule & holidays</a>
                @endif

                @if (bouncer()->hasPermission('contacts.workers.create'))
                    <a href="{{ route('admin.contacts.workers.create') }}" class="primary-button">Register worker</a>
                @endif
            </div>
        </div>

        <section class="tc tc-card is-plain">
            @if ($workers->isEmpty())
                <div class="tc-empty-state">
                    @include('admin::settings.txn.partials.icon', ['name' => 'users', 'size' => 34])
                    <strong>No workers registered yet</strong>
                    Register a worker so customers can be offered visit times.
                </div>
            @else
                <div class="tc-table-wrap">
                    <table class="tc-table">
                        <thead>
                            <tr>
                                <th>Worker</th>
                                <th>Starts from</th>
                                <th>Services</th>
                                <th>Priority</th>
                                <th>Week</th>
                                <th>Status</th>
                                <th class="tc-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($workers as $worker)
                                <tr class="{{ $worker->is_active ? '' : 'is-inactive' }}">
                                    <td>
                                        <div class="tc-who">
                                            <span class="tc-avatar" style="{{ $worker->calendar_color ? "background: {$worker->calendar_color};" : '' }}">{{ $initials($worker->name) }}</span>
                                            <div>
                                                <strong>{{ $worker->name }}</strong>
                                                <span class="tc-sub">{{ $worker->phone ?: 'No phone' }} · {{ $worker->email ?: 'No email' }}</span>
                                            </div>
                                        </div>
                                    </td>
                                    <td><span class="tc-sub" style="color: var(--tc-ink); max-width: 220px;">{{ $worker->start_address ?: 'Not set' }}</span></td>
                                    <td>
                                        <div class="tc-chips">
                                            @forelse ((array) $worker->services as $service)
                                                <span class="tc-chip">{{ $service }}</span>
                                            @empty
                                                <span class="tc-chip is-muted">All services</span>
                                            @endforelse
                                        </div>
                                    </td>
                                    <td><span class="tc-prio {{ (int) $worker->priority === 1 ? 'is-top' : '' }}">{{ $worker->priority }}</span></td>
                                    <td>
                                        @if ($worker->work_hours)
                                            <span class="tc-week">
                                                @foreach ($days as $day => $letter)
                                                    @php
                                                        $dayHours = $worker->work_hours[$day] ?? null;
                                                        $dayTitle = $dayNames[$day].': '.($dayHours ? ($dayHours[0] ?? $dayHours['start'] ?? '').'–'.($dayHours[1] ?? $dayHours['end'] ?? '') : 'off');
                                                    @endphp
                                                    <span class="tc-day {{ $dayHours ? '' : 'is-off' }}" title="{{ $dayTitle }}">{{ $letter }}</span>
                                                @endforeach
                                            </span>
                                        @else
                                            <span class="tc-chip is-muted">Company hours</span>
                                        @endif
                                        @if ($worker->timeOffs->count())
                                            <span class="tc-sub" style="margin-top: 4px;">{{ $worker->timeOffs->count() }} time-off {{ $worker->timeOffs->count() === 1 ? 'entry' : 'entries' }}</span>
                                        @endif
                                    </td>
                                    <td><span class="tc-status {{ $worker->is_active ? 'is-on' : 'is-off' }}">{{ $worker->is_active ? 'Active' : 'Inactive' }}</span></td>
                                    <td class="tc-right">
                                        <div style="display: inline-flex; gap: 6px;">
                                            <a href="{{ route('admin.contacts.workers.schedule', $worker->id) }}" class="tc-btn">@include('admin::settings.txn.partials.icon', ['name' => 'calendar', 'size' => 14]) Schedule</a>

                                            @if (bouncer()->hasPermission('contacts.workers.edit'))
                                                <a href="{{ route('admin.contacts.workers.edit', $worker->id) }}" class="tc-btn">@include('admin::settings.txn.partials.icon', ['name' => 'pen', 'size' => 14]) Edit</a>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        <div>{{ $workers->links() }}</div>
    </div>
</x-admin::layouts>
