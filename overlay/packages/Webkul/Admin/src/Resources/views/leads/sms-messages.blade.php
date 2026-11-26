{{-- Leads > SMS messages (SmsMessagesController): text conversations with customers, list + chat.
     v-pre: customer text must never be read as a Vue template. Styles: tc-styles (.tc) + .yc-* below (same look as Yelp messages). --}}
<x-admin::layouts>
    <x-slot:title>
        SMS messages
    </x-slot>

    @include('admin::settings.txn.partials.tc-styles')

    @pushOnce('styles')
        <style>
            .tc.yc-inbox { display: grid; grid-template-columns: minmax(260px, 360px) minmax(0, 1fr); gap: 16px; align-items: start; }
            @media (max-width: 900px) { .tc.yc-inbox { grid-template-columns: minmax(0, 1fr); } }
            .tc .yc-list { display: flex; flex-direction: column; max-height: 640px; overflow-y: auto; }
            .tc .yc-item { display: flex; gap: 10px; align-items: flex-start; padding: 11px 14px; border-bottom: 1px solid var(--tc-line); color: inherit; text-decoration: none; }
            .tc .yc-item:hover { background: var(--tc-band); }
            .tc .yc-item.is-current { background: var(--tc-brand-soft); }
            .tc .yc-item-main { min-width: 0; flex: 1 1 auto; }
            .tc .yc-item-top { display: flex; justify-content: space-between; gap: 8px; }
            .tc .yc-item-name { font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
            .tc .yc-item-time { flex: 0 0 auto; font-size: 12px; color: var(--tc-muted); }
            .tc .yc-item-text { font-size: 13px; color: var(--tc-muted); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
            .tc .yc-item-tag { font-size: 11px; color: var(--tc-warn); }
            .tc .yc-unread { flex: 0 0 auto; min-width: 20px; height: 20px; padding: 0 6px; border-radius: 999px; background: var(--tc-brand); color: #fff; font-size: 12px; font-weight: 700; display: grid; place-items: center; }
            .tc .yc-tabs { display: flex; gap: 6px; flex-wrap: wrap; }
            .tc .yc-tab { padding: 5px 12px; border-radius: 999px; border: 1px solid var(--tc-line); color: var(--tc-muted); text-decoration: none; font-size: 13px; }
            .tc .yc-tab.is-on { border-color: var(--tc-brand); background: var(--tc-brand-soft); color: var(--tc-brand); font-weight: 600; }
            .tc .yc-chat-head { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; justify-content: space-between; }
            .tc .yc-thread { display: flex; flex-direction: column-reverse; gap: 10px; padding: 16px; overflow-y: auto; background: var(--tc-band); border-radius: 10px; max-height: 560px; }
            .tc .yc-row { display: flex; flex-direction: column; align-items: flex-start; align-self: flex-start; max-width: min(75%, 520px); }
            .tc .yc-row.is-us { align-self: flex-end; align-items: flex-end; }
            .tc .yc-who { font-size: 12px; color: var(--tc-muted); margin: 0 6px 3px; }
            .tc .yc-bubble { padding: 9px 13px; border-radius: 14px; line-height: 1.45; white-space: pre-wrap; overflow-wrap: anywhere;
                background: var(--tc-panel); border: 1px solid var(--tc-line); color: var(--tc-ink); border-bottom-left-radius: 4px; }
            .tc .yc-row.is-us .yc-bubble { background: var(--tc-brand); border-color: var(--tc-brand); color: #fff; border-bottom-left-radius: 14px; border-bottom-right-radius: 4px; }
            .tc .yc-row.is-new .yc-bubble { box-shadow: 0 0 0 2px var(--tc-warn); }
            .tc .yc-attach { display: inline-flex; align-items: center; gap: 6px; margin-top: 6px; font-size: 13px; text-decoration: underline; color: inherit; }
            .tc .yc-when { font-size: 11px; color: var(--tc-muted); margin: 3px 6px 0; }
            .tc .yc-empty { padding: 28px 12px; text-align: center; color: var(--tc-muted); }
        </style>
    @endPushOnce

    @php
        $tz = config('slot_finder.timezone') ?: config('app.timezone');
        $local = fn ($time) => $time ? \Carbon\Carbon::parse($time, config('app.timezone'))->timezone($tz) : null;
        $tabUrl = fn ($show) => route('admin.leads.sms_messages', array_filter(['show' => $show === 'all' ? null : $show, 'q' => $filters['q'] ?: null]));
        $chatUrl = fn ($number) => route('admin.leads.sms_messages', array_filter(['show' => $filters['show'] === 'all' ? null : $filters['show'], 'q' => $filters['q'] ?: null, 'chat' => $number]));
        $tzLabel = \Webkul\Admin\Support\Contact\TxnSettings::timezoneLabel();
    @endphp

    <div v-pre class="flex flex-col gap-4">
        <div class="tc tc-top">
            <span class="tc-top-icon">@include('admin::settings.txn.partials.icon', ['name' => 'message', 'size' => 22])</span>
            <div class="tc-top-text">
                <span class="tc-crumb">Leads</span>
                <h1>SMS messages</h1>
                <p>Texts with customers: the ones they send to our Twilio number, and the booking-link and follow-up texts Krayin sends. Each number is matched to its contact and newest open lead.</p>
            </div>
            <a href="{{ route('admin.leads.index') }}" class="secondary-button">Back to leads</a>
        </div>

        @if (! $receiving)
            <div class="tc tc-note">@include('admin::settings.txn.partials.icon', ['name' => 'info', 'size' => 15])
                <span>Receiving is <strong>off</strong>: incoming texts are refused until <strong>Settings &gt; TXN Settings &gt; Automation &gt; Incoming texts</strong> is switched on and the Twilio number points at Krayin. Texts already saved are still shown.</span>
            </div>
        @endif

        @if (! $ready)
            <div class="tc tc-note" style="border-color: var(--tc-bad);">@include('admin::settings.txn.partials.icon', ['name' => 'alert', 'size' => 15])
                <span>The SMS table is not set up yet: run the migrations (deploy.sh does this).</span>
            </div>
        @else
            <div class="tc yc-inbox">
                {{-- Conversations --}}
                <section class="tc-card is-plain">
                    <div class="tc-body" style="gap: 10px;">
                        <form method="GET" action="{{ route('admin.leads.sms_messages') }}">
                            <input type="hidden" name="show" value="{{ $filters['show'] === 'all' ? '' : $filters['show'] }}">
                            <input type="search" name="q" value="{{ $filters['q'] }}" class="tc-input" placeholder="Search name, number, lead or text" style="width: 100%;">
                        </form>
                        <div class="yc-tabs">
                            @foreach (['all' => 'All', 'unread' => 'Unread', 'staff' => 'Needs staff', 'unmatched' => 'Unknown number'] as $key => $label)
                                <a href="{{ $tabUrl($key) }}" class="yc-tab {{ $filters['show'] === $key ? 'is-on' : '' }}">{{ $label }} ({{ $counts[$key] }})</a>
                            @endforeach
                        </div>
                    </div>
                    <div class="yc-list">
                        @forelse ($conversations as $row)
                            <a href="{{ $chatUrl($row->customer_number) }}" class="yc-item {{ $current && $current->customer_number === $row->customer_number ? 'is-current' : '' }}">
                                <div class="yc-item-main">
                                    <div class="yc-item-top">
                                        <span class="yc-item-name">{{ $row->name }}</span>
                                        <span class="yc-item-time">{{ $local($row->sent_at)?->diffForHumans(short: true) }}</span>
                                    </div>
                                    <div class="yc-item-text">{{ $row->direction === 'out' ? 'You: ' : '' }}{{ $row->body ?: '📎 Picture or file' }}</div>
                                    @if ($row->needs_staff)
                                        <div class="yc-item-tag">Needs staff: number on several contacts</div>
                                    @elseif (! $row->person_id)
                                        <div class="yc-item-tag">Unknown number</div>
                                    @endif
                                </div>
                                @if ($row->unread)
                                    <span class="yc-unread">{{ $row->unread }}</span>
                                @endif
                            </a>
                        @empty
                            <div class="yc-empty">{{ $counts['all'] ? 'No conversation matches.' : 'No texts yet.' }}</div>
                        @endforelse
                    </div>
                </section>

                {{-- Chosen conversation --}}
                <section class="tc-card is-plain">
                    @if ($current)
                        <div class="tc-head yc-chat-head">
                            <div class="tc-title">
                                <h2 style="margin: 0; font-size: 16px; font-weight: 600;">{{ $current->name }}</h2>
                                <p>{{ $current->customer_number }}{{ $current->lead ? ' · '.$current->lead->title : ($current->person_id ? ' · no open lead' : '') }}</p>
                            </div>
                            <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                                @if ($current->lead)
                                    <a href="{{ route('admin.leads.view', $current->lead->id) }}" class="tc-btn">Open lead</a>
                                @endif
                                @if ($current->person_id)
                                    <a href="{{ route('admin.contacts.persons.view', $current->person_id) }}" class="tc-btn">Open contact</a>
                                @endif
                            </div>
                        </div>
                        <div class="tc-body">
                            @if ($current->needs_staff)
                                <p class="tc-note">@include('admin::settings.txn.partials.icon', ['name' => 'alert', 'size' => 15])
                                    <span><strong>Needs staff:</strong> this number is on {{ $staffContacts->count() }} contacts
                                        ({{ $staffContacts->pluck('name')->implode(', ') }}), so Krayin didn't pick one. Check who wrote and keep the number on the right contact only.</span>
                                </p>
                            @endif

                            <div class="yc-thread">
                                @forelse ($thread->reverse() as $m)
                                    @php $us = $m->direction === 'out'; @endphp
                                    <div class="yc-row {{ $us ? 'is-us' : '' }} {{ ! $us && ! $m->read_at ? 'is-new' : '' }}">
                                        <span class="yc-who">{{ $us ? 'Us' : ($current->person_name ?: $m->customer_number) }}</span>
                                        <div class="yc-bubble">
                                            @if ($m->body){{ $m->body }}@endif
                                            @foreach (\Webkul\Admin\Support\Replies\SmsInbox::media($m) as $file)
                                                <a class="yc-attach" href="{{ $file['url'] }}" target="_blank" rel="noopener noreferrer">📎 {{ $file['type'] ?: 'Open file' }}</a>
                                            @endforeach
                                        </div>
                                        <span class="yc-when" title="{{ $tzLabel }}">{{ $local($m->sent_at)?->format('D M j, g:i A') }}</span>
                                    </div>
                                @empty
                                    <div class="yc-empty">No texts in this conversation yet.</div>
                                @endforelse
                            </div>
                        </div>
                    @else
                        <div class="tc-body">
                            <div class="tc-empty-state">
                                @include('admin::settings.txn.partials.icon', ['name' => 'message', 'size' => 34])
                                <strong>No conversation chosen</strong>
                                Texts appear here once Twilio sends them to Krayin.
                            </div>
                        </div>
                    @endif
                </section>
            </div>
        @endif
    </div>
</x-admin::layouts>
