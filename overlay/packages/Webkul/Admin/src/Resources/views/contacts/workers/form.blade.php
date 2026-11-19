{{-- Contacts > Workers > Register / Edit worker (WorkerController). Styles: settings/txn/partials/tc-styles (.tc, scoped). --}}
<x-admin::layouts>
    <x-slot:title>
        {{ $worker ? 'Edit worker' : 'Register worker' }}
    </x-slot>

    @php
        $name = old('name', $worker?->name ?? '');
        $initials = collect(preg_split('/\s+/', trim($name)))->filter()->take(2)->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))->implode('') ?: '?';
        $color = old('calendar_color', $worker?->calendar_color ?? '');
        $active = (bool) old('is_active', $worker?->is_active ?? true);

        $hours = collect($weekdays)->mapWithKeys(function ($dayName, $day) use ($worker) {
            $value = old("work_hours.{$day}", $worker?->work_hours[$day] ?? []);

            return [$day => is_array($value) ? [$value['start'] ?? $value[0] ?? '', $value['end'] ?? $value[1] ?? ''] : ['', '']];
        })->all();

        $timeOffValues = old('time_off', $worker?->timeOffs?->map(fn ($entry) => [
            'start_date' => $entry->start_date?->format('Y-m-d'),
            'end_date' => $entry->end_date?->format('Y-m-d'),
            'start_time' => $entry->start_time,
            'end_time' => $entry->end_time,
            'type' => $entry->type,
            'note' => $entry->note,
        ])->all() ?? []);
        $nextTimeOffIndex = $timeOffValues ? max(array_keys($timeOffValues)) + 1 : 0;
        $timeOffTypes = ['annual_leave' => 'Annual leave', 'sick' => 'Sick', 'personal' => 'Personal', 'other' => 'Other'];
        $error = fn (string $key) => $errors->first($key);
    @endphp

    @include('admin::settings.txn.partials.tc-styles')

    <div class="mx-auto flex w-full max-w-5xl flex-col gap-4">
        <div class="tc tc-top">
            <span class="tc-avatar is-lg" id="worker-avatar" style="{{ $color ? "background: {$color};" : '' }}">{{ $initials }}</span>
            <div class="tc-top-text">
                <span class="tc-crumb">Workers</span>
                <h1>{{ $worker ? $worker->name : 'Register worker' }}</h1>
                <p>{{ $worker ? 'Details, working hours and time off. Changes apply to new suggestions and bookings.' : 'Add a worker who can receive home visits. Times are suggested from their address, hours and services.' }}</p>
            </div>
            @if ($worker)
                <span class="tc-status {{ $worker->is_active ? 'is-on' : 'is-off' }}">{{ $worker->is_active ? 'Active' : 'Inactive' }}</span>
            @endif
            <a href="{{ route('admin.contacts.workers.index') }}" class="secondary-button">Back to workers</a>
        </div>

        <form
            method="POST"
            action="{{ $worker ? route('admin.contacts.workers.update', $worker->id) : route('admin.contacts.workers.store') }}"
            class="tc tc-form"
        >
            @csrf
            @if ($worker)
                @method('PUT')
            @endif

            @if (collect($errors->keys())->diff(['delete'])->isNotEmpty())
                <div class="tc-alert" role="alert">
                    @include('admin::settings.txn.partials.icon', ['name' => 'alert'])
                    <div><strong>Not saved</strong>Check the fields marked below.</div>
                </div>
            @endif

            {{-- Status --}}
            <section class="tc-card {{ $active ? 'tc-guard-on' : 'tc-guard-off' }}" id="status-card">
                <div class="tc-head">
                    <span class="tc-mark">@include('admin::settings.txn.partials.icon', ['name' => 'check'])</span>
                    <div class="tc-title">
                        <h2>Available for new appointments</h2>
                        <p>Off: the worker gets no new suggested times or bookings. Their existing appointments stay.</p>
                    </div>
                    <input type="hidden" name="is_active" value="0">
                    <label class="tc-toggle">
                        <input id="is_active" type="checkbox" name="is_active" value="1" @checked($active)>
                        <span class="tc-toggle-track"></span>
                        <span id="is-active-text">{{ $active ? 'Active' : 'Inactive' }}</span>
                    </label>
                </div>
            </section>

            {{-- Details --}}
            <section class="tc-card is-plain">
                @include('admin::settings.txn.partials.card-head', ['icon' => 'user', 'title' => 'Worker details', 'text' => 'How staff and customers reach this worker.'])
                <div class="tc-body">
                    <div class="tc-grid">
                        <div class="tc-field">
                            <label for="name" class="tc-label">Name</label>
                            <input id="name" name="name" type="text" value="{{ $name }}" required maxlength="255" class="tc-input" placeholder="e.g. Sam Rivera">
                            @if ($error('name')) <p class="tc-help" style="color: var(--tc-bad);" role="alert">{{ $error('name') }}</p> @endif
                        </div>

                        <div class="tc-field">
                            <label for="phone" class="tc-label">Phone</label>
                            <input id="phone" name="phone" type="text" value="{{ old('phone', $worker?->phone ?? '') }}" maxlength="50" class="tc-input" placeholder="e.g. (713) 555-0101">
                            @if ($error('phone')) <p class="tc-help" style="color: var(--tc-bad);" role="alert">{{ $error('phone') }}</p> @endif
                        </div>

                        <div class="tc-field">
                            <label for="email" class="tc-label">Email</label>
                            <input id="email" name="email" type="email" value="{{ old('email', $worker?->email ?? '') }}" maxlength="255" class="tc-input" placeholder="name@company.com">
                            @if ($error('email')) <p class="tc-help" style="color: var(--tc-bad);" role="alert">{{ $error('email') }}</p> @endif
                        </div>

                        <div class="tc-field">
                            <label for="priority" class="tc-label">Priority</label>
                            <input id="priority" name="priority" type="number" min="1" max="65535" value="{{ old('priority', $worker?->priority ?? 1) }}" required class="tc-input is-short">
                            <p class="tc-help">1 is preferred; priority only breaks scheduling ties.</p>
                            @if ($error('priority')) <p class="tc-help" style="color: var(--tc-bad);" role="alert">{{ $error('priority') }}</p> @endif
                        </div>

                        <div class="tc-field tc-wide">
                            <label for="notes" class="tc-label">Notes <span class="tc-sublabel">(optional)</span></label>
                            <textarea id="notes" name="notes" rows="3" maxlength="2000" class="tc-input">{{ old('notes', $worker?->notes ?? '') }}</textarea>
                            @if ($error('notes')) <p class="tc-help" style="color: var(--tc-bad);" role="alert">{{ $error('notes') }}</p> @endif
                        </div>
                    </div>
                </div>
            </section>

            {{-- Location --}}
            <section class="tc-card is-plain">
                @include('admin::settings.txn.partials.card-head', ['icon' => 'home', 'title' => 'Where the day starts and ends', 'text' => 'Drive times to customers are worked out from this address.'])
                <div class="tc-body">
                    <div class="tc-field">
                        <label for="start_address" class="tc-label">Start and end address</label>
                        <input id="start_address" name="start_address" type="text" value="{{ old('start_address', $worker?->start_address ?? '') }}" required maxlength="255" placeholder="Worker's home or office" class="tc-input">
                        <p class="tc-help">
                            Krayin looks up the map location when you save.
                            @if ($worker?->home_latitude !== null && $worker?->home_longitude !== null)
                                <span class="tc-chip is-muted">@include('admin::settings.txn.partials.icon', ['name' => 'map', 'size' => 12]) {{ number_format($worker->home_latitude, 5) }}, {{ number_format($worker->home_longitude, 5) }}</span>
                            @endif
                        </p>
                        @if ($error('start_address')) <p class="tc-help" style="color: var(--tc-bad);" role="alert">{{ $error('start_address') }}</p> @endif
                    </div>
                </div>
            </section>

            {{-- Work --}}
            <section class="tc-card is-plain">
                @include('admin::settings.txn.partials.card-head', ['icon' => 'wrench', 'title' => 'Work', 'text' => 'Which jobs this worker is offered for, and how many a day.'])
                <div class="tc-body">
                    <div class="tc-grid">
                        <div class="tc-field">
                            <label for="services_text" class="tc-label">Services they can do</label>
                            <textarea id="services_text" name="services_text" rows="3" maxlength="5000" placeholder="One service per line, e.g. Gutter" class="tc-input">{{ old('services_text', implode("\n", $worker?->services ?? [])) }}</textarea>
                            <div class="tc-chips" id="service-chips"></div>
                            <p class="tc-help">Leave blank if this worker does every service.</p>
                            @if ($error('services_text')) <p class="tc-help" style="color: var(--tc-bad);" role="alert">{{ $error('services_text') }}</p> @endif
                        </div>

                        <div class="tc-field" style="gap: 18px;">
                            <div class="tc-field">
                                <label for="max_visits_per_day" class="tc-label">Max visits per day</label>
                                <div class="tc-unit">
                                    <input id="max_visits_per_day" name="max_visits_per_day" type="number" min="1" max="65535" value="{{ old('max_visits_per_day', $worker?->max_visits_per_day ?? '') }}" class="tc-input" placeholder="No cap">
                                    <span>visits</span>
                                </div>
                                <p class="tc-help">Leave blank for no daily cap.</p>
                                @if ($error('max_visits_per_day')) <p class="tc-help" style="color: var(--tc-bad);" role="alert">{{ $error('max_visits_per_day') }}</p> @endif
                            </div>

                            <div class="tc-field">
                                <label for="calendar_color" class="tc-label">Calendar colour <span class="tc-sublabel">(optional)</span></label>
                                <div class="tc-colorpick">
                                    <input type="color" id="calendar_color_pick" value="{{ preg_match('/^#[0-9A-Fa-f]{6}$/', $color) ? $color : '#0E90D9' }}" aria-label="Pick a colour">
                                    <input id="calendar_color" name="calendar_color" type="text" pattern="#[0-9A-Fa-f]{6}" maxlength="7" placeholder="#2563EB" value="{{ $color }}" class="tc-input">
                                </div>
                                <p class="tc-help">How this worker's visits look on the calendars and maps.</p>
                                @if ($error('calendar_color')) <p class="tc-help" style="color: var(--tc-bad);" role="alert">{{ $error('calendar_color') }}</p> @endif
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            {{-- Weekly hours --}}
            <section class="tc-card is-plain">
                @include('admin::settings.txn.partials.card-head', ['icon' => 'clock', 'title' => 'Weekly work hours', 'text' => 'Leave every day blank to use the company hours. In a custom week, a blank day is a day off.'])
                <div class="tc-body">
                    @include('admin::settings.txn.partials.week-hours', ['field' => 'work_hours', 'hours' => $hours, 'allBlankLabel' => 'Company hours'])
                </div>
            </section>

            {{-- Time off --}}
            <section class="tc-card is-plain">
                @include('admin::settings.txn.partials.card-head', [
                    'icon' => 'beach',
                    'title' => 'Time off',
                    'text' => 'Whole days, or part of one day. No visits are suggested in these times.',
                    'aside' => '<button id="add-time-off" type="button" class="tc-add">+ Add time off</button>',
                ])
                <div class="tc-body">
                    @if ($error('time_off')) <p class="tc-help" style="color: var(--tc-bad);" role="alert">{{ $error('time_off') }}</p> @endif
                    <p class="tc-empty" id="time-off-empty" @if ($timeOffValues) style="display: none;" @endif>No time off planned.</p>

                    <div id="time-off-list" class="tc-rows">
                        @foreach ($timeOffValues as $index => $entry)
                            <div class="time-off-entry tc-entry">
                                <div class="tc-field"><label class="tc-label">From</label><input type="date" name="time_off[{{ $index }}][start_date]" value="{{ $entry['start_date'] ?? '' }}" class="tc-input"></div>
                                <div class="tc-field"><label class="tc-label">To</label><input type="date" name="time_off[{{ $index }}][end_date]" value="{{ $entry['end_date'] ?? '' }}" class="tc-input"></div>
                                <div class="tc-field"><label class="tc-label">Type</label><select name="time_off[{{ $index }}][type]" class="tc-input"><option value="">Choose type</option>@foreach ($timeOffTypes as $value => $label)<option value="{{ $value }}" @selected(($entry['type'] ?? '') === $value)>{{ $label }}</option>@endforeach</select></div>
                                <div class="tc-field"><label class="tc-label">Part of day: start <span class="tc-sublabel">(optional)</span></label><input type="time" name="time_off[{{ $index }}][start_time]" value="{{ $entry['start_time'] ?? '' }}" class="tc-input"></div>
                                <div class="tc-field"><label class="tc-label">Part of day: end <span class="tc-sublabel">(optional)</span></label><input type="time" name="time_off[{{ $index }}][end_time]" value="{{ $entry['end_time'] ?? '' }}" class="tc-input"></div>
                                <div class="tc-field"><label class="tc-label">Note <span class="tc-sublabel">(optional)</span></label><input type="text" name="time_off[{{ $index }}][note]" maxlength="1000" value="{{ $entry['note'] ?? '' }}" class="tc-input"></div>
                                <div class="tc-entry-foot"><button type="button" class="remove-time-off tc-btn is-danger">Remove</button></div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </section>

            @include('admin::settings.txn.partials.savebar', [
                'noReset' => true,
                'cancelUrl' => route('admin.contacts.workers.index'),
                'saveLabel' => $worker ? 'Save changes' : 'Register worker',
                'idleText' => $worker ? 'No changes yet' : 'Fill in the details, then register',
            ])
        </form>

        {{-- Delete: only a worker who was never used (WorkerController::destroy checks again). --}}
        @if ($worker && bouncer()->hasPermission('contacts.workers.delete'))
            @php
                $usedBy = collect([
                    $usage['activities'] ? $usage['activities'].' '.\Illuminate\Support\Str::plural('appointment', $usage['activities']) : null,
                    $usage['links'] ? $usage['links'].' '.\Illuminate\Support\Str::plural('booking link', $usage['links']) : null,
                ])->filter()->implode(' and ');
            @endphp
            <section class="tc tc-card tc-guard-off" id="delete-worker">
                <div class="tc-head">
                    <span class="tc-mark" style="background: var(--tc-bad-soft); color: var(--tc-bad);">@include('admin::settings.txn.partials.icon', ['name' => 'alert'])</span>
                    <div class="tc-title">
                        <h2>Delete this worker</h2>
                        @if ($usedBy)
                            <p>{{ $worker->name }} can't be deleted: {{ $usedBy }} use them. To stop new bookings, turn off "Available for new appointments" above; their history stays.</p>
                        @else
                            <p>{{ $worker->name }} has no appointments and no booking link offers them, so they can be deleted. Their time off is deleted too. This can't be undone.</p>
                        @endif
                    </div>
                    <form method="POST" action="{{ route('admin.contacts.workers.delete', $worker->id) }}"
                        onsubmit="return confirm(@js('Delete '.$worker->name.' for good? This cannot be undone.'))">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="tc-btn is-danger" @disabled($usedBy !== '')>Delete worker</button>
                    </form>
                </div>
                @if ($errors->has('delete'))
                    <p class="tc-problem is-failed" role="alert"><strong>Not deleted.</strong> {{ $errors->first('delete') }}</p>
                @endif
            </section>
        @endif
    </div>

    <template id="time-off-template">
        <div class="time-off-entry tc-entry">
            <div class="tc-field"><label class="tc-label">From</label><input type="date" data-field="start_date" class="tc-input"></div>
            <div class="tc-field"><label class="tc-label">To</label><input type="date" data-field="end_date" class="tc-input"></div>
            <div class="tc-field"><label class="tc-label">Type</label><select data-field="type" class="tc-input"><option value="">Choose type</option>@foreach ($timeOffTypes as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></div>
            <div class="tc-field"><label class="tc-label">Part of day: start <span class="tc-sublabel">(optional)</span></label><input type="time" data-field="start_time" class="tc-input"></div>
            <div class="tc-field"><label class="tc-label">Part of day: end <span class="tc-sublabel">(optional)</span></label><input type="time" data-field="end_time" class="tc-input"></div>
            <div class="tc-field"><label class="tc-label">Note <span class="tc-sublabel">(optional)</span></label><input type="text" data-field="note" maxlength="1000" class="tc-input"></div>
            <div class="tc-entry-foot"><button type="button" class="remove-time-off tc-btn is-danger">Remove</button></div>
        </div>
    </template>

    @pushOnce('scripts')
        <script>
            (() => {
                let nextIndex = {{ $nextTimeOffIndex }};
                const list = document.getElementById('time-off-list');
                const empty = document.getElementById('time-off-empty');
                const showEmpty = () => { empty.style.display = list.children.length ? 'none' : ''; };

                document.addEventListener('click', (event) => {
                    if (!(event.target instanceof Element)) {
                        return;
                    }

                    if (event.target.closest('#add-time-off')) {
                        const template = document.getElementById('time-off-template');
                        const entry = template.content.firstElementChild.cloneNode(true);
                        entry.querySelectorAll('[data-field]').forEach((input) => {
                            input.name = `time_off[${nextIndex}][${input.dataset.field}]`;
                        });
                        nextIndex += 1;
                        list.appendChild(entry);
                        showEmpty();
                        entry.querySelector('input').focus();

                        return;
                    }

                    const removeButton = event.target.closest('.remove-time-off');

                    if (removeButton) {
                        removeButton.closest('.time-off-entry')?.remove();
                        showEmpty();
                    }
                });

                // Active switch: card colour and text follow it.
                const active = document.getElementById('is_active');
                active.addEventListener('change', () => {
                    document.getElementById('status-card').className = `tc-card ${active.checked ? 'tc-guard-on' : 'tc-guard-off'}`;
                    document.getElementById('is-active-text').textContent = active.checked ? 'Active' : 'Inactive';
                });

                // Avatar initials and colour follow the name and the colour.
                const avatar = document.getElementById('worker-avatar');
                const nameField = document.getElementById('name');
                const pick = document.getElementById('calendar_color_pick');
                const colorText = document.getElementById('calendar_color');
                const refreshAvatar = () => {
                    avatar.textContent = nameField.value.trim().split(/\s+/).filter(Boolean).slice(0, 2).map((part) => part[0].toUpperCase()).join('') || '?';
                    avatar.style.background = /^#[0-9A-Fa-f]{6}$/.test(colorText.value) ? colorText.value : '';
                };
                nameField.addEventListener('input', refreshAvatar);
                pick.addEventListener('input', () => { colorText.value = pick.value.toUpperCase(); refreshAvatar(); });
                colorText.addEventListener('input', () => {
                    if (/^#[0-9A-Fa-f]{6}$/.test(colorText.value)) {
                        pick.value = colorText.value;
                    }
                    refreshAvatar();
                });

                // Services as chips.
                const services = document.getElementById('services_text');
                const chips = document.getElementById('service-chips');
                const refreshServices = () => {
                    const items = services.value.split(/\n/).map((line) => line.trim()).filter(Boolean);
                    chips.replaceChildren(...(items.length ? items : ['All services']).map((text) => Object.assign(document.createElement('span'), {
                        className: items.length ? 'tc-chip' : 'tc-chip is-muted',
                        textContent: text,
                    })));
                };
                services.addEventListener('input', refreshServices);
                refreshServices();
            })();
        </script>
    @endPushOnce
</x-admin::layouts>
