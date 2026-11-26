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
                        <span class="tc-label">Follow-ups <span class="tc-sublabel">(hours on <a href="{{ route('admin.settings.txn.follow_ups') }}" style="color: var(--tc-brand);">Follow-ups</a>; each one is scheduled at its exact time, see below)</span></span>
                        @include('admin::settings.txn.partials.switch', $common + ['key' => 'automation.email.reminder', 'help' => 'At the reminder hour: the still-open times, or a fresh link with new times.'])
                        @include('admin::settings.txn.partials.switch', $common + ['key' => 'automation.email.final', 'help' => 'At the final hour: "If you still need help, call or text us."'])
                        @include('admin::settings.txn.partials.switch', $common + ['key' => 'automation.email.lost', 'help' => 'At the Lost hour: only leads still in Contacted, with the Lost reason from Follow-ups.'])
                    </div>

                    <div class="tc-options">
                        <span class="tc-label">When a customer cancels</span>
                        @include('admin::settings.txn.partials.switch', $common + ['key' => 'automation.email.cancel_notice', 'help' => '"Your appointment on … has been canceled. To reschedule, call or text us." Once, no follow-ups after it. Wording on Emails.'])
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
                        @include('admin::settings.txn.partials.switch', $common + ['key' => 'automation.sms.reminder', 'help' => 'With the reminder email (needs the Email engine\'s reminder on).'])
                        @include('admin::settings.txn.partials.switch', $common + ['key' => 'automation.sms.final', 'help' => 'With the final email (needs the Email engine\'s final email on).'])
                        @include('admin::settings.txn.partials.switch', $common + ['key' => 'automation.sms.cancel_notice', 'help' => 'When a customer cancels a booked visit.'])
                        <p class="tc-help">The wording of every text is on Settings &gt; TXN Settings &gt; Emails. The requirements send the follow-ups on the same channels as the first message, so the texts are on by default (they only go out while this engine is on).</p>
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

            {{-- Yelp messages (YelpMessageController) --}}
            @php $yelpOn = ! empty($values['automation.yelp.enabled']); @endphp
            <section class="tc-card {{ $yelpOn ? 'tc-state-ready' : 'is-plain' }}">
                @include('admin::settings.txn.partials.card-head', [
                    'icon' => 'message',
                    'title' => 'Yelp messages',
                    'text' => 'Zapier sends every Yelp chat message (the customer\'s and ours); Krayin saves it in the lead database (yelp_message) and shows it on the lead and on Leads > Yelp messages. Replies typed in Krayin go out through a second Zap (below). Own switches: not affected by the master switch.',
                    'aside' => '<span class="tc-status '.($yelpOn ? 'is-on">Listening' : 'is-off">Off').'</span>',
                ])
                <div class="tc-body">
                    @include('admin::settings.txn.partials.switch', $common + ['key' => 'automation.yelp.enabled', 'switchLabel' => 'Accept Yelp messages from Zapier'])

                    <div class="tc-grid">
                        <div class="tc-field tc-wide">
                            <span class="tc-label">Webhook address (URL in the Zap's Webhooks POST step)</span>
                            <div class="tc-linkbox">
                                <input id="yelp-url" type="text" readonly value="{{ $yelpUrl }}" class="tc-input">
                                <button type="button" class="tc-btn" onclick="navigator.clipboard.writeText(document.getElementById('yelp-url').value); this.textContent = 'Copied';">Copy</button>
                            </div>
                            <p class="tc-help">Use the public https address (e.g. https://txnhome.epro.co/txn/yelp-message) so Zapier can reach it.</p>
                        </div>
                        <div class="tc-field tc-wide">
                            <label for="f-yelp-token" class="tc-label">{{ $fields['automation.yelp.token'][2] }}</label>
                            <input id="f-yelp-token" type="password" name="{{ $inputNames['automation.yelp.token'] }}" class="tc-input" autocomplete="new-password"
                                placeholder="{{ $yelpTokenStatus['source'] === 'none' ? 'Not set: type at least 16 characters' : 'Set ('.$yelpTokenStatus['ends'].'): leave empty to keep it' }}" @disabled(! $canEdit)>
                            <p class="tc-help">Any long random text. In the Zap's Webhooks step, add the header <strong>X-TXN-Token</strong> with the same text. Requests without it are refused.</p>
                        </div>
                    </div>

                    <p class="tc-note">@include('admin::settings.txn.partials.icon', ['name' => 'info', 'size' => 15])
                        <span>The Zap: trigger <strong>Yelp &gt; New Message</strong>, action <strong>Webhooks by Zapier &gt; POST</strong>, payload JSON with <strong>lead_id, event_id, user_type, user, text, time, attachment_url, attachment_text</strong>. The messages database login is on <strong>Connections &gt; Yelp messages database</strong>.</span>
                    </p>

                    {{-- Replies from Krayin (YelpReplySender) --}}
                    <hr style="border: 0; border-top: 1px solid var(--tc-line); margin: 4px 0;">
                    @include('admin::settings.txn.partials.switch', $common + ['key' => 'automation.yelp.reply_enabled', 'switchLabel' => 'Send replies typed in Krayin to Yelp'])

                    <div class="tc-grid">
                        <div class="tc-field tc-wide">
                            <label for="f-yelp-hook" class="tc-label">{{ $fields['automation.yelp.reply_hook'][2] }}</label>
                            <input id="f-yelp-hook" type="password" name="{{ $inputNames['automation.yelp.reply_hook'] }}" class="tc-input" autocomplete="new-password"
                                placeholder="{{ $yelpHookStatus['source'] === 'none' ? 'Not set: paste the https://hooks.zapier.com/... address' : 'Set ('.$yelpHookStatus['ends'].'): leave empty to keep it' }}" @disabled(! $canEdit)>
                            <p class="tc-help">Kept secret: anyone with this address could make the Zap write in Yelp.</p>
                        </div>
                    </div>

                    <p class="tc-note">@include('admin::settings.txn.partials.icon', ['name' => 'info', 'size' => 15])
                        <span>The reply Zap: trigger <strong>Webhooks by Zapier &gt; Catch Hook</strong> (copy its address here), action <strong>Yelp Leads</strong> send-message, with the Yelp lead ID = <strong>lead_id</strong> and the message = <strong>text</strong>. Krayin also sends <strong>request_id</strong>, <strong>sent_by</strong> and <strong>sent_at</strong>. The reply shows in the chat at once and is marked "Seen in Yelp" when the New Message Zap brings it back. While the <strong>Yelp reply guard</strong> is on (Safety), only the allowed test chats can get a reply.</span>
                    </p>
                </div>
            </section>

            {{-- Email replies (Support\Replies\EmailReplyImporter) --}}
            @php $intakeOn = ! empty($values['automation.email_intake.enabled']); @endphp
            <section class="tc-card {{ $intakeOn ? 'tc-state-ready' : 'is-plain' }}">
                @include('admin::settings.txn.partials.card-head', [
                    'icon' => 'mail',
                    'title' => 'Email replies',
                    'text' => 'Krayin reads new emails in every mailbox on Mailboxes and adds each one to its lead (by the email thread, else by the sender\'s address) and to Mail > Inbox. It only reads: nothing is sent. Own switch: not affected by the master switch.',
                    'aside' => (bouncer()->hasPermission('settings.txn.mailboxes') ? '<a href="'.e(route('admin.settings.txn.mailboxes')).'" class="tc-btn">Mailboxes</a> ' : '').'<span class="tc-status '.($intakeOn ? 'is-on">Reading' : 'is-off">Off').'</span>',
                ])
                <div class="tc-body">
                    @include('admin::settings.txn.partials.switch', $common + ['key' => 'automation.email_intake.enabled', 'switchLabel' => 'Read customer email replies'])

                    <div class="tc-grid">
                        @foreach (['automation.email_intake.minutes', 'automation.email_intake.lookback_days'] as $key)
                            <div class="tc-field">
                                <label for="f-{{ $inputNames[$key] }}" class="tc-label">{{ $fields[$key][2] }}</label>
                                <input id="f-{{ $inputNames[$key] }}" type="number" min="{{ $fields[$key][3]['min'] }}" max="{{ $fields[$key][3]['max'] }}" name="{{ $inputNames[$key] }}"
                                    value="{{ old($inputNames[$key], $values[$key]) }}" class="tc-input" @disabled(! $canEdit)>
                                @include('admin::settings.txn.partials.field-meta', ['key' => $key])
                            </div>
                        @endforeach
                    </div>

                    @include('admin::settings.txn.partials.switch', $common + ['key' => 'automation.email_intake.mark_read', 'help' => 'Needs Mail.ReadWrite. Off: the mailbox is left exactly as it is.'])

                    <p class="tc-note">@include('admin::settings.txn.partials.icon', ['name' => 'info', 'size' => 15])
                        <span>The automatic check runs in Krayin's scheduler (<strong>KRAYIN_SCHEDULER=true</strong> in .env). <strong>Check now</strong> on Mailboxes works without it. Each mailbox's Microsoft 365 app needs the application permission <strong>Mail.ReadWrite</strong> with admin consent.</span>
                    </p>
                </div>
            </section>

            {{-- Schedule --}}
            <section class="tc-card is-plain">
                @include('admin::settings.txn.partials.card-head', [
                    'icon' => 'clock',
                    'title' => 'The schedule',
                    'text' => 'When a booking link is emailed, each follow-up above that is on becomes an entry with its exact time ("Reminder to Jane, Thu 10:14 AM") and goes out at that time.',
                    'aside' => bouncer()->hasPermission('leads.scheduled') ? '<a href="'.e(route('admin.leads.scheduled')).'" class="tc-btn">Scheduled messages</a>' : '',
                ])
                <div class="tc-body">
                    <p class="tc-note">@include('admin::settings.txn.partials.icon', ['name' => 'info', 'size' => 15])
                        <span>An entry is cancelled with the reason when the customer books, the lead leaves Contacted, a newer link is sent or its switch is turned off; turning it back on schedules the ones still ahead. Changing the hours on Follow-ups moves the pending ones. Staff can cancel, send now or change the time on <strong>Leads &gt; Scheduled messages</strong> and on each lead. The entries are fired by Krayin's queue worker (always running).</span>
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
