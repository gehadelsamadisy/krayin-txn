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

    <div class="mx-auto max-w-3xl rounded-lg border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-gray-900">
        <div class="mb-6">
            <h1 class="text-xl font-semibold dark:text-white">Send a home-visit booking link</h1>
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                For {{ $person->name }}. The customer will choose one of the exact 60-minute slots you enter.
            </p>

            @if ($lead)
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                    Lead: <a href="{{ route('admin.leads.view', $lead->id) }}" class="text-blue-600 underline">{{ $lead->title }}</a>.
                    The booking goes to this lead only.
                </p>
            @endif
        </div>

        @if (! $calcomConfigured)
            <div class="mb-5 rounded border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900">
                Cal.com is not configured yet. Set <code>CALCOM_API_KEY</code> in the Krayin environment before sending.
            </div>
        @endif

        @if (! $customerEmailEnabled)
            <div class="mb-5 rounded border border-blue-300 bg-blue-50 p-3 text-sm text-blue-900">
                @if (config('txn.test_mode', true))
                    Customer booking-link creation and email are disabled. No link will be created or sent from this form.
                @else
                    This contact has no email address. Add one to create and send a booking link.
                @endif
            </div>
        @elseif (! config('txn.test_mode', true))
            <div class="mb-5 rounded border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900">
                "Create link" makes a real Cal.com link and shows it here; nothing is emailed until you press
                "Email this link". The email goes to the customer.
            </div>
        @else
            <div class="mb-5 rounded border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900">
                Authorized for this contact and test recipient only. "Create link" makes a real (hidden) Cal.com link
                and shows it here; nothing is emailed until you press "Email this link".
                @if ($singleTestAttempt)
                    Only one email is allowed.
                @endif
            </div>
        @endif

        @if ($draftLink)
            <div class="mb-5 rounded border border-green-300 bg-green-50 p-4 text-sm text-green-900">
                <p class="font-medium">Link created &mdash; not sent yet</p>

                <div class="mt-2 flex flex-wrap items-center gap-2">
                    <input
                        id="draft-link"
                        type="text"
                        readonly
                        value="{{ $draftLink['url'] }}"
                        class="min-w-0 flex-1 rounded border border-green-300 bg-white px-3 py-2 text-sm text-gray-900"
                    >
                    <a href="{{ $draftLink['url'] }}" target="_blank" rel="noopener noreferrer" class="secondary-button">Open</a>
                    <button type="button" class="secondary-button" onclick="navigator.clipboard.writeText(document.getElementById('draft-link').value); this.textContent = 'Copied';">Copy</button>
                </div>

                <p class="mt-2 text-xs">Times in this link ({{ $draftLink['timezone'] }}); the worker of the time the customer books gets the visit:</p>
                <p id="draft-times-changed" class="mt-1 text-xs font-medium" style="display: none; color: #b45309;">
                    The times below were changed on the form. Sending the email updates the link to these times.
                </p>
                <ul id="draft-times" class="mt-1 list-inside list-disc text-xs">
                    @foreach ($draftLink['slot_workers'] ?? array_fill_keys($draftLink['slots'], null) as $slot => $workerId)
                        <li>
                            {{ \Carbon\CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $slot)->format('D M j, g:i A') }}
                            &mdash; {{ $workerNames[$workerId] ?? 'no worker' }}
                        </li>
                    @endforeach
                </ul>

                {{-- The email that goes with the link: check it, edit the text, then send. --}}
                <form method="POST" action="{{ route('admin.contacts.persons.booking_link.send', $person->id) }}" onsubmit="txnCopySlots(this)" class="mt-3 space-y-3 border-t border-green-200 pt-3 text-gray-900"
                    data-draft-slots="{{ json_encode(collect($draftLink['slot_workers'] ?? [])->map(fn ($w) => (string) $w)->sortKeys()->all()) }}">
                    @csrf
                    {{-- The form's current times (and workers), copied in on submit. --}}
                    <div class="txn-send-slots"></div>

                    <p class="text-sm font-medium text-green-900">The email &mdash; edit the text if needed, then send</p>

                    <div class="flex flex-wrap items-center gap-2">
                        <label for="send-email" class="w-16 text-xs font-medium text-green-900">To</label>
                        <select id="send-email" name="email" required class="rounded border border-gray-300 px-2 py-1 text-sm text-gray-900">
                            @foreach ($emails as $address)
                                <option value="{{ $address }}">{{ $address }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <label for="email-subject" class="w-16 text-xs font-medium text-green-900">Subject</label>
                        <input id="email-subject" name="subject" type="text" required maxlength="200" value="{{ $email['subject'] }}"
                            data-default="{{ $email['subject'] }}" oninput="txnEmailPreview()"
                            class="min-w-0 flex-1 rounded border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900">
                    </div>

                    <div>
                        <label for="email-intro" class="text-xs font-medium text-green-900">Message (before the times)</label>
                        <textarea id="email-intro" name="intro" rows="4" required maxlength="3000"
                            data-default="{{ $email['intro'] }}" oninput="txnEmailPreview()"
                            class="mt-1 w-full rounded border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900">{{ $email['intro'] }}</textarea>
                    </div>

                    <div>
                        <label for="email-closing" class="text-xs font-medium text-green-900">Closing note (after the button; can be empty)</label>
                        <textarea id="email-closing" name="closing" rows="3" maxlength="3000"
                            data-default="{{ $email['closing'] }}" oninput="txnEmailPreview()"
                            class="mt-1 w-full rounded border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900">{{ $email['closing'] }}</textarea>
                    </div>

                    <p class="text-xs text-green-900">
                        "Hi {{ $email['firstName'] }}," the times, the "Choose your time" button and the signature are added automatically, so they always match the link.
                        <button type="button" class="underline" onclick="txnEmailReset()">Reset the text</button>
                    </p>

                    <div>
                        <p class="mb-1 text-xs font-medium text-green-900">Preview</p>
                        <iframe id="email-preview" title="Email preview" srcdoc="{{ $emailPreview }}" onload="window.txnEmailPreview && txnEmailPreview()"
                            class="w-full rounded border border-gray-300 bg-white" style="height: 640px;"></iframe>
                    </div>

                    <button type="submit" class="primary-button" @disabled(! $emails || ! $customerEmailEnabled)>Email this link</button>
                </form>

                <p class="mt-2 text-xs">Creating a new link below replaces this one.</p>
            </div>
        @endif

        @if ($errors->any())
            <div role="alert" class="mb-5 rounded border border-red-300 bg-red-50 p-3 text-sm text-red-800">
                <ul class="list-inside list-disc">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('admin.contacts.persons.booking_link.create_link', $person->id) }}" class="space-y-6">
            @csrf

            @if ($lead)
                <input type="hidden" name="lead_id" value="{{ $lead->id }}">
            @endif

            <div>
                <label for="email" class="mb-1 block text-sm font-medium dark:text-white">Customer email</label>

                @if ($emails)
                    <select id="email" name="email" required class="w-full rounded border border-gray-300 px-3 py-2 dark:border-gray-700 dark:bg-gray-800 dark:text-white">
                        @foreach ($emails as $email)
                            <option value="{{ $email }}" @selected(old('email', $emails[0]) === $email)>{{ $email }}</option>
                        @endforeach
                    </select>
                @else
                    <p class="text-sm text-red-600">This Person has no valid email address. Add one before sending a booking link.</p>
                @endif

            </div>

            <div>
                <label for="timezone" class="mb-1 block text-sm font-medium dark:text-white">Time zone for these slots</label>
                <input
                    id="timezone"
                    name="timezone"
                    type="text"
                    required
                    value="{{ old('timezone', $defaultTimezone) }}"
                    placeholder="America/Chicago"
                    class="w-full rounded border border-gray-300 px-3 py-2 dark:border-gray-700 dark:bg-gray-800 dark:text-white"
                >
                <p class="mt-1 text-xs text-gray-500">Use an IANA time zone. Texas customers: America/Chicago.</p>

            </div>

            <fieldset class="rounded border border-gray-200 p-4 dark:border-gray-800">
                <legend class="px-1 text-sm font-medium dark:text-white">Suggest times automatically</legend>
                <p class="mb-3 text-xs text-gray-500">
                    Picks the best times for this customer from the workers' schedules and fills them in below.
                    Nothing is created in Cal.com and nothing is sent.
                </p>

                <div class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <label for="suggest-address" class="mb-1 block text-xs font-medium dark:text-white">Test address (optional)</label>
                        <input
                            id="suggest-address"
                            type="text"
                            placeholder="Contact's address, or e.g. 123 Main St, Katy, TX 77494"
                            class="w-full rounded border border-gray-300 px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-800 dark:text-white"
                        >
                    </div>

                    <div>
                        <label for="suggest-now" class="mb-1 block text-xs font-medium dark:text-white">Pretend "now" (optional, Chicago time)</label>
                        <input
                            id="suggest-now"
                            type="datetime-local"
                            class="w-full rounded border border-gray-300 px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-800 dark:text-white"
                        >
                    </div>
                </div>

                @if ($serviceTypes->isNotEmpty())
                    <div class="mt-3">
                        <label for="suggest-service" class="mb-1 block text-xs font-medium dark:text-white">Service needed (optional)</label>
                        <select id="suggest-service" class="w-full rounded border border-gray-300 px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-800 dark:text-white">
                            <option value="">No service specified</option>
                            @foreach ($serviceTypes as $service)
                                <option value="{{ $service }}">{{ $service }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif

                <button type="button" id="suggest-button" onclick="suggestBookingSlots()" class="secondary-button mt-3">Suggest times</button>

                <div id="suggest-result" class="mt-3 hidden text-sm dark:text-white"></div>
            </fieldset>

            <fieldset>
                <legend class="mb-2 text-sm font-medium dark:text-white">Available appointment slots (60 minutes each, starting on the hour)</legend>
                <p class="mb-2 text-xs text-gray-500">Each time has its own worker. The customer picks a time; that time's worker gets the visit when Cal.com syncs the booking. Suggest times fills in both; for a time you type, choose its worker.</p>

                @if ($workers->isEmpty())
                    <p class="mb-2 text-sm text-red-600">No active worker with a geocoded start address is available. Register and locate a worker first.</p>
                @endif

                <div id="booking-slots" class="space-y-2">
                    @foreach ($oldSlots as $index => $slot)
                        <div class="booking-slot-row flex items-center gap-2">
                            <input
                                type="datetime-local"
                                name="slots[]"
                                value="{{ $slot }}"
                                step="3600"
                                required
                                class="min-w-0 flex-1 rounded border border-gray-300 px-3 py-2 dark:border-gray-700 dark:bg-gray-800 dark:text-white"
                            >
                            <select name="slot_workers[]" required class="rounded border border-gray-300 px-3 py-2 dark:border-gray-700 dark:bg-gray-800 dark:text-white">
                                <option value="">Worker</option>
                                @foreach ($workers as $worker)
                                    <option value="{{ $worker->id }}" @selected((string) ($oldSlotWorkers[$index] ?? '') === (string) $worker->id)>{{ $worker->name }}</option>
                                @endforeach
                            </select>
                            <button type="button" onclick="removeBookingSlot(this)" class="secondary-button">Remove</button>
                        </div>
                    @endforeach
                </div>
                @error('slot_workers') <p class="mt-1 text-sm text-red-600" role="alert">{{ $message }}</p> @enderror

                <button type="button" onclick="addBookingSlot()" class="secondary-button mt-3">Add another time</button>

            </fieldset>

            <fieldset>
                <legend class="mb-2 text-sm font-medium dark:text-white">Ask the customer for</legend>

                <p class="mb-2 text-xs text-gray-500">Missing or unusable details are ticked. Tick any other detail to ask for it again (e.g. a wrong number), or untick what the booking page shouldn't ask. Answers replace the stored values when the booking syncs. Name and email are always on the booking page.</p>

                @if ($addressPlan['has_street'])
                    <p class="mb-2 text-xs text-gray-500">Visit address on file: {{ $addressPlan['location'] }}.</p>
                @endif

                <div class="txn-ask-list">
                    @foreach ($askChoices as $choice)
                        <label class="txn-ask-row">
                            <input type="checkbox" name="fields[]" value="{{ $choice['code'] }}" @checked(old('_token') === null ? $choice['ticked'] : in_array($choice['code'], $oldFields, true))>
                            <span class="txn-ask-name">{{ $choice['name'] }}</span>
                            @if ($choice['why'])
                                <span class="txn-ask-badge">{{ $choice['why'] }}</span>
                            @endif
                            <span class="txn-ask-current">{{ $choice['current'] ?? '—' }}</span>
                        </label>
                    @endforeach
                </div>
            </fieldset>

            <div class="flex items-center gap-3 border-t border-gray-200 pt-5 dark:border-gray-800">
                <button
                    type="submit"
                    class="primary-button"
                    @disabled(! $calcomConfigured || ! $customerEmailEnabled)
                >
                    Create link (no email yet)
                </button>

                <a href="{{ $lead ? route('admin.leads.view', $lead->id) : route('admin.contacts.persons.view', $person->id) }}" class="secondary-button">Cancel</a>
            </div>
        </form>
    </div>

    <script>
        // Email preview: put the edited text into the preview frame, formatted
        // like the email template (line breaks; "free, no-obligation
        // estimate" in bold; an empty closing note is hidden).
        function txnEmailFormat(text) {
            const div = document.createElement('div');
            div.textContent = text.trim();

            return div.innerHTML
                .replace(/\n/g, '<br>')
                .replace(/free, no-obligation estimate/g, '<strong>free, no-obligation estimate</strong>');
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
            box.className = 'mt-3 text-sm dark:text-white';
            box.textContent = 'Finding times...';

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
                box.innerHTML = `<p class="text-red-600">${escapeHtml(error.message)}</p>`;
            } finally {
                button.disabled = false;
            }
        }

        function renderSuggestion(data) {
            const loc = data.location || {};
            let html = `<p class="text-xs text-gray-500">Now: ${escapeHtml(data.now)} (${escapeHtml(data.timezone)})<br>`
                + `Customer: ${escapeHtml(loc.address || '-')} &rarr; located by ${escapeHtml(loc.precision || 'nothing')}`
                + (loc.miles_from_houston != null ? `, ${loc.miles_from_houston} mi from Houston` : '')
                + (loc.precision === 'zip' ? ' <b>(ZIP only: approximate)</b>' : '') + '</p>';

            if (data.workers?.length) {
                html += '<p class="mt-2 font-medium">Workers</p><ul class="list-inside list-disc text-xs">';
                data.workers.forEach(w => {
                    const workerColor = /^#[0-9A-Fa-f]{6}$/.test(w.color || '') ? w.color : '';
                    html += `<li>${workerColor ? `<span style="color:${workerColor}">●</span> ` : ''}${escapeHtml(w.name)}: `
                        + (w.best_extra_minutes == null
                            ? 'no free time'
                            : (w.reachable ? `best time adds ~${w.best_extra_minutes} min` : `only times they can't reach on time (best adds ~${w.best_extra_minutes} min)`))
                        + `; ${w.jobs} jobs booked, nearest ` + (w.nearest_job_minutes == null ? '-' : `~${w.nearest_job_minutes} min away`)
                        + '</li>';
                });
                html += '</ul>';
            }

            if (data.status !== 'OK') {
                return html + `<p class="mt-2 font-medium text-red-600">${escapeHtml(SUGGEST_STATUS[data.status] || data.status)}</p>`;
            }

            html += '<p class="mt-2 font-medium">Suggested (least extra driving first, from any worker) &mdash; filled in below</p>';
            data.options.forEach((option, index) => {
                const workerColor = /^#[0-9A-Fa-f]{6}$/.test(option.worker_color || '') ? option.worker_color : '';
                html += `<div class="mt-2 rounded border border-gray-200 p-2 dark:border-gray-800">`
                    + `<b>${index + 1}. ${escapeHtml(option.label)}</b> with `
                    + (workerColor ? `<span style="color:${workerColor}">●</span> ` : '')
                    + `${escapeHtml(option.worker)} `
                    + `<span class="text-xs text-gray-500">(+${option.extra_drive_minutes} min extra driving; `
                    + `~${option.drive_in_minutes} in, ~${option.drive_out_minutes} out)</span>`
                    + (option.late ? ' <b class="text-red-600">worker would arrive late</b>' : '')
                    + '<ul class="ml-4 mt-1 text-xs">';
                for (const row of option.day_schedule) {
                    html += `<li class="${row.new ? 'font-semibold text-green-700' : 'text-gray-600 dark:text-gray-300'}">`
                        + `${escapeHtml(row.time)} &nbsp; ${escapeHtml(row.label)}</li>`;
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

    @push('styles')
        <style>
            .txn-ask-list { border: 1px solid #e5e7eb; border-radius: 6px; }
            .txn-ask-row { display: flex; align-items: center; gap: 10px; padding: 8px 12px; font-size: 14px; cursor: pointer; }
            .txn-ask-row + .txn-ask-row { border-top: 1px solid #e5e7eb; }
            .txn-ask-name { min-width: 120px; font-weight: 500; }
            .txn-ask-badge { font-size: 11px; padding: 1px 8px; border-radius: 9999px; background: #fef3c7; color: #92400e; white-space: nowrap; }
            .txn-ask-current { margin-left: auto; color: #6b7280; font-size: 13px; text-align: right; overflow-wrap: anywhere; }
            .dark .txn-ask-list, .dark .txn-ask-row + .txn-ask-row { border-color: #1f2937; }
            .dark .txn-ask-row { color: #fff; }
            .dark .txn-ask-current { color: #9ca3af; }
        </style>
    @endpush
</x-admin::layouts>
