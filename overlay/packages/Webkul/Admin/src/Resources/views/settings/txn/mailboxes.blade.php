{{-- Settings > TXN Settings > Mailboxes (TxnMailboxesController, Support\Replies\Mailboxes). Styles: partials/tc-styles (.tc, scoped). --}}
<x-admin::layouts>
    <x-slot:title>
        Mailboxes
    </x-slot>

    @include('admin::settings.txn.partials.tc-styles')

    @php
        $editing = (string) old('editing', '');
        $when = fn ($time) => $time ? \Carbon\Carbon::parse($time)->format('M j, g:i A') : null;
    @endphp

    <div class="mx-auto flex w-full max-w-4xl flex-col gap-4">
        <div class="tc tc-top">
            <span class="tc-top-icon">@include('admin::settings.txn.partials.icon', ['name' => 'mail', 'size' => 22])</span>
            <div class="tc-top-text">
                <span class="tc-crumb">TXN Settings</span>
                <h1>Mailboxes</h1>
                <p>Every mailbox Krayin uses to email customers, so their replies reach the lead. Each one signs in with its own Microsoft 365 app (tenant ID, client ID, client secret). Krayin only reads them: nothing is sent from this page.</p>
            </div>
            <a href="{{ route('admin.settings.index') }}" class="secondary-button">Back to settings</a>
        </div>

        @if ($errors->has('mailbox'))
            <div class="tc tc-alert" role="alert">
                @include('admin::settings.txn.partials.icon', ['name' => 'alert'])
                <div>
                    <strong>Not saved</strong>
                    @foreach ($errors->get('mailbox') as $message)
                        <div>{{ $message }}</div>
                    @endforeach
                </div>
            </div>
        @endif

        @if (! $intakeOn)
            <div class="tc tc-box" role="status">
                @include('admin::settings.txn.partials.icon', ['name' => 'info'])
                <div>
                    <strong>Automatic checking is off.</strong>
                    Replies are only read when you click <strong>Check now</strong>. Turn on <strong>Email replies</strong> on
                    <a href="{{ route('admin.settings.txn.automation') }}" style="color: var(--tc-brand); font-weight: 600;">Automation</a>
                    to check every {{ $minutes }} {{ $minutes === 1 ? 'minute' : 'minutes' }}.
                </div>
            </div>
        @endif

        @if ($missing)
            <div class="tc tc-box is-warn" role="status">
                @include('admin::settings.txn.partials.icon', ['name' => 'alert'])
                <div>
                    <strong>Replies to these addresses are not read</strong>
                    <p style="margin: 2px 0 4px;">Krayin has emailed customers from them, but there is no working login here. Add each one below (or fix its login).</p>
                    <ul>
                        @foreach ($missing as $address)
                            <li>{{ $address }}</li>
                        @endforeach
                    </ul>
                </div>
            </div>
        @endif

        @foreach ($mailboxes as $item)
            @php
                $m = $item->row;
                $state = ! $m->read_replies ? ['is-off', 'Not read'] : (! $item->hasLogin ? ['is-off', 'No login'] : ($m->last_error ? ['is-off', 'Problem'] : ($m->last_checked_at ? ['is-on', 'Reading'] : ['is-on', 'Ready'])));
                $open = $editing === (string) $m->id;
            @endphp
            <section class="tc tc-card {{ $m->last_error && $m->read_replies ? 'tc-state-failed' : ($state[0] === 'is-on' ? 'tc-state-ready' : 'is-plain') }}" data-mailbox="{{ $m->id }}">
                @include('admin::settings.txn.partials.card-head', [
                    'icon' => $m->is_main ? 'key' : 'mail',
                    'title' => $m->address,
                    'text' => $m->is_main
                        ? 'Main sender: the address customer emails go out from (Connections). Its Microsoft 365 login is the one on Connections.'
                        : (($m->name ? $m->name.'. ' : '').'Own Microsoft 365 login'.($item->ends ? ' (secret '.$item->ends.')' : '').'.'),
                    'aside' => '<span class="tc-status '.$state[0].'">'.e($state[1]).'</span>',
                ])
                <div class="tc-body">
                    <div class="tc-rowline" style="display: flex; flex-wrap: wrap; gap: 6px 18px; font-size: 13px;">
                        <span><strong>Last check:</strong> {{ $when($m->last_checked_at) ?? 'never' }}</span>
                        @if ($m->last_result)
                            <span>{{ $m->last_result }}</span>
                        @endif
                        @if ($m->received_until)
                            <span><strong>Read up to:</strong> {{ $when($m->received_until) }}</span>
                        @endif
                    </div>

                    @if ($m->last_error && $m->read_replies)
                        <p class="tc-result is-fail" role="status"><strong>Failed.</strong> <span>{{ $m->last_error }}</span></p>
                    @elseif (! $item->hasLogin && $m->read_replies)
                        <p class="tc-result is-fail" role="status"><strong>No login.</strong>
                            <span>{{ $m->is_main ? 'Fill in the Microsoft 365 tenant ID, client ID and secret on Connections.' : 'Edit this mailbox and add its tenant ID, client ID and secret.' }}</span>
                        </p>
                    @endif

                    <p class="tc-result" data-test-result hidden role="status"><strong></strong> <span></span></p>

                    @if ($canEdit)
                        <div style="display: flex; flex-wrap: wrap; gap: 8px; align-items: center;">
                            <button type="button" class="tc-test" data-test-url="{{ route('admin.settings.txn.mailboxes.test', $m->id) }}" @disabled(! $item->hasLogin)>
                                <span class="tc-spin" hidden aria-hidden="true"></span><span>Test</span>
                            </button>

                            <form method="POST" action="{{ route('admin.settings.txn.mailboxes.check', $m->id) }}" style="display: inline;">
                                @csrf
                                <button type="submit" class="tc-btn" @disabled(! $item->hasLogin)>Check now</button>
                            </form>

                            <button type="button" class="tc-btn" data-toggle-edit="{{ $m->id }}">Edit</button>

                            @if (! $m->is_main)
                                <form method="POST" action="{{ route('admin.settings.txn.mailboxes.delete', $m->id) }}" style="display: inline;"
                                    onsubmit="return confirm('Stop reading {{ $m->address }} and remove its login from Krayin? Emails already in Krayin stay.');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="tc-btn">Remove</button>
                                </form>
                            @endif
                        </div>

                        <form method="POST" action="{{ route('admin.settings.txn.mailboxes.update', $m->id) }}" class="tc-form" data-edit="{{ $m->id }}" @if (! $open) hidden @endif autocomplete="off">
                            @csrf
                            @method('PUT')
                            <input type="hidden" name="editing" value="{{ $m->id }}">
                            @include('admin::settings.txn.mailbox-fields', ['m' => $m, 'ends' => $item->ends, 'open' => $open])
                            <div style="display: flex; gap: 8px; justify-content: flex-end;">
                                <button type="button" class="tc-btn" data-toggle-edit="{{ $m->id }}">Cancel</button>
                                <button type="submit" class="primary-button">Save mailbox</button>
                            </div>
                        </form>
                    @endif
                </div>
            </section>
        @endforeach

        @if ($canEdit)
            <section class="tc tc-card is-plain">
                @include('admin::settings.txn.partials.card-head', [
                    'icon' => 'plus',
                    'title' => 'Add a mailbox',
                    'text' => 'Another address Krayin uses to email customers. In Entra: an app registration with the Microsoft Graph application permission Mail.ReadWrite (admin consent), allowed for this mailbox. Several mailboxes can share one app.',
                ])
                <div class="tc-body">
                    <form method="POST" action="{{ route('admin.settings.txn.mailboxes.store') }}" class="tc-form" autocomplete="off">
                        @csrf
                        <input type="hidden" name="editing" value="new">
                        @include('admin::settings.txn.mailbox-fields', ['m' => null, 'ends' => null, 'open' => $editing === 'new'])
                        <div style="display: flex; justify-content: flex-end;">
                            <button type="submit" class="primary-button">Add mailbox</button>
                        </div>
                    </form>
                </div>
            </section>
        @endif

        <section class="tc tc-card is-plain">
            @include('admin::settings.txn.partials.card-head', ['icon' => 'info', 'title' => 'How replies are matched', 'text' => 'Each new email is added once, even if it is checked again.'])
            <div class="tc-body">
                <div class="tc-roles">
                    <div class="tc-role"><strong>Reply to a Krayin email</strong>Joins that email's thread, on the same contact and lead, and shows unread in Mail &gt; Inbox.</div>
                    <div class="tc-role"><strong>New email from a known address</strong>Goes to that contact's newest open lead (not Won or Lost).</div>
                    <div class="tc-role"><strong>Unknown sender</strong>Kept in Mail &gt; Inbox without a lead.</div>
                    <div class="tc-role"><strong>Skipped</strong>Emails from these mailboxes themselves, auto-replies, out-of-office notes and bounces.</div>
                </div>
            </div>
        </section>

        @include('admin::settings.txn.partials.log')
    </div>

    @pushOnce('scripts')
        <script>
            document.querySelectorAll('[data-toggle-edit]').forEach((button) => button.addEventListener('click', () => {
                const form = document.querySelector('[data-edit="' + button.dataset.toggleEdit + '"]');
                if (form) {
                    form.hidden = ! form.hidden;
                }
            }));

            document.querySelectorAll('[data-test-url]').forEach((button) => button.addEventListener('click', async () => {
                const card = button.closest('[data-mailbox]');
                const box = card.querySelector('[data-test-result]');
                const spin = button.querySelector('.tc-spin');
                const token = card.querySelector('input[name=_token]')?.value || '';

                button.disabled = true;
                spin.hidden = false;

                let result;
                try {
                    const response = await fetch(button.dataset.testUrl, {
                        method: 'POST',
                        headers: {'X-CSRF-TOKEN': token, 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
                    });
                    result = await response.json();
                } catch (error) {
                    result = {ok: false, message: 'Krayin did not answer. Try again.'};
                }

                box.hidden = false;
                box.className = 'tc-result ' + (result.ok ? 'is-ok' : 'is-fail');
                box.querySelector('strong').textContent = result.ok ? 'Works.' : 'Failed.';
                box.querySelector('span').textContent = result.message || '';
                button.disabled = false;
                spin.hidden = true;
            }));
        </script>
    @endPushOnce
</x-admin::layouts>
