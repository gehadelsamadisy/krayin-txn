{{-- Reply box under a Yelp chat (Leads > Yelp messages, the Yelp card on a lead). Data: $chat (Yelp lead id).
     Posts to admin.leads.yelp_messages.reply (YelpReplySender -> the reply Zap), then back to this page.
     Must sit inside a v-pre element (plain form, no Vue). Styles: yelp-chat-thread (.yc-reply*). --}}
@if (bouncer()->hasPermission('yelp_messages.reply'))
    @php
        $replyOn = (bool) config('automation.yelp.reply_enabled');
        $hookSet = str_starts_with(trim((string) config('automation.yelp.reply_hook')), 'https://');
        $allowed = \Webkul\Admin\Support\Contact\YelpReplySender::allowed($chat);
        $canSend = $replyOn && $hookSet && $allowed;
        $settingsLink = fn ($route, $label) => bouncer()->hasPermission('settings.txn') ? '<a href="'.e(route($route)).'" style="text-decoration: underline;">'.e($label).'</a>' : e($label);
    @endphp

    <form method="POST" action="{{ route('admin.leads.yelp_messages.reply', ['chat' => $chat]) }}" class="yc-reply"
        onsubmit="var b = this.querySelector('button[type=submit]'); b.disabled = true; b.textContent = 'Sending…';">
        @csrf
        <label for="yc-reply-{{ md5($chat) }}" class="tc-label" style="margin: 0;">Reply on Yelp</label>
        <textarea id="yc-reply-{{ md5($chat) }}" name="text" class="tc-input" maxlength="{{ \Webkul\Admin\Support\Contact\YelpReplySender::MAX_LENGTH }}"
            placeholder="{{ $canSend ? 'Write to the customer. It goes to their Yelp inbox through Zapier.' : 'Replying is not available for this chat right now (see below).' }}"
            @disabled(! $canSend) required>{{ old('text') }}</textarea>
        <div class="yc-reply-bar">
            <span class="yc-reply-note {{ $canSend ? '' : 'is-warn' }}">
                @if (! $replyOn)
                    Replies from Krayin are off: {!! $settingsLink('admin.settings.txn.automation', 'Settings > TXN Settings > Automation > Yelp messages') !!}.
                @elseif (! $hookSet)
                    The reply Zap address is not set: {!! $settingsLink('admin.settings.txn.automation', 'Settings > TXN Settings > Automation > Yelp messages') !!}.
                @elseif (! $allowed)
                    Yelp reply guard is on and this chat isn't an allowed test chat. To test with it, add <strong>{{ $chat }}</strong> on {!! $settingsLink('admin.settings.txn.safety', 'Settings > TXN Settings > Safety > Yelp replies') !!}.
                @else
                    Chat ID {{ $chat }}{{ config('txn.guard_yelp', true) ? ' · allowed test chat (Yelp reply guard on)' : '' }}
                @endif
            </span>
            <button type="submit" class="primary-button" @disabled(! $canSend)>Send</button>
        </div>
    </form>
@endif
