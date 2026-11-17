{{-- Settings > TXN Settings > Emails (TxnSettingsController). The preview is
     the real email template rendered with a sample customer; the script below
     puts the text being typed into it (same rules as BookingEmail: placeholders,
     **bold**, empty parts hidden, signature lines with empty placeholders left out). --}}
<x-admin::layouts>
    <x-slot:title>{{ $title }}</x-slot>

    @php
        $groups = \Webkul\Admin\Support\Contact\TxnSettings::EMAIL_PARTS;
        $input = 'w-full rounded border border-gray-300 px-2 py-1.5 text-sm dark:border-gray-700 dark:bg-gray-800 dark:text-white';
    @endphp

    <div class="mx-auto flex w-full max-w-5xl flex-col gap-4">
        @include('admin::settings.txn.partials.top')

        <div class="rounded-lg border border-gray-200 bg-white p-4 text-sm dark:border-gray-800 dark:bg-gray-900">
            <p class="font-semibold dark:text-white">Placeholders</p>
            <p class="text-gray-500">Type these in any part; each email fills in the real values. Put **two stars** around words to make them bold. An empty part is left out of the email.</p>
            <div class="mt-2 grid gap-1 md:grid-cols-2">
                @foreach ($placeholders as $name => $meaning)
                    <p class="text-gray-600 dark:text-gray-300"><code>{{ '{'.$name.'}' }}</code>: {{ $meaning }}</p>
                @endforeach
            </div>
        </div>

        <div class="grid gap-4 lg:grid-cols-2">
            <form method="POST" action="{{ route('admin.settings.txn.emails.update') }}" class="flex flex-col gap-6 rounded-lg border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-gray-900">
                @csrf
                @method('PUT')

                @foreach ($groups as $group => [$groupTitle, $parts])
                    <section class="flex flex-col gap-3">
                        <div class="flex items-center justify-between gap-3">
                            <h2 class="text-lg font-semibold dark:text-white">{{ $groupTitle }}</h2>
                            @if ($canEdit)
                                <button type="submit" name="reset" value="emails:{{ $group }}" formnovalidate class="text-xs text-blue-600 hover:underline" onclick="return confirm('Put this email back to its default wording?')">Reset this email</button>
                            @endif
                        </div>

                        @foreach ($parts as $part => [$label, $oneLine, $required])
                            @php
                                $key = "lead_messages.templates.{$group}.{$part}";
                                $value = old("parts.{$group}.{$part}", $values[$key]);
                            @endphp
                            <div>
                                <label for="p-{{ $group }}-{{ $part }}" class="mb-1 block text-sm font-medium dark:text-gray-200">{{ $label }}{{ $required ? '' : ' (can be empty)' }}</label>
                                @if ($oneLine)
                                    <input id="p-{{ $group }}-{{ $part }}" type="text" name="parts[{{ $group }}][{{ $part }}]" value="{{ $value }}" maxlength="300"
                                        data-group="{{ $group }}" data-part="{{ $part }}" class="txn-part {{ $input }}" @disabled(! $canEdit)>
                                @else
                                    <textarea id="p-{{ $group }}-{{ $part }}" name="parts[{{ $group }}][{{ $part }}]" rows="{{ $part === 'signature' ? 4 : 3 }}" maxlength="3000"
                                        data-group="{{ $group }}" data-part="{{ $part }}" class="txn-part {{ $input }}" @disabled(! $canEdit)>{{ $value }}</textarea>
                                @endif
                                @include('admin::settings.txn.partials.field-meta', ['key' => $key, 'defaultText' => (string) $defaults[$key]])
                            </div>
                        @endforeach
                    </section>
                @endforeach

                @if ($canEdit)
                    <div class="flex justify-between gap-3">
                        <button type="submit" name="reset" value="all" formnovalidate class="secondary-button" onclick="return confirm('Put all three emails back to their default wording?')">Reset all emails</button>
                        <button type="submit" class="primary-button">Save</button>
                    </div>
                @endif
            </form>

            <div class="flex flex-col gap-2 rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 class="text-lg font-semibold dark:text-white">Preview</h2>
                    <select id="preview-kind" class="rounded border border-gray-300 px-2 py-1 text-sm dark:border-gray-700 dark:bg-gray-800 dark:text-white" aria-label="Which email to preview">
                        @foreach ($previews as $id => $preview)
                            <option value="{{ $id }}">{{ $preview['label'] }}</option>
                        @endforeach
                    </select>
                </div>
                <p class="text-sm text-gray-600 dark:text-gray-300">Subject: <strong id="preview-subject"></strong></p>
                <p class="text-xs text-gray-500">Sample customer "Jane", gutter service, sample times. Updates while you type; Save to keep it.</p>
                <iframe id="email-preview" title="Email preview" srcdoc="{{ $previews['link']['html'] }}" class="w-full rounded border border-gray-300 bg-white" style="height: 760px;"></iframe>
            </div>
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

                frame.addEventListener('load', update);
                select.addEventListener('change', () => { frame.srcdoc = previews[select.value].html; });
                document.querySelectorAll('.txn-part').forEach((field) => field.addEventListener('input', update));
                update();
            })();
        </script>
    @endPushOnce
</x-admin::layouts>
