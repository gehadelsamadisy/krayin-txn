{{-- Settings > TXN Settings > Company & sender (TxnSettingsController). Styles: partials/tc-styles (.tc, scoped). --}}
<x-admin::layouts>
    <x-slot:title>{{ $title }}</x-slot>

    @php
        $field = fn (string $name, string $key, string $help, string $placeholder = '') => compact('name', 'key', 'help', 'placeholder');
        $groups = [
            ['building', 'Company & rep', 'Who customers hear from in every email and booking confirmation.', [
                $field('company', 'lead_messages.company', 'Shown in every customer email and booking confirmation.', 'e.g. TXN Home Services'),
                $field('rep_name', 'lead_messages.rep_name', 'The person customers hear from ("This is ... with ...") and the signature.', 'e.g. Sam Rivera'),
                $field('rep_cell', 'lead_messages.rep_cell', 'Customers are asked to call or text this number. Leave empty to say "give us a call".', 'e.g. (713) 555-0100'),
                $field('office_phone', 'lead_messages.office_phone', 'Shown in the email signature.', 'e.g. (713) 555-0199'),
            ]],
            ['message', 'Text messages', 'How customer texts are signed.', [
                $field('sms_signature', 'sms.signature', 'Who the customer texts are from: {signature} in the text message ("Hi Jane, this is ...").', 'e.g. Sam | TXN Home Services'),
            ]],
        ];
    @endphp

    <div class="mx-auto flex w-full max-w-4xl flex-col gap-4">
        @include('admin::settings.txn.partials.top')

        <form method="POST" action="{{ route('admin.settings.txn.company.update') }}" class="tc tc-form" id="company-form">
            @csrf
            @method('PUT')

            @foreach ($groups as [$icon, $groupTitle, $groupText, $rows])
                <section class="tc-card is-plain">
                    @include('admin::settings.txn.partials.card-head', ['icon' => $icon, 'title' => $groupTitle, 'text' => $groupText])
                    <div class="tc-body">
                        <div class="tc-grid">
                            @foreach ($rows as $row)
                                <div class="tc-field {{ count($rows) === 1 ? 'tc-wide' : '' }}">
                                    <label for="f-{{ $row['name'] }}" class="tc-label">{{ $fields[$row['key']][2] }}</label>
                                    <input id="f-{{ $row['name'] }}" type="text" name="{{ $row['name'] }}" maxlength="{{ $fields[$row['key']][3]['max'] }}" value="{{ old($row['name'], $values[$row['key']]) }}" placeholder="{{ $row['placeholder'] }}" class="tc-input" data-sig="{{ $row['name'] }}" @disabled(! $canEdit)>
                                    <p class="tc-help">{{ $row['help'] }}</p>
                                    @include('admin::settings.txn.partials.field-meta', ['key' => $row['key']])
                                </div>
                            @endforeach
                        </div>
                    </div>
                </section>
            @endforeach

            <section class="tc-card is-plain">
                @include('admin::settings.txn.partials.card-head', ['icon' => 'eye', 'title' => 'Look', 'text' => 'The email colour, and how the signature reads.'])
                <div class="tc-body">
                    <div class="tc-grid">
                        <div class="tc-field">
                            <label for="f-accent" class="tc-label">{{ $fields['lead_messages.accent'][2] }}</label>
                            <span class="tc-swatch">
                                <input id="f-accent" type="color" name="accent" value="{{ old('accent', $values['lead_messages.accent']) }}" @disabled(! $canEdit)>
                                <code id="accent-hex" class="tc-chip is-muted">{{ old('accent', $values['lead_messages.accent']) }}</code>
                            </span>
                            <p class="tc-help">The top line and the button of the emails.</p>
                            @include('admin::settings.txn.partials.field-meta', ['key' => 'lead_messages.accent'])
                        </div>

                        <div class="tc-preview">
                            <span class="tc-preview-label">Email signature preview</span>
                            <div id="sig-preview"></div>
                        </div>
                    </div>
                </div>
            </section>

            @include('admin::settings.txn.partials.savebar')
        </form>

        @include('admin::settings.txn.partials.log')
    </div>

    @pushOnce('scripts')
        <script>
            (() => {
                const form = document.getElementById('company-form');
                const value = (name) => (form.querySelector(`[data-sig="${name}"]`)?.value || '').trim();
                const accent = document.getElementById('f-accent');
                const hex = document.getElementById('accent-hex');
                const preview = document.getElementById('sig-preview');

                const update = () => {
                    const lines = [
                        value('rep_name') ? `<strong style="color:${accent.value}">${escape(value('rep_name'))}</strong>` : '',
                        escape(value('company')),
                        value('rep_cell') ? `Cell: ${escape(value('rep_cell'))}` : '',
                        value('office_phone') ? `Office: ${escape(value('office_phone'))}` : '',
                    ].filter(Boolean);

                    preview.innerHTML = lines.join('<br>') || '<span class="tc-help">Fill in the fields to see the signature.</span>';
                    hex.textContent = accent.value;
                };

                const escape = (text) => text.replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

                form.addEventListener('input', update);
                update();
            })();
        </script>
    @endPushOnce
</x-admin::layouts>
