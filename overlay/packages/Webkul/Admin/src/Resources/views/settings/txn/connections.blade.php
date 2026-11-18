{{-- Settings > TXN Settings > Connections (TxnSettingsController). One card per
     service: its keys, its Test button and the test result together. Locked
     until "Edit keys" is confirmed; the server also refuses a save or test
     without unlocked=1. Secrets never reach the page ($secrets has only their
     source and last 4 characters). Styles are scoped (.tc): the precompiled
     Tailwind build ignores new classes. --}}
<x-admin::layouts>
    <x-slot:title>{{ $title }}</x-slot>

    @push('styles')
        <style>
            .tc {
                --tc-ink: #1c2b39; --tc-muted: #5f6f7f; --tc-line: #e3e8ee; --tc-panel: #fff; --tc-band: #f6f8fa;
                --tc-field: #fff; --tc-field-off: #f6f8fa; --tc-brand: #0e90d9; --tc-brand-soft: #e3f2fb;
                --tc-ok: #1f9d6b; --tc-ok-soft: #e4f5ee; --tc-warn: #c9822b; --tc-warn-soft: #fbf1e3;
                --tc-bad: #d1453b; --tc-bad-soft: #fbe9e7; --tc-idle: #c3ccd5;
                color: var(--tc-ink); font-size: 14px;
            }
            .dark .tc {
                --tc-ink: #e6ebf0; --tc-muted: #9aa8b5; --tc-line: #243041; --tc-panel: #111827; --tc-band: #161f2e;
                --tc-field: #0b1220; --tc-field-off: #131c2b; --tc-brand: #38a9e8; --tc-brand-soft: #0f2c40;
                --tc-ok: #3cc28f; --tc-ok-soft: #10302a; --tc-warn: #e3a356; --tc-warn-soft: #33260f;
                --tc-bad: #f07167; --tc-bad-soft: #3a1714; --tc-idle: #3a4759;
            }

            /* Lock bar: always reachable while scrolling, carries Save. */
            .tc-lockbar {
                position: sticky; top: 72px; z-index: 5;
                display: flex; align-items: center; gap: 14px; flex-wrap: wrap;
                padding: 12px 16px; border: 1px solid var(--tc-line); border-radius: 10px; background: var(--tc-panel);
            }
            .tc-lockbar.is-open { border-color: var(--tc-warn); background: var(--tc-warn-soft); }
            .tc-lock-icon { flex: 0 0 auto; width: 34px; height: 34px; border-radius: 9px; display: grid; place-items: center; background: var(--tc-band); color: var(--tc-muted); }
            .tc-lockbar.is-open .tc-lock-icon { background: var(--tc-panel); color: var(--tc-warn); }
            .tc-lock-text { flex: 1 1 260px; line-height: 1.4; }
            .tc-lock-text strong { display: block; font-size: 15px; font-weight: 600; }
            .tc-lock-text span { color: var(--tc-muted); font-size: 13px; }
            .tc-lock-actions { display: flex; gap: 8px; }

            /* Service card: the rail on the left is the state (ready, key missing, can't read, test result). */
            .tc-card {
                position: relative; overflow: hidden;
                border: 1px solid var(--tc-line); border-radius: 12px; background: var(--tc-panel);
            }
            .tc-card::before { content: ""; position: absolute; inset: 0 auto 0 0; width: 4px; background: var(--tc-rail, var(--tc-idle)); transition: background .25s; }
            .tc-state-ready { --tc-rail: var(--tc-ok); }
            .tc-state-missing { --tc-rail: var(--tc-warn); }
            .tc-state-bad, .tc-state-failed { --tc-rail: var(--tc-bad); }
            .tc-state-passed { --tc-rail: var(--tc-ok); }

            .tc-head { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; padding: 16px 20px 16px 24px; background: var(--tc-band); border-bottom: 1px solid var(--tc-line); }
            .tc-mark { flex: 0 0 auto; width: 40px; height: 40px; border-radius: 10px; display: grid; place-items: center; background: var(--tc-brand-soft); color: var(--tc-brand); font-weight: 700; font-size: 14px; letter-spacing: -.01em; }
            .tc-title { flex: 1 1 220px; min-width: 0; }
            .tc-title h2 { margin: 0; font-size: 17px; font-weight: 600; line-height: 1.3; }
            .tc-title p { margin: 2px 0 0; color: var(--tc-muted); font-size: 13px; line-height: 1.4; }

            .tc-pill { display: inline-flex; align-items: center; gap: 6px; padding: 3px 10px; border-radius: 999px; font-size: 12px; font-weight: 600; white-space: nowrap; background: var(--tc-panel); border: 1px solid var(--tc-line); color: var(--tc-muted); }
            .tc-pill::before { content: ""; width: 7px; height: 7px; border-radius: 50%; background: var(--tc-rail, var(--tc-idle)); }

            .tc-test {
                display: inline-flex; align-items: center; gap: 7px; padding: 7px 14px; border-radius: 8px;
                border: 1px solid var(--tc-brand); background: transparent; color: var(--tc-brand);
                font-size: 13px; font-weight: 600; cursor: pointer; white-space: nowrap;
            }
            .tc-test:hover:not(:disabled) { background: var(--tc-brand); color: #fff; }
            .tc-test:disabled { border-color: var(--tc-line); color: var(--tc-muted); cursor: not-allowed; }
            .tc-spin { width: 13px; height: 13px; border: 2px solid currentColor; border-right-color: transparent; border-radius: 50%; animation: tc-spin .7s linear infinite; }
            @keyframes tc-spin { to { transform: rotate(360deg); } }

            .tc-result { display: flex; gap: 10px; align-items: flex-start; margin: 0; padding: 11px 20px 11px 24px; font-size: 13px; line-height: 1.45; border-bottom: 1px solid var(--tc-line); }
            .tc-result.is-ok { background: var(--tc-ok-soft); color: var(--tc-ok); }
            .tc-result.is-fail { background: var(--tc-bad-soft); color: var(--tc-bad); }
            .tc-result strong { font-weight: 600; }

            .tc-body { padding: 20px 20px 20px 24px; display: flex; flex-direction: column; gap: 18px; }
            .tc-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px 18px; }
            .tc-wide { grid-column: 1 / -1; }
            @media (max-width: 720px) { .tc-grid { grid-template-columns: 1fr; } }

            .tc-field { display: flex; flex-direction: column; gap: 6px; min-width: 0; }
            .tc-label-row { display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; }
            .tc-label { font-size: 13px; font-weight: 600; }
            .tc-input {
                width: 100%; padding: 8px 11px; border: 1px solid var(--tc-line); border-radius: 8px;
                background: var(--tc-field); color: var(--tc-ink); font-size: 14px; line-height: 1.4;
            }
            .tc-input::placeholder { color: var(--tc-muted); opacity: .8; }
            .tc-input:focus { outline: none; border-color: var(--tc-brand); box-shadow: 0 0 0 3px var(--tc-brand-soft); }
            .tc-input:disabled { background: var(--tc-field-off); color: var(--tc-muted); cursor: not-allowed; }
            .tc-help { margin: 0; color: var(--tc-muted); font-size: 12px; line-height: 1.45; min-height: 0; }
            .tc-help:empty { display: none; }
            .tc-changed { color: var(--tc-brand); font-weight: 600; }

            .tc-source { display: inline-flex; align-items: center; gap: 6px; flex-wrap: wrap; font-size: 12px; }
            .tc-badge { padding: 1px 8px; border-radius: 999px; font-size: 11.5px; font-weight: 600; }
            .tc-badge-saved { background: var(--tc-brand-soft); color: var(--tc-brand); }
            .tc-badge-env { background: var(--tc-band); color: var(--tc-muted); border: 1px solid var(--tc-line); }
            .tc-badge-none { background: var(--tc-warn-soft); color: var(--tc-warn); }
            .tc-badge-bad { background: var(--tc-bad-soft); color: var(--tc-bad); }
            .tc-ends { color: var(--tc-muted); font-variant-numeric: tabular-nums; }

            .tc-link { align-self: flex-start; padding: 0; border: 0; background: none; color: var(--tc-brand); font-size: 12px; font-weight: 600; cursor: pointer; }
            .tc-link:hover:not(:disabled) { text-decoration: underline; }
            .tc-link:disabled { display: none; }

            .tc-note { display: flex; gap: 8px; margin: 0; padding: 10px 12px; border-radius: 8px; background: var(--tc-band); color: var(--tc-muted); font-size: 12.5px; line-height: 1.45; }
            .tc-note svg { flex: 0 0 auto; margin-top: 1px; color: var(--tc-warn); }

            /* Email: which service sends, as a two-way switch. */
            .tc-switch-row { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; padding-top: 4px; border-top: 1px dashed var(--tc-line); padding-top: 18px; }
            .tc-switch { display: inline-flex; padding: 3px; border-radius: 10px; background: var(--tc-band); border: 1px solid var(--tc-line); }
            .tc-switch label { position: relative; cursor: pointer; }
            .tc-switch input { position: absolute; opacity: 0; pointer-events: none; }
            .tc-switch span { display: block; padding: 6px 16px; border-radius: 8px; font-size: 13px; font-weight: 600; color: var(--tc-muted); transition: background .15s, color .15s; }
            .tc-switch input:checked + span { background: var(--tc-panel); color: var(--tc-ink); box-shadow: 0 1px 2px rgba(16, 24, 40, .12); }
            .tc-switch input:focus-visible + span { outline: 2px solid var(--tc-brand); outline-offset: 1px; }
            .tc-switch input:disabled + span { cursor: not-allowed; }
            .tc-mailer { display: flex; flex-direction: column; gap: 14px; }
            .tc-mailer-hint { margin: 0; color: var(--tc-muted); font-size: 12.5px; }

            .tc-foot { color: var(--tc-muted); font-size: 12px; line-height: 1.5; padding: 0 4px; }
            .tc button:focus-visible, .tc a:focus-visible { outline: 2px solid var(--tc-brand); outline-offset: 2px; }
            @media (prefers-reduced-motion: reduce) { .tc-spin { animation: none; } .tc-card::before, .tc-switch span { transition: none; } }
        </style>
    @endpush

    <div class="mx-auto flex w-full max-w-4xl flex-col gap-4">
        @include('admin::settings.txn.partials.top')

        <v-txn-connections></v-txn-connections>

        @include('admin::settings.txn.partials.log')
    </div>

    @pushOnce('scripts')
        @php
            // A card's state before any test: a saved key that can't be read, a missing key, or ready.
            $stateOf = function (array $keys) use ($secrets) {
                $state = 'ready';

                foreach ($keys as $key) {
                    if (isset($secrets[$key])) {
                        if ($secrets[$key]['unreadable']) {
                            return 'bad';
                        }

                        if ($secrets[$key]['source'] === 'none') {
                            $state = 'missing';
                        }
                    }
                }

                return $state;
            };

            $email = $groups['email'];
            $mailerState = collect($email['mailers'])->map(fn ($mailer) => $stateOf(array_merge($email['keys'], $mailer['keys'])))->all();
            $fieldData = compact('fields', 'values', 'defaults', 'saved', 'secrets', 'inputNames', 'canEdit');
        @endphp

        <script type="text/x-template" id="v-txn-connections-template">
            <form
                ref="form"
                method="POST"
                action="{{ route('admin.settings.txn.connections.update') }}"
                class="tc flex flex-col gap-4"
                autocomplete="off"
            >
                @csrf
                @method('PUT')

                <input type="hidden" name="unlocked" :value="locked ? '0' : '1'">

                <!-- Lock bar -->
                <div class="tc-lockbar" :class="{ 'is-open': ! locked }">
                    <span class="tc-lock-icon" aria-hidden="true">
                        <svg v-if="locked" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>
                        <svg v-else width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 7.5-2"/></svg>
                    </span>

                    <p class="tc-lock-text" v-if="locked">
                        <strong>Keys are locked</strong>
                        <span>You can see where each key comes from. Unlock to change or test them.</span>
                    </p>
                    <p class="tc-lock-text" v-else>
                        <strong>Editing keys</strong>
                        <span>Leave a key empty to keep it. Saving or reloading locks the page again.</span>
                    </p>

                    @if ($canEdit)
                        <div class="tc-lock-actions">
                            <button type="button" class="primary-button" v-if="locked" @click="unlock">Edit keys</button>
                            <template v-else>
                                <button type="button" class="secondary-button" @click="lock">Lock again</button>
                                <button type="submit" class="primary-button">Save changes</button>
                            </template>
                        </div>
                    @endif
                </div>

                <fieldset :disabled="locked" class="flex flex-col gap-4">
                    @foreach ($groups as $id => $group)
                        @php
                            $isEmail = $id === 'email';
                            $state = $isEmail ? null : $stateOf($group['keys']);
                        @endphp

                        <section
                            class="tc-card"
                            :class="'tc-state-' + {!! $isEmail ? 'cardState(mailerTest, mailerState[mailer])' : "cardState('{$group['test']}', '{$state}')" !!}"
                            aria-labelledby="tc-{{ $id }}"
                        >
                            <header class="tc-head">
                                <span class="tc-mark" aria-hidden="true">{{ $group['mark'] }}</span>

                                <div class="tc-title">
                                    <h2 id="tc-{{ $id }}">{{ $group['title'] }}</h2>
                                    <p>{{ $group['purpose'] }}</p>
                                </div>

                                <span
                                    class="tc-pill"
                                    v-text="stateText[{!! $isEmail ? 'cardState(mailerTest, mailerState[mailer])' : "cardState('{$group['test']}', '{$state}')" !!}]"
                                ></span>

                                @if ($canEdit)
                                    <button
                                        type="button"
                                        class="tc-test"
                                        :disabled="locked || testing[{!! $isEmail ? 'mailerTest' : "'{$group['test']}'" !!}]"
                                        :title="locked ? 'Click Edit keys first' : ''"
                                        @click="test({!! $isEmail ? 'mailerTest' : "'{$group['test']}'" !!})"
                                    >
                                        <span class="tc-spin" v-if="testing[{!! $isEmail ? 'mailerTest' : "'{$group['test']}'" !!}]" aria-hidden="true"></span>
                                        @if ($isEmail)
                                            <span v-text="'Test ' + mailerNames[mailer]"></span>
                                        @else
                                            <span>Test connection</span>
                                        @endif
                                    </button>
                                @endif
                            </header>

                            <p
                                class="tc-result"
                                :class="result({!! $isEmail ? 'mailerTest' : "'{$group['test']}'" !!}).ok ? 'is-ok' : 'is-fail'"
                                v-if="result({!! $isEmail ? 'mailerTest' : "'{$group['test']}'" !!})"
                                role="status"
                            >
                                <strong v-text="result({!! $isEmail ? 'mailerTest' : "'{$group['test']}'" !!}).ok ? 'Works.' : 'Failed.'"></strong>
                                <span v-text="result({!! $isEmail ? 'mailerTest' : "'{$group['test']}'" !!}).message"></span>
                            </p>

                            <div class="tc-body">
                                <div class="tc-grid">
                                    @foreach ($group['keys'] as $key)
                                        @include('admin::settings.txn.partials.connection-field', $fieldData + ['key' => $key])
                                    @endforeach
                                </div>

                                @if ($isEmail)
                                    <div class="tc-switch-row">
                                        <span class="tc-label" id="tc-mailer-label">Sent with</span>

                                        <div class="tc-switch" role="radiogroup" aria-labelledby="tc-mailer-label">
                                            @foreach (\Webkul\Admin\Support\Contact\TxnSettings::MAILERS as $mailerKey => $mailerName)
                                                <label>
                                                    <input type="radio" name="{{ $inputNames['mail.default'] }}" value="{{ $mailerKey }}" v-model="mailer">
                                                    <span>{{ $mailerName }}</span>
                                                </label>
                                            @endforeach
                                        </div>

                                        @if (isset($saved['mail.default']) && $canEdit)
                                            <button type="submit" name="reset" value="mail.default" formnovalidate class="tc-link">Use the .env mailer</button>
                                        @endif
                                    </div>

                                    @foreach ($group['mailers'] as $mailerKey => $mailer)
                                        <div class="tc-mailer" v-show="mailer === '{{ $mailerKey }}'">
                                            <p class="tc-mailer-hint">{{ $mailer['hint'] }}</p>

                                            <div class="tc-grid">
                                                @foreach ($mailer['keys'] as $key)
                                                    @include('admin::settings.txn.partials.connection-field', $fieldData + ['key' => $key])
                                                @endforeach
                                            </div>
                                        </div>
                                    @endforeach
                                @endif

                                @if ($group['note'])
                                    <p class="tc-note">
                                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16.5v.01"/></svg>
                                        <span>{{ $group['note'] }}</span>
                                    </p>
                                @endif
                            </div>
                        </section>
                    @endforeach
                </fieldset>

                <p class="tc-foot">
                    Keys are stored encrypted with Krayin's APP_KEY. If APP_KEY changes, saved keys can't be read and the .env keys are used until you enter them again. Tests only log in: they never send a text or an email.
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
                        mailer: @json(old($inputNames['mail.default'], $values['mail.default'])),
                        mailerNames: @json(\Webkul\Admin\Support\Contact\TxnSettings::MAILERS),
                        mailerState: @json($mailerState),
                        stateText: {
                            ready: 'Ready',
                            missing: 'Key missing',
                            bad: "Saved key can't be read",
                            passed: 'Test passed',
                            failed: 'Test failed',
                        },
                    };
                },

                computed: {
                    /** The Email card tests the mailer that is switched on. */
                    mailerTest() {
                        return this.mailer;
                    },
                },

                methods: {
                    result(name) {
                        return this.results[name] || null;
                    },

                    /** A test result wins over the state the page opened with. */
                    cardState(name, opened) {
                        const result = this.result(name);

                        return result ? (result.ok ? 'passed' : 'failed') : (opened || 'ready');
                    },

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
