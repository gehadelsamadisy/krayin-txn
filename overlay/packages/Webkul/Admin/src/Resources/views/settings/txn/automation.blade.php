{{-- Settings > TXN Settings > Automation (TxnSettingsController, Support\Automation). Everything ships off.
     Styles: partials/tc-styles (.tc, scoped). --}}
<x-admin::layouts>
    <x-slot:title>{{ $title }}</x-slot>

    @php
        $common = compact('inputNames', 'values', 'fields', 'canEdit', 'saved', 'defaults');
        $master = ! empty($values['automation.enabled']);
        $engineOn = fn (string $engine) => $master && ! empty($values["automation.{$engine}.enabled"]);
        $multi = function (string $key, array $choices) use ($inputNames, $values) {
            return ['name' => $inputNames[$key], 'choices' => $choices, 'checked' => (array) old($inputNames[$key], (array) $values[$key])];
        };
        $flows = $multi('automation.email.flows', \Webkul\Admin\Support\Contact\TxnSettings::FLOWS);
        $events = $multi('automation.staff.events', $staffEvents);
    @endphp

    <div class="mx-auto flex w-full max-w-4xl flex-col gap-4">
        @include('admin::settings.txn.partials.top')

        <form method="POST" action="{{ route('admin.settings.txn.automation.update') }}" class="tc tc-form" autocomplete="off">
            @csrf
            @method('PUT')

            {{-- Master switch --}}
            <section class="tc-card {{ $master ? 'tc-guard-on' : '' }}">
                <div class="tc-head">
                    <span class="tc-mark">@include('admin::settings.txn.partials.icon', ['name' => 'sparkle'])</span>
                    <div class="tc-title">
                        <h2>Automation</h2>
                        <p>{{ $master ? 'On: the engines switched on below work on their own.' : 'Off: nothing is sent automatically, whatever the switches below say. Staff buttons work as always.' }}</p>
                    </div>
                    <span class="tc-status {{ $master ? 'is-on' : 'is-off' }}">{{ $master ? 'Running' : 'Stopped' }}</span>
                </div>
                <div class="tc-body">
                    @include('admin::settings.txn.partials.switch', $common + ['key' => 'automation.enabled', 'switchLabel' => 'Master switch', 'help' => 'Turns every engine on or off at once. The Safety guards still decide who can receive anything.', 'plain' => false])
                </div>
            </section>

            {{-- Email engine --}}
            <section class="tc-card {{ $engineOn('email') ? 'tc-state-ready' : 'is-plain' }}">
                @include('admin::settings.txn.partials.card-head', [
                    'icon' => 'mail',
                    'title' => $engines['email'][0],
                    'text' => $engines['email'][1],
                    'aside' => '<span class="tc-status '.($engineOn('email') ? 'is-on">Working' : 'is-off">Off').'</span>',
                ])
                <div class="tc-body">
                    @include('admin::settings.txn.partials.switch', $common + ['key' => 'automation.email.enabled', 'switchLabel' => 'Email engine on'])

                    <div class="tc-options">
                        <span class="tc-label">Booking link</span>
                        @include('admin::settings.txn.partials.switch', $common + ['key' => 'automation.email.send_link', 'help' => 'Suggests the best times, creates the Cal.com link, emails the customer and moves the lead to Contacted. Never twice for the same lead.'])
                        @include('admin::settings.txn.partials.switch', $common + ['key' => 'automation.email.when_fixed', 'help' => 'When staff fix a lead and the check passes, the link goes out then. Off: only brand-new leads.'])

                        <div class="tc-field">
                            <span class="tc-label">{{ $fields['automation.email.flows'][2] }}</span>
                            <input type="hidden" name="{{ $flows['name'] }}_sent" value="1">
                            <div class="tc-chips">
                                @foreach ($flows['choices'] as $flow => $label)
                                    <label class="tc-chip is-muted" style="cursor: pointer;">
                                        <input type="checkbox" name="{{ $flows['name'] }}[]" value="{{ $flow }}" @checked(in_array($flow, $flows['checked'], true)) @disabled(! $canEdit)> {{ $label }}
                                    </label>
                                @endforeach
                            </div>
                            <p class="tc-help">Roof leads wait for the roof report, so "Roof report first" is normally left out.</p>
                            @include('admin::settings.txn.partials.field-meta', ['key' => 'automation.email.flows'])
                        </div>
                    </div>

                    <div class="tc-options">
                        <span class="tc-label">Follow-ups <span class="tc-sublabel">(hours on <a href="{{ route('admin.settings.txn.follow_ups') }}" style="color: var(--tc-brand);">Follow-ups</a>; sent by the clock, see below)</span></span>
                        @include('admin::settings.txn.partials.switch', $common + ['key' => 'automation.email.reminder', 'help' => 'The still-open times, or a fresh link with new times.'])
                        @include('admin::settings.txn.partials.switch', $common + ['key' => 'automation.email.final', 'help' => '"If you still need help, call or text us."'])
                        @include('admin::settings.txn.partials.switch', $common + ['key' => 'automation.email.lost', 'help' => 'Only leads still in Contacted, with the Lost reason from Follow-ups.'])
                    </div>
                </div>
            </section>

            {{-- SMS engine --}}
            <section class="tc-card {{ $engineOn('sms') ? 'tc-state-ready' : 'is-plain' }}">
                @include('admin::settings.txn.partials.card-head', [
                    'icon' => 'message',
                    'title' => $engines['sms'][0],
                    'text' => $engines['sms'][1],
                    'aside' => '<span class="tc-status '.($engineOn('sms') ? 'is-on">Working' : 'is-off">Off').'</span>',
                ])
                <div class="tc-body">
                    @include('admin::settings.txn.partials.switch', $common + ['key' => 'automation.sms.enabled', 'switchLabel' => 'SMS engine on'])
                    <div class="tc-options">
                        @include('admin::settings.txn.partials.switch', $common + ['key' => 'automation.sms.booking_link', 'help' => 'With the automatic booking-link email, when the customer has a phone number.'])
                        @include('admin::settings.txn.partials.switch', $common + ['key' => 'automation.sms.reminder', 'help' => 'With the reminder email (needs the Email engine\'s reminder on). Wording on Settings > TXN Settings > Emails.'])
                    </div>
                </div>
            </section>

            {{-- Staff notifications --}}
            <section class="tc-card {{ $engineOn('staff') ? 'tc-state-ready' : 'is-plain' }}">
                @include('admin::settings.txn.partials.card-head', [
                    'icon' => 'bell',
                    'title' => $engines['staff'][0],
                    'text' => $engines['staff'][1],
                    'aside' => '<span class="tc-status '.($engineOn('staff') ? 'is-on">Working' : 'is-off">Off').'</span>',
                ])
                <div class="tc-body">
                    @include('admin::settings.txn.partials.switch', $common + ['key' => 'automation.staff.enabled', 'switchLabel' => 'Staff notifications on'])
                    <div class="tc-options">
                        <div class="tc-field">
                            <label for="f-staff" class="tc-label">{{ $fields['automation.staff.recipients'][2] }}</label>
                            <textarea id="f-staff" name="{{ $inputNames['automation.staff.recipients'] }}" rows="2" class="tc-input tc-textarea" placeholder="One address per line" @disabled(! $canEdit)>{{ old($inputNames['automation.staff.recipients'], implode("\n", (array) $values['automation.staff.recipients'])) }}</textarea>
                            <p class="tc-help">While the email guard is on, these addresses must also be on the allowed test list (Safety).</p>
                            @include('admin::settings.txn.partials.field-meta', ['key' => 'automation.staff.recipients'])
                        </div>
                        <div class="tc-field">
                            <span class="tc-label">{{ $fields['automation.staff.events'][2] }}</span>
                            <input type="hidden" name="{{ $events['name'] }}_sent" value="1">
                            <div class="tc-ask">
                                @foreach ($events['choices'] as $event => $label)
                                    <label>
                                        <input type="checkbox" name="{{ $events['name'] }}[]" value="{{ $event }}" @checked(in_array($event, $events['checked'], true)) @disabled(! $canEdit)>
                                        <span class="tc-ask-name">{{ $label }}</span>
                                    </label>
                                @endforeach
                            </div>
                            @include('admin::settings.txn.partials.field-meta', ['key' => 'automation.staff.events'])
                        </div>
                    </div>
                </div>
            </section>

            {{-- Cal.com webhook --}}
            @php $webhookOn = ! empty($values['automation.webhook.enabled']); @endphp
            <section class="tc-card {{ $webhookOn ? 'tc-state-ready' : 'is-plain' }}">
                @include('admin::settings.txn.partials.card-head', [
                    'icon' => 'calendar',
                    'title' => 'Cal.com webhook',
                    'text' => 'Bookings, reschedules and cancellations arrive the moment they happen, instead of waiting for the Sync button (which stays as a backup). Own switch: not affected by the master switch.',
                    'aside' => '<span class="tc-status '.($webhookOn ? 'is-on">Listening' : 'is-off">Off').'</span>',
                ])
                <div class="tc-body">
                    @include('admin::settings.txn.partials.switch', $common + ['key' => 'automation.webhook.enabled', 'switchLabel' => 'Accept bookings from Cal.com'])

                    <div class="tc-grid">
                        <div class="tc-field tc-wide">
                            <span class="tc-label">Webhook address (Subscriber URL in Cal.com)</span>
                            <div class="tc-linkbox">
                                <input id="webhook-url" type="text" readonly value="{{ $webhookUrl }}" class="tc-input">
                                <button type="button" class="tc-btn" onclick="navigator.clipboard.writeText(document.getElementById('webhook-url').value); this.textContent = 'Copied';">Copy</button>
                            </div>
                            <p class="tc-help">Cal.com must be able to reach this address from the internet (a public domain with HTTPS).</p>
                        </div>
                        <div class="tc-field tc-wide">
                            <label for="f-secret" class="tc-label">{{ $fields['automation.webhook.secret'][2] }}</label>
                            <input id="f-secret" type="password" name="{{ $inputNames['automation.webhook.secret'] }}" class="tc-input" autocomplete="new-password"
                                placeholder="{{ $secretStatus['source'] === 'none' ? 'Not set: type at least 16 characters' : 'Set ('.$secretStatus['ends'].'): leave empty to keep it' }}" @disabled(! $canEdit)>
                            <p class="tc-help">Any long random text; type the same one in Cal.com. Requests without the right signature are refused.</p>
                        </div>
                    </div>

                    <p class="tc-note">@include('admin::settings.txn.partials.icon', ['name' => 'info', 'size' => 15])
                        <span>In Cal.com: Settings &gt; Developer &gt; Webhooks &gt; New. Paste the address, tick <strong>Booking Created</strong>, <strong>Booking Rescheduled</strong> and <strong>Booking Cancelled</strong>, paste the secret, save, then switch this on.</span>
                    </p>
                </div>
            </section>

            {{-- Clock --}}
            <section class="tc-card is-plain">
                @include('admin::settings.txn.partials.card-head', ['icon' => 'clock', 'title' => 'The clock', 'text' => 'Follow-ups are time-based: every 15 minutes the clock sends what is due, as the switches above allow.'])
                <div class="tc-body">
                    <p class="tc-note">@include('admin::settings.txn.partials.icon', ['name' => 'info', 'size' => 15])
                        <span>The clock runs in Krayin's scheduler container, which is off until the server's <code>.env</code> has <strong>KRAYIN_SCHEDULER=true</strong> and <code>./scripts/deploy.sh</code> has run. Until then follow-ups only go out with <code>php artisan txn:follow-ups</code>.</span>
                    </p>
                </div>
            </section>

            @include('admin::settings.txn.partials.savebar', ['resetConfirm' => 'Put every automation setting back to its default? (Everything off.)', 'resetLabel' => 'Reset (all off)'])
        </form>

        {{-- What automation did --}}
        <section class="tc tc-card is-plain">
            @include('admin::settings.txn.partials.card-head', [
                'icon' => 'history',
                'title' => 'Latest automatic activity',
                'text' => $recent->isEmpty() ? 'Nothing yet.' : 'The last 20 steps done or skipped by automation, webhooks and the clock.',
                'aside' => bouncer()->hasPermission('leads.event_log') ? '<a href="'.e(route('admin.leads.event_log', ['who' => 'automatic'])).'" class="tc-btn">Full event log</a>' : '',
            ])
            @if ($recent->isNotEmpty())
                <div class="tc-body">
                    @include('admin::leads.events-timeline', ['events' => $recent])
                </div>
            @endif
        </section>

        @include('admin::settings.txn.partials.log')
    </div>

    @pushOnce('scripts')
        <script>
            document.querySelectorAll('.tc-toggle input[type=checkbox]').forEach((box) => box.addEventListener('change', () => {
                const text = box.closest('.tc-toggle').querySelector('[data-switch-text]');
                if (text) {
                    text.textContent = box.checked ? 'On' : 'Off';
                }
            }));
        </script>
    @endPushOnce
</x-admin::layouts>
