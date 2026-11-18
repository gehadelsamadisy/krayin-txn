{{-- Settings > TXN Settings > Connections (TxnSettingsController). Locked until
     "Edit keys" is confirmed; the server also refuses a save or test without
     unlocked=1. Secrets are never put on the page: only where they come from
     and their last 4 characters ($secrets). --}}
<x-admin::layouts>
    <x-slot:title>{{ $title }}</x-slot>

    @push('styles')
        <style>
            .txn-conn-badge { display: inline-block; padding: 1px 8px; border-radius: 9999px; font-size: 11px; font-weight: 600; }
            .txn-conn-saved { background: #dbeafe; color: #1d4ed8; }
            .txn-conn-env { background: #f3f4f6; color: #374151; }
            .txn-conn-none { background: #fef3c7; color: #92400e; }
            .txn-conn-bad { background: #fee2e2; color: #b91c1c; }
            .dark .txn-conn-saved { background: #1e3a8a; color: #dbeafe; }
            .dark .txn-conn-env { background: #1f2937; color: #e5e7eb; }
            .dark .txn-conn-none { background: #78350f; color: #fef3c7; }
            .dark .txn-conn-bad { background: #7f1d1d; color: #fee2e2; }
            .txn-conn-locked input, .txn-conn-locked select { background: #f9fafb; cursor: not-allowed; }
            .dark .txn-conn-locked input, .dark .txn-conn-locked select { background: #111827; }
            .txn-conn-result { margin-top: 8px; padding: 8px 10px; border-radius: 6px; font-size: 13px; }
            .txn-conn-ok { background: #dcfce7; color: #166534; }
            .txn-conn-fail { background: #fee2e2; color: #991b1b; }
            .dark .txn-conn-ok { background: #14532d; color: #dcfce7; }
            .dark .txn-conn-fail { background: #7f1d1d; color: #fee2e2; }
            .txn-conn-lockbar { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
        </style>
    @endpush

    <div class="mx-auto flex w-full max-w-4xl flex-col gap-4">
        @include('admin::settings.txn.partials.top')

        <v-txn-connections></v-txn-connections>

        @include('admin::settings.txn.partials.log')
    </div>

    @pushOnce('scripts')
        <script type="text/x-template" id="v-txn-connections-template">
            @php
                $input = 'w-full rounded border border-gray-300 px-2 py-1.5 dark:border-gray-700 dark:bg-gray-800 dark:text-white';
                $sourceText = ['saved' => 'Saved in Krayin', 'env' => 'From .env', 'none' => 'Not set'];
            @endphp

            <form
                ref="form"
                method="POST"
                action="{{ route('admin.settings.txn.connections.update') }}"
                class="flex flex-col gap-4"
                autocomplete="off"
            >
                @csrf
                @method('PUT')

                <input type="hidden" name="unlocked" :value="locked ? '0' : '1'">

                <!-- Lock bar -->
                <div class="txn-conn-lockbar rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
                    <p class="text-sm dark:text-gray-200" v-if="locked">
                        <strong>Locked.</strong> These keys can only be read. Click "Edit keys" to change or test them.
                    </p>
                    <p class="text-sm text-amber-700 dark:text-amber-300" v-else>
                        <strong>Editing keys.</strong> Leave a secret empty to keep it. Saving or reloading locks the page again.
                    </p>

                    @if ($canEdit)
                        <div class="flex gap-2">
                            <button type="button" class="primary-button" v-if="locked" @click="unlock">Edit keys</button>
                            <template v-else>
                                <button type="button" class="secondary-button" @click="lock">Lock again</button>
                                <button type="submit" class="primary-button">Save</button>
                            </template>
                        </div>
                    @endif
                </div>

                <fieldset :disabled="locked" :class="{ 'txn-conn-locked': locked }" class="flex flex-col gap-4">
                    @foreach ($groups as $group => [$groupTitle, $note, $test, $groupKeys])
                        <div class="flex flex-col gap-4 rounded-lg border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-gray-900">
                            <div>
                                <h2 class="text-lg font-semibold dark:text-white">{{ $groupTitle }}</h2>
                                <p class="text-sm text-gray-500">{{ $note }}</p>
                            </div>

                            @foreach ($groupKeys as $key)
                                @php
                                    [, $type, $label, $options] = $fields[$key];
                                    $name = $inputNames[$key];
                                @endphp

                                <div>
                                    <label for="f-{{ $name }}" class="mb-1 block text-sm font-medium dark:text-gray-200">{{ $label }}</label>

                                    @if ($type === 'secret')
                                        @php $status = $secrets[$key]; @endphp

                                        <div class="mb-1 flex flex-wrap items-center gap-2 text-xs text-gray-500">
                                            @if ($status['unreadable'])
                                                <span class="txn-conn-badge txn-conn-bad">Saved key can't be read: enter it again</span>
                                            @endif
                                            <span class="txn-conn-badge txn-conn-{{ $status['source'] }}">{{ $sourceText[$status['source']] }}</span>
                                            @if ($status['source'] !== 'none')
                                                <span>{{ $status['ends'] }}</span>
                                            @endif
                                            @if ($canEdit && $status['source'] === 'saved')
                                                <button
                                                    type="submit"
                                                    name="reset"
                                                    value="{{ $key }}"
                                                    formnovalidate
                                                    class="text-blue-600 hover:underline"
                                                    onclick="return confirm('Delete the key saved in Krayin and use the one in .env?')"
                                                >Use .env value</button>
                                            @endif
                                        </div>

                                        <input
                                            id="f-{{ $name }}"
                                            type="password"
                                            name="{{ $name }}"
                                            value=""
                                            autocomplete="new-password"
                                            placeholder="{{ $status['source'] === 'none' ? 'Enter the key' : 'Leave empty to keep the current key' }}"
                                            class="{{ $input }}"
                                        >
                                    @elseif ($type === 'choice')
                                        <select id="f-{{ $name }}" name="{{ $name }}" class="{{ $input }}">
                                            @foreach ($options['choices'] as $choice => $choiceLabel)
                                                <option value="{{ $choice }}" @selected(old($name, $values[$key]) === $choice)>{{ $choiceLabel }}</option>
                                            @endforeach
                                        </select>
                                        @include('admin::settings.txn.partials.field-meta', ['key' => $key, 'defaultText' => $options['choices'][$defaults[$key]] ?? (string) $defaults[$key]])
                                    @else
                                        <input
                                            id="f-{{ $name }}"
                                            type="{{ $type === 'port' ? 'number' : ($type === 'email' ? 'email' : 'text') }}"
                                            name="{{ $name }}"
                                            value="{{ old($name, $values[$key]) }}"
                                            class="{{ $input }}"
                                            spellcheck="false"
                                        >
                                        @if (! empty($options['hint']))
                                            <p class="mt-1 text-xs text-gray-500">Format: {{ $options['hint'] }}.</p>
                                        @endif
                                        @include('admin::settings.txn.partials.field-meta', ['key' => $key])
                                    @endif
                                </div>
                            @endforeach

                            @if ($test && $canEdit)
                                <div>
                                    <button
                                        type="button"
                                        class="secondary-button"
                                        :disabled="testing['{{ $test }}']"
                                        @click="test('{{ $test }}')"
                                    >
                                        <span v-if="testing['{{ $test }}']">Testing…</span>
                                        <span v-else>Test {{ $groupTitle }}</span>
                                    </button>

                                    <div
                                        class="txn-conn-result"
                                        :class="results['{{ $test }}'].ok ? 'txn-conn-ok' : 'txn-conn-fail'"
                                        v-if="results['{{ $test }}']"
                                        v-text="results['{{ $test }}'].message"
                                        role="status"
                                    ></div>
                                </div>
                            @endif
                        </div>
                    @endforeach
                </fieldset>

                <p class="text-xs text-gray-500">
                    Keys are stored encrypted with Krayin's APP_KEY. If APP_KEY ever changes, saved keys can't be read and the .env values are used until they are entered again.
                </p>
            </form>
        </script>

        <script type="module">
            app.component('v-txn-connections', {
                template: '#v-txn-connections-template',

                data() {
                    return {
                        locked: true,
                        testing: {},
                        results: {},
                    };
                },

                methods: {
                    unlock() {
                        this.$emitter.emit('open-confirm-modal', {
                            title: 'Edit API keys',
                            message: 'Are you sure you want to edit the API keys and tokens?',
                            agree: () => {
                                this.locked = false;
                            },
                        });
                    },

                    lock() {
                        window.location.reload();
                    },

                    test(name) {
                        const data = new FormData(this.$refs.form);
                        data.delete('_method');
                        data.set('service', name);

                        this.testing[name] = true;
                        this.results[name] = null;

                        this.$axios.post(@json(route('admin.settings.txn.connections.test')), data)
                            .then(response => {
                                this.results[name] = response.data;
                            })
                            .catch(error => {
                                this.results[name] = {
                                    ok: false,
                                    message: error.response?.data?.message || 'The test could not run. Reload the page and try again.',
                                };
                            })
                            .finally(() => {
                                this.testing[name] = false;
                            });
                    },
                },
            });
        </script>
    @endPushOnce
</x-admin::layouts>
