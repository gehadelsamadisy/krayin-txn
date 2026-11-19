<x-admin::layouts>
    <x-slot:title>
        Send booking link - {{ strip_tags($person->name) }}
    </x-slot>

    @php
        $oldSlots = old('slots', ['']);
        $oldFields = old('fields', []);

        $oldSlots = is_array($oldSlots)
            ? array_values(array_filter($oldSlots, 'is_string'))
            : [''];

        $oldFields = is_array($oldFields)
            ? array_values(array_filter($oldFields, 'is_string'))
            : [];

        // A fresh form ticks every "ask for" box; after a submit the admin's
        // own ticks are kept (also when they unticked everything).
        $askChecked = fn (string $code) => old('_token') === null || in_array($code, $oldFields, true);

        $oldSlots = $oldSlots ?: [''];
        $oldSlotWorkers = array_values((array) old('slot_workers', []));
        $workerNames = $workers->pluck('name', 'id');
    @endphp

    @include('admin::settings.txn.partials.tc-styles')

    <div class="mx-auto flex w-full max-w-4xl flex-col gap-4">
        <div class="tc tc-top">
            <span class="tc-top-icon">@include('admin::settings.txn.partials.icon', ['name' => 'link', 'size' => 22])</span>
            <div class="tc-top-text">
                <span class="tc-crumb">Booking link</span>
                <h1>Send a home-visit booking link</h1>
                <p>
                    For <strong style="color: var(--tc-ink);">{{ $person->name }}</strong>. The customer chooses one of the exact 60-minute times you enter.
                    @if ($lead)
                        Lead: <a href="{{ route('admin.leads.view', $lead->id) }}" style="color: var(--tc-brand); font-weight: 600;">{{ $lead->title }}</a> (the booking goes to this lead only).
                    @endif
                </p>
            </div>
            <a href="{{ $lead ? route('admin.leads.view', $lead->id) : route('admin.contacts.persons.view', $person->id) }}" class="secondary-button">Back</a>
        </div>

        <div class="tc flex flex-col gap-3">
            @if (! $calcomConfigured)
                <div class="tc-box is-warn">@include('admin::settings.txn.partials.icon', ['name' => 'alert'])<div>Cal.com is not configured yet. Add its API key in Settings &gt; TXN Settings &gt; Connections before sending.</div></div>
            @endif

            @if (! $customerEmailEnabled)
                <div class="tc-box is-info">@include('admin::settings.txn.partials.icon', ['name' => 'info'])
                    <div>
                        @if (config('txn.guard_calcom', true))
                            Customer booking-link creation and email are disabled. No link will be created or sent from this form.
                        @else
                            This contact has no email address. Add one to create and send a booking link.
                        @endif
                    </div>
                </div>
            @elseif (! config('txn.guard_calcom', true))
                <div class="tc-box is-warn">@include('admin::settings.txn.partials.icon', ['name' => 'alert'])
                    <div>"Create link" makes a real Cal.com link and shows it here; nothing is emailed until you press "Email this link". The email goes to the customer.</div>
                </div>
            @else
                <div class="tc-box is-info">@include('admin::settings.txn.partials.icon', ['name' => 'shield'])
                    <div>
                        Test mode: authorized for this contact and test recipient only. "Create link" makes a real (hidden) Cal.com link and shows it here; nothing is emailed until you press "Email this link".
                        @if ($singleTestAttempt)
                            Only one email is allowed.
                        @endif
                    </div>
                </div>
            @endif

            @if ($errors->any())
                <div role="alert" class="tc-alert">
                    @include('admin::settings.txn.partials.icon', ['name' => 'alert'])
                    <div>
                        <strong>Not done</strong>
                        @foreach ($errors->all() as $error)
                            <div>{{ $error }}</div>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>

        @if ($draftLink)
            <section class="tc tc-card tc-state-ready">
                @include('admin::settings.txn.partials.card-head', [
                    'icon' => 'check',
                    'title' => 'Link created, not sent yet',
                    'text' => 'Check the email below, edit the text if needed, then send. Creating a new link further down replaces this one.',
                ])
                <div class="tc-body">
                    <div class="tc-linkbox">
                        <input id="draft-link" type="text" readonly value="{{ $draftLink['url'] }}" class="tc-input">
                        <a href="{{ $draftLink['url'] }}" target="_blank" rel="noopener noreferrer" class="tc-btn">Open</a>
                        <button type="button" class="tc-btn" onclick="navigator.clipboard.writeText(document.getElementById('draft-link').value); this.textContent = 'Copied';">Copy</button>
                    </div>

                    <div>
                        <span class="tc-label">Times in this link ({{ $draftLink['timezone'] }})</span>
                        <p class="tc-help">The worker of the time the customer books gets the visit.</p>
                        <p id="draft-times-changed" class="tc-help" style="display: none; color: var(--tc-warn); font-weight: 600;">
                            The times below were changed on the form. Sending the email updates the link to these times.
                        </p>
                        <ul id="draft-times" class="tc-chips" style="margin-top: 6px; list-style: none; padding: 0;">
                            @foreach ($draftLink['slot_workers'] ?? array_fill_keys($draftLink['slots'], null) as $slot => $workerId)
                                <li class="tc-chip">
                                    {{ \Carbon\CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $slot)->format('D M j, g:i A') }}
                                    &mdash; {{ $workerNames[$workerId] ?? 'no worker' }}
                                </li>
                            @endforeach
                        </ul>
                    </div>

                    {{-- The email that goes with the link: check it, edit the text, then send. --}}
                    <form method="POST" action="{{ route('admin.contacts.persons.booking_link.send', $person->id) }}" onsubmit="txnCopySlots(this)" class="tc-form" style="padding-top: 16px; border-top: 1px dashed var(--tc-line);"
                        data-draft-slots="{{ json_encode(collect($draftLink['slot_workers'] ?? [])->map(fn ($w) => (string) $w)->sortKeys()->all()) }}">
                        @csrf
                        {{-- The form's current times (and workers), copied in on submit. --}}
                        <div class="txn-send-slots"></div>

                        <div class="tc-split">
                            <div class="tc-form">
                                <div class="tc-field">
                                    <label for="send-email" class="tc-label">To</label>
                                    <select id="send-email" name="email" required class="tc-input">
                                        @foreach ($emails as $address)
                                            <option value="{{ $address }}">{{ $address }}</option>
                                        @endforeach
                                    </select>
                                </div>

                                <div class="tc-field">
                                    <label for="email-subject" class="tc-label">Subject</label>
                                    <input id="email-subject" name="subject" type="text" required maxlength="200" value="{{ $email['subject'] }}"
                                        data-default="{{ $email['subject'] }}" oninput="txnEmailPreview()" class="tc-input">
                                </div>

                                <div class="tc-field">
                                    <label for="email-intro" class="tc-label">Message <span class="tc-sublabel">(before the times)</span></label>
                                    <textarea id="email-intro" name="intro" rows="5" required maxlength="3000"
                                        data-default="{{ $email['intro'] }}" oninput="txnEmailPreview()" class="tc-input">{{ $email['intro'] }}</textarea>
                                </div>

                                <div class="tc-field">
                                    <label for="email-closing" class="tc-label">Closing note <span class="tc-sublabel">(after the button; can be empty)</span></label>
                                    <textarea id="email-closing" name="closing" rows="3" maxlength="3000"
                                        data-default="{{ $email['closing'] }}" oninput="txnEmailPreview()" class="tc-input">{{ $email['closing'] }}</textarea>
                                </div>

                                <p class="tc-note">
                                    @include('admin::settings.txn.partials.icon', ['name' => 'info', 'size' => 15])
                                    <span>
                                        The greeting, the times, the button and the signature are added automatically, so they always match the link
                                        (their wording is in Settings &gt; TXN Settings &gt; Emails). Put **two stars** around words to make them bold.
                                        <button type="button" class="tc-link" onclick="txnEmailReset()">Reset the text</button>
                                    </span>
                                </p>

                                <button type="submit" class="primary-button" style="align-self: flex-start;" @disabled(! $emails || ! $customerEmailEnabled)>Email this link</button>
                            </div>

                            <div class="tc-field">
                                <span class="tc-preview-label">Preview</span>
                                <iframe id="email-preview" title="Email preview" srcdoc="{{ $emailPreview }}" onload="window.txnEmailPreview && txnEmailPreview()" class="tc-frame" style="height: 640px;"></iframe>
                            </div>
                        </div>
                    </form>
                </div>
            </section>
        @endif

        <form method="POST" action="{{ route('admin.contacts.persons.booking_link.create_link', $person->id) }}" class="tc tc-form">
            @csrf

            @if ($lead)
                <input type="hidden" name="lead_id" value="{{ $lead->id }}">
            @endif

            {{-- Who --}}
            <section class="tc-card is-plain">
                @include('admin::settings.txn.partials.card-head', ['icon' => 'mail', 'title' => 'Customer', 'text' => 'Where the link goes, and the time zone of the times.'])
                <div class="tc-body">
                    <div class="tc-grid">
                        <div class="tc-field">
                            <label for="email" class="tc-label">Customer email</label>
                            @if ($emails)
                                <select id="email" name="email" required class="tc-input">
                                    @foreach ($emails as $email)
                                        <option value="{{ $email }}" @selected(old('email', $emails[0]) === $email)>{{ $email }}</option>
                                    @endforeach
                                </select>
                            @else
                                <p class="tc-help" style="color: var(--tc-bad);">This Person has no valid email address. Add one before sending a booking link.</p>
                            @endif
                        </div>

                        <div class="tc-field">
                            <label for="timezone" class="tc-label">Time zone for these times</label>
                            <input id="timezone" name="timezone" type="text" required value="{{ old('timezone', $defaultTimezone) }}" placeholder="{{ $defaultTimezone }}" class="tc-input">
                            <p class="tc-help">An IANA time zone. The company's zone (Settings &gt; TXN Settings &gt; Service area &amp; scheduling): {{ $defaultTimezone }}.</p>
                        </div>
                    </div>
                </div>
            </section>

            {{-- Suggest times --}}
            <section class="tc-card is-plain">
                @include('admin::settings.txn.partials.card-head', ['icon' => 'sparkle', 'title' => 'Suggest times automatically', 'text' => 'Picks the best times for this customer from the workers\' schedules and fills them in below. Nothing is created in Cal.com and nothing is sent.'])
                <div class="tc-body">
                    <div class="tc-grid">
                        <div class="tc-field">
                            <label for="suggest-address" class="tc-label">Test address <span class="tc-sublabel">(optional)</span></label>
                            <input id="suggest-address" type="text" placeholder="Contact's address, or e.g. 123 Main St, Katy, TX 77494" class="tc-input">
                        </div>

                        <div class="tc-field">
                            <label for="suggest-now" class="tc-label">Pretend "now" <span class="tc-sublabel">(optional, {{ \Webkul\Admin\Support\Contact\TxnSettings::timezoneLabel() }})</span></label>
                            <input id="suggest-now" type="datetime-local" class="tc-input">
                        </div>

                        @if ($serviceTypes->isNotEmpty())
                            <div class="tc-field">
                                <label for="suggest-service" class="tc-label">Service needed <span class="tc-sublabel">(optional)</span></label>
                                <select id="suggest-service" class="tc-input">
                                    <option value="">No service specified</option>
                                    @foreach ($serviceTypes as $service)
                                        <option value="{{ $service }}">{{ $service }}</option>
                                    @endforeach
                                </select>
                            </div>
                        @endif
                    </div>

                    <button type="button" id="suggest-button" onclick="suggestBookingSlots()" class="tc-test" style="align-self: flex-start;">
                        @include('admin::settings.txn.partials.icon', ['name' => 'sparkle', 'size' => 14]) Suggest times
                    </button>

                    <div id="suggest-result" class="tc-suggest"></div>
                </div>
            </section>

            {{-- Times --}}
            <section class="tc-card is-plain">
                @include('admin::settings.txn.partials.card-head', [
                    'icon' => 'calendar',
                    'title' => 'Appointment times',
                    'text' => '60 minutes each, starting on the hour. Each time has its own worker: the customer picks a time and that time\'s worker gets the visit.',
                ])
                <div class="tc-body">
                    @if ($workers->isEmpty())
                        <div class="tc-box is-bad">@include('admin::settings.txn.partials.icon', ['name' => 'alert'])<div>No active worker with a geocoded start address is available. Register and locate a worker first.</div></div>
                    @endif

                    <div id="booking-slots" class="tc-slots">
                        @foreach ($oldSlots as $index => $slot)
                            <div class="booking-slot-row tc-slot">
                                <span class="tc-slot-num" aria-hidden="true"></span>
                                <input type="datetime-local" name="slots[]" value="{{ $slot }}" step="3600" required class="tc-input" aria-label="Time">
                                <select name="slot_workers[]" required class="tc-input" aria-label="Worker">
                                    <option value="">Worker</option>
                                    @foreach ($workers as $worker)
                                        <option value="{{ $worker->id }}" @selected((string) ($oldSlotWorkers[$index] ?? '') === (string) $worker->id)>{{ $worker->name }}</option>
                                    @endforeach
                                </select>
                                <button type="button" onclick="removeBookingSlot(this)" class="tc-btn is-danger">Remove</button>
                            </div>
                        @endforeach
                    </div>
                    @error('slot_workers') <p class="tc-help" style="color: var(--tc-bad);" role="alert">{{ $message }}</p> @enderror

                    <button type="button" onclick="addBookingSlot()" class="tc-add">@include('admin::settings.txn.partials.icon', ['name' => 'plus', 'size' => 15]) Add another time</button>
                </div>
            </section>

            {{-- Ask for --}}
            <section class="tc-card is-plain">
                @include('admin::settings.txn.partials.card-head', ['icon' => 'list', 'title' => 'Ask the customer for', 'text' => 'Missing or unusable details are ticked. Answers replace the stored values when the booking syncs. Name and email are always on the booking page.'])
                <div class="tc-body">
                    @if ($addressPlan['has_street'])
                        <p class="tc-note">@include('admin::settings.txn.partials.icon', ['name' => 'map', 'size' => 15]) <span>Visit address on file: {{ $addressPlan['location'] }}.</span></p>
                    @endif

                    <div class="tc-ask">
                        @foreach ($askChoices as $choice)
                            <label>
                                <input type="checkbox" name="fields[]" value="{{ $choice['code'] }}" @checked(old('_token') === null ? $choice['ticked'] : in_array($choice['code'], $oldFields, true))>
                                <span class="tc-ask-name">{{ $choice['name'] }}</span>
                                @if ($choice['why'])
                                    <span class="tc-badge tc-badge-none">{{ $choice['why'] }}</span>
                                @endif
                                <span class="tc-ask-current">{{ $choice['current'] ?? '—' }}</span>
                            </label>
                        @endforeach
                    </div>
                    <p class="tc-help">Tick any other detail to ask for it again (e.g. a wrong number), or untick what the booking page shouldn't ask.</p>
                </div>
            </section>

            @include('admin::settings.txn.partials.savebar', [
                'noReset' => true,
                'cancelUrl' => $lead ? route('admin.leads.view', $lead->id) : route('admin.contacts.persons.view', $person->id),
                'saveLabel' => 'Create link (no email yet)',
                'saveDisabled' => ! $calcomConfigured || ! $customerEmailEnabled,
                'idleText' => 'Nothing is created or sent yet',
            ])
        </form>
    </div>

    <script>
        // Email preview: put the edited text into the preview frame, formatted
        // like the email template (BookingEmail::html: line breaks, **bold**;
        // an empty closing note is hidden).
        function txnEmailFormat(text) {
            const div = document.createElement('div');
            div.textContent = text.trim();

            return div.innerHTML
                .replace(/\n/g, '<br>')
                .replace(/\*\*([\s\S]+?)\*\*/g, '<strong>$1</strong>');
        }

        function txnEmailPreview() {
            const frame = document.getElementById('email-preview');
            const doc = frame && frame.contentDocument;

            if (! doc || ! doc.getElementById('txn-intro')) {
                return;
            }

            const intro = document.getElementById('email-intro').value;
            const closing = document.getElementById('email-closing').value;

            doc.getElementById('txn-intro').innerHTML = txnEmailFormat(intro);
            doc.getElementById('txn-closing').innerHTML = txnEmailFormat(closing);
            doc.getElementById('txn-closing').style.display = closing.trim() ? '' : 'none';
            txnTimesPreview(doc);
        }

        // The times currently on the form (rows with a date), earliest first.
        function txnFormSlots() {
            return Array.from(document.querySelectorAll('#booking-slots .booking-slot-row'))
                .map((row) => {
                    const select = row.querySelector('select');
                    return { value: row.querySelector('input').value, worker: select.value, workerName: select.value ? select.selectedOptions[0].text : 'no worker' };
                })
                .filter((slot) => slot.value)
                .sort((a, b) => a.value.localeCompare(b.value));
        }

        // "2026-11-10T11:00" -> "Tuesday, Nov 10 at 11:00 AM" (as in the email).
        function txnSlotLabel(value, short) {
            const [date, time] = value.split('T');
            const [y, m, d] = date.split('-').map(Number);
            const [h, min] = time.split(':').map(Number);
            const day = new Date(y, m - 1, d);
            const clock = `${h % 12 || 12}:${String(min).padStart(2, '0')} ${h < 12 ? 'AM' : 'PM'}`;
            return short
                ? `${day.toLocaleDateString('en-US', { weekday: 'short' })} ${day.toLocaleDateString('en-US', { month: 'short' })} ${d}, ${clock}`
                : `${day.toLocaleDateString('en-US', { weekday: 'long' })}, ${day.toLocaleDateString('en-US', { month: 'short' })} ${d} at ${clock}`;
        }

        // Keeps the email preview's times and the "Times in this link" list
        // in step with the time rows on the form.
        function txnTimesPreview(doc) {
            const slots = txnFormSlots();
            const table = doc && doc.getElementById('txn-times');

            if (table && table.rows.length) {
                const template = table.rows[0];
                const body = template.parentNode;
                Array.from(table.rows).slice(1).forEach((row) => row.remove());
                slots.forEach((slot, index) => {
                    const row = index === 0 ? template : body.appendChild(template.cloneNode(true));
                    row.querySelector('strong').textContent = txnSlotLabel(slot.value, false);
                });
                table.style.display = slots.length ? '' : 'none';
            }

            const list = document.getElementById('draft-times');
            const form = document.querySelector('form[data-draft-slots]');
            if (! list || ! form) {
                return;
            }
            list.innerHTML = '';
            slots.forEach((slot) => {
                const item = document.createElement('li');
                item.className = 'tc-chip';
                item.textContent = `${txnSlotLabel(slot.value, true)} — ${slot.workerName}`;
                list.appendChild(item);
            });
            const now = JSON.stringify(Object.fromEntries(slots.map((slot) => [slot.value, slot.worker])));
            const draft = JSON.stringify(JSON.parse(form.dataset.draftSlots || '{}'));
            document.getElementById('draft-times-changed').style.display = now === draft ? 'none' : '';
        }

        // On "Email this link": send the form's current times with it.
        function txnCopySlots(form) {
            const box = form.querySelector('.txn-send-slots');
            box.innerHTML = '';
            Array.from(document.querySelectorAll('#booking-slots .booking-slot-row')).forEach((row) => {
                const value = row.querySelector('input').value;
                if (! value) {
                    return;
                }
                [['slots[]', value], ['slot_workers[]', row.querySelector('select').value]].forEach(([name, val]) => {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = name;
                    input.value = val;
                    box.appendChild(input);
                });
            });
        }

        ['input', 'change'].forEach((type) => document.addEventListener(type, (event) => {
            if (event.target.closest && event.target.closest('#booking-slots')) {
                txnEmailPreview();
            }
        }));

        function txnEmailReset() {
            ['email-subject', 'email-intro', 'email-closing'].forEach((id) => {
                const field = document.getElementById(id);
                field.value = field.dataset.default;
            });

            txnEmailPreview();
        }

        const SUGGEST_URL = @json(route('admin.contacts.persons.booking_link.suggest', $person->id));

        const SUGGEST_STATUS = {
            NO_LOCATION: 'The address could not be located. Check the address or ZIP.',
            OUT_OF_AREA: 'The address is outside the service area. Send to staff for review.',
            NO_STAFF: 'No active worker with a usable start address is available for that service.',
            NO_SLOTS: 'No free time for any worker in the search window. Send to staff.',
        };

        function escapeHtml(value) {
            const div = document.createElement('div');
            div.textContent = value ?? '';

            return div.innerHTML;
        }

        async function suggestBookingSlots() {
            const button = document.getElementById('suggest-button');
            const box = document.getElementById('suggest-result');
            const params = new URLSearchParams();
            const address = document.getElementById('suggest-address').value.trim();
            const now = document.getElementById('suggest-now').value;
            const service = document.getElementById('suggest-service')?.value || '';

            if (address) params.set('address', address);
            if (now) params.set('now', now);
            if (service) params.set('service', service);

            button.disabled = true;
            box.innerHTML = '<p class="tc-help"><span class="tc-spin" style="display: inline-block; vertical-align: -2px;"></span> Finding times...</p>';

            try {
                const response = await fetch(`${SUGGEST_URL}?${params}`, {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });
                const data = await response.json();

                if (! response.ok) {
                    const message = data.message || Object.values(data.errors || {}).flat().join(' ');
                    throw new Error(message || `HTTP ${response.status}`);
                }

                box.innerHTML = renderSuggestion(data);

                if (data.status === 'OK') {
                    fillBookingSlots(data.options);
                    document.getElementById('timezone').value = data.timezone;
                }
            } catch (error) {
                box.innerHTML = `<div class="tc-box is-bad">${escapeHtml(error.message)}</div>`;
            } finally {
                button.disabled = false;
            }
        }

        function renderSuggestion(data) {
            const loc = data.location || {};
            let html = `<p class="tc-note">Now: ${escapeHtml(data.now)} (${escapeHtml(data.timezone)}) · `
                + `Customer: ${escapeHtml(loc.address || '-')} &rarr; located by ${escapeHtml(loc.precision || 'nothing')}`
                + (loc.miles_from_center != null ? `, ${loc.miles_from_center} mi from ${escapeHtml(@json(\Webkul\Admin\Support\Contact\TxnSettings::areaCentreName()))}` : '')
                + (loc.precision === 'zip' ? ' <b>(ZIP only: approximate)</b>' : '') + '</p>';

            if (data.workers?.length) {
                html += '<div><span class="tc-label">Workers</span><div class="tc-chips" style="margin-top: 6px;">';
                data.workers.forEach(w => {
                    const workerColor = /^#[0-9A-Fa-f]{6}$/.test(w.color || '') ? w.color : '';
                    html += `<span class="tc-chip is-muted" title="${w.jobs} jobs booked, nearest ${w.nearest_job_minutes == null ? '-' : `~${w.nearest_job_minutes} min away`}">`
                        + (workerColor ? `<span style="color:${workerColor}">●</span>` : '')
                        + `${escapeHtml(w.name)}: `
                        + (w.best_extra_minutes == null
                            ? 'no free time'
                            : (w.reachable ? `best adds ~${w.best_extra_minutes} min` : `can't reach on time (~${w.best_extra_minutes} min)`))
                        + '</span>';
                });
                html += '</div></div>';
            }

            if (data.status !== 'OK') {
                return html + `<div class="tc-box is-bad">${escapeHtml(SUGGEST_STATUS[data.status] || data.status)}</div>`;
            }

            html += '<span class="tc-label">Suggested: least extra driving first, from any worker. Filled in below.</span>';
            data.options.forEach((option, index) => {
                const workerColor = /^#[0-9A-Fa-f]{6}$/.test(option.worker_color || '') ? option.worker_color : '';
                html += `<div class="tc-option" style="${workerColor ? `--tc-opt: ${workerColor};` : ''}">`
                    + '<div class="tc-option-head">'
                    + `<b>${index + 1}. ${escapeHtml(option.label)}</b>`
                    + `<span class="tc-chip">${escapeHtml(option.worker)}</span>`
                    + `<span class="tc-sub">+${option.extra_drive_minutes} min extra driving · ~${option.drive_in_minutes} in, ~${option.drive_out_minutes} out</span>`
                    + (option.late ? ' <span class="tc-late">worker would arrive late</span>' : '')
                    + '</div><ul>';
                for (const row of option.day_schedule) {
                    html += `<li class="${row.new ? 'is-new' : ''}">${escapeHtml(row.time)} &nbsp; ${escapeHtml(row.label)}</li>`;
                }
                html += '</ul></div>';
            });

            return html;
        }

        function fillBookingSlots(options) {
            const slots = document.getElementById('booking-slots');

            while (slots.children.length < options.length) addBookingSlot();
            while (slots.children.length > options.length) slots.lastElementChild.remove();

            options.forEach((option, index) => {
                slots.children[index].querySelector('input').value = option.value;
                slots.children[index].querySelector('select').value = String(option.worker_id);
            });
            txnEmailPreview();
        }

        function addBookingSlot() {
            const slots = document.getElementById('booking-slots');

            if (slots.children.length >= 20) {
                return;
            }

            // A copy of the first row, emptied.
            const row = slots.firstElementChild.cloneNode(true);
            row.querySelector('input').value = '';
            row.querySelector('select').value = '';
            slots.appendChild(row);
        }

        function removeBookingSlot(button) {
            const slots = document.getElementById('booking-slots');

            if (slots.children.length > 1) {
                button.closest('.booking-slot-row').remove();
                txnEmailPreview();
            }
        }
    </script>
</x-admin::layouts>
