{{-- Settings > TXN Settings > Connections (TxnSettingsController). One card per
     service: its keys, its Test button and the test result together. Locked
     until "Edit keys" is confirmed; the server also refuses a save or test
     without unlocked=1. Secrets never reach the page ($secrets has only their
     source and last 4 characters). Styles are scoped (.tc): the precompiled
     Tailwind build ignores new classes. --}}
<x-admin::layouts>
    <x-slot:title>{{ $title }}</x-slot>

    @include('admin::settings.txn.partials.tc-styles')

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

            // A recorded problem (ServiceHealth) wins: "failing" (red) or "nokey" (amber).
            $problemState = fn (string $card) => match ($problems[$card]['state'] ?? null) {
                'failed' => 'failing',
                'no_key' => 'nokey',
                default => null,
            };

            $email = $groups['email'];
            $mailerState = collect($email['mailers'])->map(fn ($mailer) => $stateOf(array_merge($email['keys'], $mailer['keys'])))->all();
            // An email problem belongs to the mailer that was sending.
            if (($emailProblem = $problemState('email')) && isset($mailerState[$values['mail.default']])) {
                $mailerState[$values['mail.default']] = $emailProblem;
            }
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
                            $state = $isEmail ? null : ($problemState($id) ?? $stateOf($group['keys']));
                            $problem = $problems[$id] ?? null;
                            $cardData = $fieldData + ['problem' => $problem];
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

                            {{-- What Krayin saw when it last used this service (until a Test result replaces it). --}}
                            @if ($problem)
                                <template v-if="! result({!! $isEmail ? 'mailerTest' : "'{$group['test']}'" !!})">
                                    <div class="tc-problem {{ $problem['state'] === 'no_key' ? 'is-nokey' : 'is-failed' }}" role="alert" v-pre>
                                        <strong>{{ $problem['state'] === 'no_key' ? 'No key assigned.' : 'Not working.' }}</strong>
                                        <span class="tc-problem-when">Seen {{ \Carbon\Carbon::parse($problem['failed_at'])->format('M j, g:i A') }}</span>
                                        <p>{{ $problem['message'] }}</p>
                                    </div>
                                </template>
                            @endif

                            <div class="tc-body">
                                <div class="tc-grid">
                                    @foreach ($group['keys'] as $key)
                                        @include('admin::settings.txn.partials.connection-field', $cardData + ['key' => $key])
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
                                                    @include('admin::settings.txn.partials.connection-field', $cardData + ['key' => $key])
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
                            failing: 'Not working',
                            nokey: 'No key',
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
