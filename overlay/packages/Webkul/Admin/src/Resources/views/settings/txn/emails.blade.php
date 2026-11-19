{{-- Settings > TXN Settings > Emails (TxnSettingsController). The preview is
     the real email template rendered with a sample customer; the script below
     puts the text being typed into it (same rules as BookingEmail: placeholders,
     **bold**, empty parts hidden, signature lines with empty placeholders left out).
     Styles: partials/tc-styles (.tc, scoped). --}}
<x-admin::layouts>
    <x-slot:title>{{ $title }}</x-slot>

    @php
        $groups = \Webkul\Admin\Support\Contact\TxnSettings::EMAIL_PARTS;
        $groupIcons = ['shared' => 'pen', 'link' => 'mail', 'reminder' => 'bell', 'final' => 'clock'];
        $smsKey = 'sms.templates.booking_link';
        $smsValue = old('sms_booking_link', $values[$smsKey]);
    @endphp

    <div class="mx-auto flex w-full max-w-6xl flex-col gap-4">
        @include('admin::settings.txn.partials.top')

        <section class="tc tc-card is-plain">
            @include('admin::settings.txn.partials.card-head', ['icon' => 'tag', 'title' => 'Placeholders', 'text' => 'Type these in any part; each email fills in the real values. Put **two stars** around words to make them bold. An empty part is left out of the email.'])
            <div class="tc-body">
                <div class="tc-chips">
                    @foreach ($placeholders as $name => $meaning)
                        <code class="tc-chip" title="{{ $meaning }}">{{ '{'.$name.'}' }}</code>
                    @endforeach
                </div>
                <div class="tc-grid">
                    @foreach ($placeholders as $name => $meaning)
                        <p class="tc-help"><strong>{{ '{'.$name.'}' }}</strong> {{ $meaning }}</p>
                    @endforeach
                </div>
            </div>
        </section>

        <div class="tc tc-split">
            <form method="POST" action="{{ route('admin.settings.txn.emails.update') }}" class="tc-form">
                @csrf
                @method('PUT')

                @foreach ($groups as $group => [$groupTitle, $parts])
                    @php
                        $resetButton = $canEdit
                            ? '<button type="submit" name="reset" value="emails:'.e($group).'" formnovalidate class="tc-link" onclick="return confirm(\'Put this email back to its default wording?\')">Reset this email</button>'
                            : '';
                    @endphp
                    <section class="tc-card is-plain">
                        @include('admin::settings.txn.partials.card-head', ['icon' => $groupIcons[$group] ?? 'mail', 'title' => $groupTitle, 'aside' => $resetButton])
                        <div class="tc-body">
                            @foreach ($parts as $part => [$label, $oneLine, $required])
                                @php
                                    $key = "lead_messages.templates.{$group}.{$part}";
                                    $value = old("parts.{$group}.{$part}", $values[$key]);
                                @endphp
                                <div class="tc-field">
                                    <label for="p-{{ $group }}-{{ $part }}" class="tc-label">{{ $label }} @unless ($required) <span class="tc-sublabel">(can be empty)</span> @endunless</label>
                                    @if ($oneLine)
                                        <input id="p-{{ $group }}-{{ $part }}" type="text" name="parts[{{ $group }}][{{ $part }}]" value="{{ $value }}" maxlength="300"
                                            data-group="{{ $group }}" data-part="{{ $part }}" class="txn-part tc-input" @disabled(! $canEdit)>
                                    @else
                                        <textarea id="p-{{ $group }}-{{ $part }}" name="parts[{{ $group }}][{{ $part }}]" rows="{{ $part === 'signature' ? 4 : 3 }}" maxlength="3000"
                                            data-group="{{ $group }}" data-part="{{ $part }}" class="txn-part tc-input" @disabled(! $canEdit)>{{ $value }}</textarea>
                                    @endif
                                    @include('admin::settings.txn.partials.field-meta', ['key' => $key, 'defaultText' => (string) $defaults[$key]])
                                </div>
                            @endforeach
                        </div>
                    </section>
                @endforeach

                <section class="tc-card is-plain">
                    @include('admin::settings.txn.partials.card-head', ['icon' => 'message', 'title' => 'Text messages (SMS)', 'text' => 'Sent with the booking-link email when the customer has a phone number.'])
                    <div class="tc-body">
                        <div class="tc-chips">
                            @foreach ($smsPlaceholders as $name => $meaning)
                                <code class="tc-chip {{ $name === 'link' ? '' : 'is-muted' }}" title="{{ $meaning }}">{{ '{'.$name.'}' }}</code>
                            @endforeach
                        </div>
                        <div class="tc-field">
                            <label for="f-sms" class="tc-label">{{ $fields[$smsKey][2] }} <span class="tc-sublabel">({{ '{link}' }} is required, one line)</span></label>
                            <textarea id="f-sms" name="sms_booking_link" rows="3" maxlength="{{ $fields[$smsKey][3]['max'] }}" class="tc-input" @disabled(! $canEdit)>{{ $smsValue }}</textarea>
                            @include('admin::settings.txn.partials.field-meta', ['key' => $smsKey, 'defaultText' => (string) $defaults[$smsKey]])
                        </div>
                        <div class="tc-phone">
                            <span class="tc-preview-label">What the customer gets</span>
                            <div class="tc-bubble" id="sms-preview"></div>
                            <div class="tc-count">
                                <span class="tc-meter" id="sms-meter"><i></i></span>
                                <span id="sms-count"></span>
                            </div>
                        </div>

                        @php
                            $moreTexts = [
                                ['sms.templates.reminder', 'sms_reminder', 'with the reminder email, when the SMS engine\'s reminder text is on; {link} required'],
                                ['sms.templates.final', 'sms_final', 'with the final email, when the SMS engine\'s final text is on'],
                                ['sms.templates.cancelled', 'sms_cancelled', 'when a customer cancels, if the SMS engine\'s cancellation text is on; {appointment} = the canceled visit'],
                            ];
                        @endphp
                        @foreach ($moreTexts as [$textKey, $textName, $textHelp])
                            <div class="tc-field" style="padding-top: 14px; border-top: 1px dashed var(--tc-line);">
                                <label for="f-{{ $textName }}" class="tc-label">{{ $fields[$textKey][2] }} <span class="tc-sublabel">({{ $textHelp }})</span></label>
                                <textarea id="f-{{ $textName }}" name="{{ $textName }}" rows="2" maxlength="{{ $fields[$textKey][3]['max'] }}" class="tc-input" @disabled(! $canEdit)>{{ old($textName, $values[$textKey]) }}</textarea>
                                @include('admin::settings.txn.partials.field-meta', ['key' => $textKey, 'defaultText' => (string) $defaults[$textKey]])
                            </div>
                        @endforeach
                    </div>
                </section>

                @include('admin::settings.txn.partials.savebar', ['resetLabel' => 'Reset all emails', 'resetConfirm' => 'Put all three emails and the text message back to their default wording?'])
            </form>

            <section class="tc-card is-plain tc-sticky">
                @include('admin::settings.txn.partials.card-head', [
                    'icon' => 'eye',
                    'title' => 'Preview',
                    'text' => 'Sample customer "Jane", gutter service, sample times. Updates while you type; Save to keep it.',
                ])
                <div class="tc-body">
                    <div class="tc-label-row">
                        <select id="preview-kind" class="tc-input is-short" aria-label="Which email to preview">
                            @foreach ($previews as $id => $preview)
                                <option value="{{ $id }}">{{ $preview['label'] }}</option>
                            @endforeach
                        </select>
                    </div>
                    <p class="tc-help" style="font-size: 13px;">Subject: <strong id="preview-subject" style="color: var(--tc-ink);"></strong></p>
                    <iframe id="email-preview" title="Email preview" srcdoc="{{ $previews['link']['html'] }}" class="tc-frame"></iframe>
                </div>
            </section>
        </div>

        @include('admin::settings.txn.partials.log')
    </div>

    @pushOnce('scripts')
        <script>
            (() => {
                const vars = @json($sampleVars);
                const previews = @json($previews);
                const frame = document.getElementById('email-preview');
                const select = document.getElementById('preview-kind');

                const value = (group, part) => {
                    const field = document.querySelector(`.txn-part[data-group="${group}"][data-part="${part}"]`);
                    return field ? field.value : '';
                };

                // BookingEmail::fill
                const fill = (template, dropEmptyLines) => {
                    let text = template.replace(/\r\n/g, '\n');

                    if (dropEmptyLines) {
                        text = text.split('\n').filter((line) => {
                            const names = [...line.matchAll(/\{([a-z_]+)\}/g)].map((m) => m[1]);
                            return ! names.length || names.some((name) => String(vars[name] ?? '').trim() !== '');
                        }).join('\n');
                    }

                    return text.replace(/\{([a-z_]+)\}/g, (all, name) => (name in vars ? vars[name] : all));
                };

                // BookingEmail::html
                const html = (text) => {
                    const div = document.createElement('div');
                    div.textContent = text.trim();
                    return div.innerHTML.replace(/\n/g, '<br>').replace(/\*\*([\s\S]+?)\*\*/g, '<strong>$1</strong>');
                };

                const plain = (text) => text.trim().replace(/\*\*([\s\S]+?)\*\*/g, '$1');

                const set = (doc, id, text, hideWhenEmpty = true) => {
                    const element = doc.getElementById(id);

                    if (element) {
                        element.innerHTML = html(text);

                        if (hideWhenEmpty) {
                            element.style.display = text.trim() ? '' : 'none';
                        }
                    }
                };

                function update() {
                    const doc = frame.contentDocument;
                    const preview = previews[select.value];

                    if (! doc || ! doc.getElementById('txn-intro') || ! preview) {
                        return;
                    }

                    const kind = preview.kind;
                    const intro = kind === 'reminder' && ! preview.offer ? 'intro_no_times' : 'intro';

                    document.getElementById('preview-subject').textContent = plain(fill(value(kind, 'subject')));
                    set(doc, 'txn-header', fill(value('shared', 'header')));
                    set(doc, 'txn-greeting', fill(value('shared', 'greeting')));
                    set(doc, 'txn-intro', fill(value(kind, intro)), false);
                    set(doc, 'txn-closing', preview.offer ? fill(value(kind, 'closing')) : '');
                    set(doc, 'txn-fallback', fill(value('shared', 'fallback')), false);
                    set(doc, 'txn-signature', fill(value('shared', 'signature'), true));

                    const button = fill(value('shared', 'button'));
                    set(doc, 'txn-button', button, false);
                    const box = doc.getElementById('txn-button-box');
                    if (box) {
                        box.style.display = button.trim() ? '' : 'none';
                    }
                }

                // Text message preview and length (one SMS = 160 plain characters;
                // longer texts go as several parts, 153 characters each).
                const smsVars = @json($smsSampleVars);
                const smsField = document.getElementById('f-sms');
                const updateSms = () => {
                    if (! smsField) {
                        return;
                    }

                    let text = smsField.value.replace(/\{([a-z_]+)\}/g, (all, name) => (name in smsVars ? smsVars[name] : all)).trim();
                    if (! text.includes(smsVars.link)) {
                        text = (text + ' ' + smsVars.link).trim();
                    }
                    const parts = text.length <= 160 ? 1 : Math.ceil(text.length / 153);
                    document.getElementById('sms-preview').textContent = text;
                    document.getElementById('sms-count').textContent = `${text.length} / 160 · ${parts} SMS ${parts === 1 ? 'part' : 'parts'}`
                        + (/[^\x00-\x7F]/.test(text) ? ' · special characters (emoji, curly quotes) make each part shorter (70 characters)' : '');
                    const meter = document.getElementById('sms-meter');
                    meter.querySelector('i').style.width = `${Math.min(100, text.length / 160 * 100)}%`;
                    meter.classList.toggle('is-long', parts > 1);
                };
                smsField?.addEventListener('input', updateSms);
                updateSms();

                frame.addEventListener('load', update);
                select.addEventListener('change', () => { frame.srcdoc = previews[select.value].html; });
                document.querySelectorAll('.txn-part').forEach((field) => field.addEventListener('input', update));
                update();
            })();
        </script>
    @endPushOnce
</x-admin::layouts>
