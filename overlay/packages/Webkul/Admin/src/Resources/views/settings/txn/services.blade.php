{{-- Settings > TXN Settings > Services (TxnSettingsController). One card per service;
     rows are added with plain JS (cloned from the last card). Styles: partials/tc-styles (.tc, scoped). --}}
<x-admin::layouts>
    <x-slot:title>{{ $title }}</x-slot>

    @php
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

        <form method="POST" action="{{ route('admin.settings.txn.services.update') }}" class="tc tc-form">
            @csrf
            @method('PUT')

            <section class="tc-card is-plain">
                @include('admin::settings.txn.partials.card-head', [
                    'icon' => 'list',
                    'title' => 'Services and how each is handled',
                    'text' => 'The lead check goes down this list: the first service with a keyword in the customer\'s request wins.',
                    'aside' => '<span class="tc-pill">'.count($rows).' '.(count($rows) === 1 ? 'service' : 'services').'</span>',
                ])
                <div class="tc-body">
                    <p class="tc-note">@include('admin::settings.txn.partials.icon', ['name' => 'info', 'size' => 15]) <span>Put specific services ("roof replacement") above general ones ("roof"). No match: the lead goes to Needs Review. <strong>Roof report first</strong> sends roof leads to the roof pipeline before a booking link.</span></p>

                    <div id="service-rows" class="tc-rows">
                        @foreach ($rows as $i => $row)
                            <div class="tc-svc {{ ! empty($row['remove']) ? 'is-removed' : '' }}">
                                <div class="tc-svc-order">
                                    <input type="number" step="any" name="rows[{{ $i }}][order]" value="{{ $row['order'] ?? '' }}" class="tc-input" aria-label="Order" @disabled(! $canEdit)>
                                    <small>order</small>
                                </div>
                                <div class="tc-svc-fields">
                                    <div class="tc-field">
                                        <span class="tc-label">Keywords <span class="tc-sublabel">(comma-separated)</span></span>
                                        <input type="text" name="rows[{{ $i }}][keywords]" value="{{ $row['keywords'] ?? '' }}" placeholder="e.g. gutter, downspout" class="tc-input" aria-label="Keywords" data-keywords @disabled(! $canEdit)>
                                        <div class="tc-chips" data-chips></div>
                                    </div>
                                    <div class="tc-field">
                                        <span class="tc-label">Category</span>
                                        <input type="text" name="rows[{{ $i }}][category]" maxlength="60" value="{{ $row['category'] ?? '' }}" placeholder="e.g. Gutter" class="tc-input" aria-label="Category" @disabled(! $canEdit)>
                                    </div>
                                    <div class="tc-field">
                                        <span class="tc-label">Flow</span>
                                        <select name="rows[{{ $i }}][flow]" class="tc-input" aria-label="Flow" @disabled(! $canEdit)>
                                            @foreach ($flows as $flow => $label)
                                                <option value="{{ $flow }}" @selected(($row['flow'] ?? 'standard') === $flow)>{{ $label }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="tc-field tc-wide">
                                        <div class="tc-label-row">
                                            <span class="tc-label">Line in the booking confirmation</span>
                                            <label class="tc-remove"><input type="checkbox" name="rows[{{ $i }}][remove]" value="1" aria-label="Remove" data-remove @checked(! empty($row['remove'])) @disabled(! $canEdit)> Remove this service</label>
                                        </div>
                                        <input type="text" name="rows[{{ $i }}][line]" maxlength="500" value="{{ $row['line'] ?? '' }}" placeholder="e.g. We'll look at your gutters and walk you through the options." class="tc-input" aria-label="Confirmation line" @disabled(! $canEdit)>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>

                    @if ($canEdit)
                        <button id="add-service" type="button" class="tc-add">@include('admin::settings.txn.partials.icon', ['name' => 'plus', 'size' => 15]) Add service</button>
                    @endif
                    @include('admin::settings.txn.partials.field-meta', ['key' => 'lead_triage.services'])
                </div>
            </section>

            <section class="tc-card is-plain">
                @include('admin::settings.txn.partials.card-head', ['icon' => 'message', 'title' => 'Any other service', 'text' => 'Used in the booking confirmation when a service has no line of its own.'])
                <div class="tc-body">
                    <div class="tc-field">
                        <label for="f-default-line" class="tc-label">Line for any other service</label>
                        <input id="f-default-line" type="text" name="default_line" maxlength="500" value="{{ old('default_line', $lines['default'] ?? '') }}" class="tc-input" @disabled(! $canEdit)>
                        @include('admin::settings.txn.partials.field-meta', ['key' => 'lead_messages.service_lines'])
                    </div>
                </div>
            </section>

            @include('admin::settings.txn.partials.savebar', ['resetConfirm' => 'Put the service list and lines back to their defaults?'])
        </form>

        @include('admin::settings.txn.partials.log')
    </div>

    @pushOnce('scripts')
        <script>
            (() => {
                const body = document.getElementById('service-rows');
                const button = document.getElementById('add-service');

                if (! body) {
                    return;
                }

                // Keywords as chips, and a removed service greyed out.
                const refresh = (card) => {
                    const chips = card.querySelector('[data-chips]');
                    const words = (card.querySelector('[data-keywords]').value || '').split(',').map((w) => w.trim()).filter(Boolean);
                    chips.replaceChildren(...words.map((word) => Object.assign(document.createElement('span'), { className: 'tc-chip', textContent: word })));
                    card.classList.toggle('is-removed', card.querySelector('[data-remove]').checked);
                };

                body.querySelectorAll('.tc-svc').forEach(refresh);
                body.addEventListener('input', (event) => refresh(event.target.closest('.tc-svc')));
                body.addEventListener('change', (event) => refresh(event.target.closest('.tc-svc')));

                button?.addEventListener('click', () => {
                    const last = body.querySelector('.tc-svc:last-child');
                    const card = last.cloneNode(true);
                    const index = Date.now();

                    card.querySelectorAll('input, select').forEach((field) => {
                        field.name = field.name.replace(/rows\[[^\]]+\]/, `rows[${index}]`);

                        if (field.type === 'checkbox') {
                            field.checked = false;
                        } else if (field.tagName === 'SELECT') {
                            field.selectedIndex = 0;
                        } else {
                            field.value = '';
                        }
                    });

                    card.querySelector('input[name$="[order]"]').value = body.querySelectorAll('.tc-svc').length + 1;
                    body.appendChild(card);
                    refresh(card);
                    card.querySelector('input[name$="[keywords]"]').focus();
                });
            })();
        </script>
    @endPushOnce
</x-admin::layouts>
