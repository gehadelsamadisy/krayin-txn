{{-- Settings > TXN Settings > Safety (TxnSettingsController). One card per guard:
     its switch, its test list and options together. Locked until "Edit safety"
     is confirmed; turning a guard OFF also needs the typed confirmation
     ($confirmText), checked on the server. Guards are never reset to .env. --}}
<x-admin::layouts>
    <x-slot:title>{{ $title }}</x-slot>

    @include('admin::settings.txn.partials.tc-styles')

    <div class="mx-auto flex w-full max-w-4xl flex-col gap-4">
        @include('admin::settings.txn.partials.top')

        <v-txn-safety></v-txn-safety>

        @include('admin::settings.txn.partials.log')
    </div>

    @pushOnce('scripts')
        @php
            $cards = [
                'email' => [
                    'guard' => 'txn.guard_email',
                    'title' => 'Email',
                    'mark' => '@',
                    'on' => 'Emails go only to the allowed test emails below. Anything else is stopped and logged.',
                    'off' => 'Emails go to customers.',
                    'lists' => ['mail_guard.allowed_recipients' => 'One address per line. Used while the guard is on.'],
                    'options' => ['lead_triage.import.store_real_emails' => 'When off (and the guard is on), imported leads get a placeholder email instead of the customer\'s address.'],
                ],
                'sms' => [
                    'guard' => 'txn.guard_sms',
                    'title' => 'Text messages',
                    'mark' => 'Tx',
                    'on' => 'Texts go only to the allowed test phones below. Anything else is stopped and logged.',
                    'off' => 'Texts go to customers.',
                    'lists' => ['sms.allowed_recipients' => 'One number per line, with country code (+1...). Used while the guard is on.'],
                    'options' => [],
                ],
                'calcom' => [
                    'guard' => 'txn.guard_calcom',
                    'title' => 'Booking links',
                    'mark' => 'Ca',
                    'on' => 'Booking links can only be made for the test contacts, and only emailed to the test recipients.',
                    'off' => 'Booking links can be made for any contact with an email address, and go to the customer.',
                    'lists' => [
                        'calcom.test_person_ids' => 'Contact IDs, one per line (the number in the contact page address).',
                        'calcom.test_recipient_emails' => 'Booking-link emails may go only to these addresses. One per line.',
                    ],
                    'options' => [
                        'calcom.allow_customer_email' => 'Lets the test contacts above get a booking link at all.',
                        'calcom.single_test_attempt' => 'Stops after one booking-link email, so a test can\'t be repeated by accident.',
                    ],
                ],
                'yelp' => [
                    'guard' => 'txn.guard_yelp',
                    'title' => 'Yelp replies',
                    'mark' => 'Ye',
                    'on' => 'Replies typed in Krayin go only to the allowed test Yelp chats below. Any other chat gets nothing, and the attempt is logged.',
                    'off' => 'Replies typed in Krayin go to any Yelp customer.',
                    'lists' => ['automation.yelp.test_lead_ids' => 'Yelp lead IDs, one per line. Each chat shows its ID under the reply box (the demo chats are DEMO-1, DEMO-2 and DEMO-3).'],
                    'options' => [],
                ],
            ];
            $listText = fn ($key) => implode("\n", array_map('strval', (array) old($inputNames[$key], $values[$key] ?? [])));
            $initialGuards = collect($cards)->mapWithKeys(fn ($card, $id) => [$id => (bool) $values[$card['guard']]])->all();
        @endphp

        <script type="text/x-template" id="v-txn-safety-template">
            <form
                ref="form"
                method="POST"
                action="{{ route('admin.settings.txn.safety.update') }}"
                class="tc flex flex-col gap-4"
                autocomplete="off"
                @submit="onSubmit"
            >
                @csrf
                @method('PUT')

                <input type="hidden" name="unlocked" :value="locked ? '0' : '1'">

                <!-- Lock bar -->
                <div class="tc-lockbar" :class="{ 'is-open': ! locked }">
                    <span class="tc-lock-icon" aria-hidden="true">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l8 3v6c0 4.5-3.4 8.3-8 9-4.6-.7-8-4.5-8-9V6l8-3z"/></svg>
                    </span>

                    <p class="tc-lock-text" v-if="locked">
                        <strong>Safety settings are locked</strong>
                        <span>{{ \Webkul\Admin\Support\Contact\TxnSettings::guardsOff() ? 'Some messages can reach customers right now.' : 'Every guard is on: customers are never contacted.' }}</span>
                    </p>
                    <p class="tc-lock-text" v-else>
                        <strong>Editing safety settings</strong>
                        <span>Turning a guard off asks you to type {{ $confirmText }}. Saving or reloading locks the page again.</span>
                    </p>

                    @if ($canEdit)
                        <div class="tc-lock-actions">
                            <button type="button" class="primary-button" v-if="locked" @click="unlock">Edit safety</button>
                            <template v-else>
                                <button type="button" class="secondary-button" @click="lock">Lock again</button>
                                <button type="submit" class="primary-button">Save changes</button>
                            </template>
                        </div>
                    @endif
                </div>

                <fieldset :disabled="locked" class="flex flex-col gap-4">
                    @foreach ($cards as $id => $card)
                        @php $guardName = $inputNames[$card['guard']]; @endphp

                        <section class="tc-card" :class="guards.{{ $id }} ? 'tc-guard-on' : 'tc-guard-off'" aria-labelledby="ts-{{ $id }}">
                            <header class="tc-head">
                                <span class="tc-mark" aria-hidden="true">{{ $card['mark'] }}</span>

                                <div class="tc-title">
                                    <h2 id="ts-{{ $id }}">{{ $card['title'] }}</h2>
                                    <p v-if="guards.{{ $id }}">{{ $card['on'] }}</p>
                                    <p v-else>{{ $card['off'] }}</p>
                                </div>

                                <span class="tc-pill" v-text="guards.{{ $id }} ? 'Guarded' : 'Live: reaches customers'"></span>

                                <label class="tc-toggle">
                                    <input type="hidden" name="{{ $guardName }}" value="0">
                                    <input type="checkbox" name="{{ $guardName }}" value="1" v-model="guards.{{ $id }}" aria-describedby="ts-{{ $id }}">
                                    <span class="tc-toggle-track" aria-hidden="true"></span>
                                    <span v-text="guards.{{ $id }} ? 'Guard on' : 'Guard off'"></span>
                                </label>
                            </header>

                            <div class="tc-body">
                                <div class="tc-grid">
                                    @foreach ($card['lists'] as $key => $help)
                                        @php $name = $inputNames[$key]; @endphp

                                        <div class="tc-field">
                                            <label for="f-{{ $name }}" class="tc-label">{{ $fields[$key][2] }}</label>
                                            <textarea id="f-{{ $name }}" name="{{ $name }}" rows="3" spellcheck="false" class="tc-input tc-textarea">{{ $listText($key) }}</textarea>
                                            <p class="tc-help">
                                                {{ $help }}
                                                @if (isset($saved[$key]) && $canEdit)
                                                    <button type="submit" name="reset" value="{{ $key }}" formnovalidate class="tc-link">Use the .env list</button>
                                                @endif
                                            </p>

                                            @if ($key === 'calcom.test_person_ids' && $values[$key])
                                                <div class="tc-people">
                                                    @foreach ($values[$key] as $personId)
                                                        @if (isset($testPeople[$personId]))
                                                            <span class="tc-person">#{{ $personId }} {{ $testPeople[$personId] }}</span>
                                                        @else
                                                            <span class="tc-person is-missing">#{{ $personId }} not found</span>
                                                        @endif
                                                    @endforeach
                                                </div>
                                            @endif
                                        </div>
                                    @endforeach
                                </div>

                                @if ($card['options'])
                                    <div class="tc-options">
                                        @foreach ($card['options'] as $key => $help)
                                            @php $name = $inputNames[$key]; @endphp

                                            <div class="tc-field">
                                                <label class="tc-toggle is-plain">
                                                    <input type="hidden" name="{{ $name }}" value="0">
                                                    <input type="checkbox" name="{{ $name }}" value="1" @checked(old($name, $values[$key] ? '1' : '0') === '1')>
                                                    <span class="tc-toggle-track" aria-hidden="true"></span>
                                                    <span>{{ $fields[$key][2] }}</span>
                                                </label>
                                                <p class="tc-help">{{ $help }}</p>
                                            </div>
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                        </section>
                    @endforeach

                    <!-- Typed confirmation, only while a guard is being turned off -->
                    <div class="tc-confirm" v-if="turningOff.length" role="alert">
                        <strong>Customers will be contacted</strong>
                        <p>
                            You are turning off: <span v-text="turningOff.join(', ')"></span>.
                            After saving, real customers can get these messages. Type <b>{{ $confirmText }}</b> to confirm.
                        </p>
                        <label class="tc-label" for="ts-confirm">Confirmation</label>
                        <input id="ts-confirm" type="text" name="confirm_off" v-model="confirm" class="tc-input" autocomplete="off" spellcheck="false">
                    </div>
                </fieldset>

                <p class="tc-foot">
                    Guards are the safety net while testing. With a guard on, a message to anyone not on its list is stopped and written to the log. If a saved guard can't be read, it counts as on.
                </p>
            </form>
        </script>

        <script type="module">
            app.component('v-txn-safety', {
                template: '#v-txn-safety-template',

                data() {
                    return {
                        locked: true,
                        confirm: '',
                        initial: @json($initialGuards),
                        guards: @json($initialGuards),
                        names: { email: 'email guard', sms: 'SMS guard', calcom: 'booking-link guard', yelp: 'Yelp reply guard' },
                    };
                },

                computed: {
                    /** Guards that are on now and switched off on the form. */
                    turningOff() {
                        return Object.keys(this.guards)
                            .filter(id => this.initial[id] && ! this.guards[id])
                            .map(id => this.names[id]);
                    },
                },

                methods: {
                    unlock() {
                        this.$emitter.emit('open-confirm-modal', {
                            title: 'Edit safety settings',
                            message: 'Are you sure you want to edit the safety settings? Turning a guard off lets real customers get messages.',
                            agree: () => {
                                this.locked = false;
                            },
                        });
                    },

                    lock() {
                        window.location.reload();
                    },

                    /** The server checks the confirmation too; this only saves a round trip. */
                    onSubmit(event) {
                        if (this.turningOff.length && this.confirm.trim() !== @json($confirmText)) {
                            event.preventDefault();
                            this.$emitter.emit('add-flash', { type: 'warning', message: 'Type {{ $confirmText }} to turn the guard off.' });
                        }
                    },
                },
            });
        </script>
    @endPushOnce
</x-admin::layouts>
