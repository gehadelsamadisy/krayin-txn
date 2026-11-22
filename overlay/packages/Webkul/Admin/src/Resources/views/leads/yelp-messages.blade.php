{{-- Leads > Yelp messages (YelpMessagesController): Yelp conversations saved from Zapier, list + chat + reply box.
     v-pre: customer text must never be read as a Vue template. Styles: tc-styles (.tc) + yelp-chat-thread (.yc-*). --}}
<x-admin::layouts>
    <x-slot:title>
        Yelp messages
    </x-slot>

    @include('admin::settings.txn.partials.tc-styles')

    @pushOnce('styles')
        <style>
            /* .tc.yc-inbox: both classes sit on the same element (a space would mean "inside" and never match). */
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
        </style>
    @endPushOnce

    @php
        $ago = fn ($row) => \Webkul\Admin\Support\Contact\YelpMessageStore::localTime($row->sent_at ?? $row->received_at)?->diffForHumans(short: true);
        $tabUrl = fn ($show) => route('admin.leads.yelp_messages', array_filter(['show' => $show === 'all' ? null : $show, 'q' => $filters['q'] ?: null]));
        $chatUrl = fn ($id) => route('admin.leads.yelp_messages', array_filter(['show' => $filters['show'] === 'all' ? null : $filters['show'], 'q' => $filters['q'] ?: null, 'chat' => $id]));
    @endphp

    <div v-pre class="flex flex-col gap-4">
        <div class="tc tc-top">
            <span class="tc-top-icon">@include('admin::settings.txn.partials.icon', ['name' => 'message', 'size' => 22])</span>
            <div class="tc-top-text">
                <span class="tc-crumb">Leads</span>
                <h1>Yelp messages</h1>
                <p>Every message in the Yelp chats, the customer's and ours, as Zapier delivers them (a few minutes after they are sent). Reply under the chat: it goes to the customer's Yelp inbox through Zapier.</p>
            </div>
            <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                <a href="{{ route('admin.leads.yelp_messages', ['new' => 1]) }}" class="primary-button">New chat</a>
                <a href="{{ route('admin.leads.index') }}" class="secondary-button">Back to leads</a>
            </div>
        </div>

        @if (! $receiving)
            <div class="tc tc-note">@include('admin::settings.txn.partials.icon', ['name' => 'info', 'size' => 15])
                <span>Receiving is <strong>off</strong>: new Yelp messages are refused until <strong>Settings &gt; TXN Settings &gt; Automation &gt; Yelp messages</strong> is switched on. Messages already saved are still shown.</span>
            </div>
        @endif

        @if ($error)
            <div class="tc tc-note" style="border-color: var(--tc-bad);">@include('admin::settings.txn.partials.icon', ['name' => 'alert', 'size' => 15])
                <span>{{ $error }}</span>
            </div>
        @else
            <div class="tc yc-inbox">
                @if ($newChat)
                {{-- New chat: pick a Yelp lead in Krayin (YelpMessagesController::yelpLeads) --}}
                <section class="tc-card is-plain">
                    <div class="tc-body" style="gap: 10px;">
                        <div class="yc-chat-head">
                            <strong>New chat</strong>
                            <a href="{{ route('admin.leads.yelp_messages') }}" class="tc-btn">Cancel</a>
                        </div>
                        <form method="GET" action="{{ route('admin.leads.yelp_messages') }}">
                            <input type="hidden" name="new" value="1">
                            <input type="search" name="find" value="{{ $find }}" class="tc-input" placeholder="Search a contact or lead" style="width: 100%;" autofocus>
                        </form>
                        <p class="tc-help" style="margin: 0;">Yelp leads in Krayin only: Yelp chat works with Yelp customers. Angi customers are reached by email and text.</p>
                    </div>
                    <div class="yc-list">
                        @forelse ($picker as $row)
                            <a href="{{ route('admin.leads.yelp_messages', ['chat' => $row->source_lead_id]) }}" class="yc-item">
                                <div class="yc-item-main">
                                    <div class="yc-item-top">
                                        <span class="yc-item-name">{{ $row->name }}</span>
                                        @if (isset($withMessages[$row->source_lead_id]))
                                            <span class="yc-item-time">has messages</span>
                                        @endif
                                    </div>
                                    <div class="yc-item-text">{{ $row->lead->title }}</div>
                                </div>
                            </a>
                        @empty
                            <div style="padding: 28px 14px; text-align: center; color: var(--tc-muted);">
                                {{ $find !== '' ? 'No Yelp lead matches "'.$find.'".' : 'No Yelp leads in Krayin yet.' }}
                            </div>
                        @endforelse
                    </div>
                </section>
                @else
                {{-- Conversations --}}
                <section class="tc-card is-plain">
                    <div class="tc-body" style="gap: 10px;">
                        <form method="GET" action="{{ route('admin.leads.yelp_messages') }}">
                            <input type="hidden" name="show" value="{{ $filters['show'] === 'all' ? '' : $filters['show'] }}">
                            <input type="search" name="q" value="{{ $filters['q'] }}" class="tc-input" placeholder="Search name, lead or text" style="width: 100%;">
                        </form>
                        <div class="yc-tabs">
                            @foreach (['all' => 'All', 'unread' => 'Unread', 'unmatched' => 'No lead in Krayin'] as $key => $label)
                                <a href="{{ $tabUrl($key) }}" class="yc-tab {{ $filters['show'] === $key ? 'is-on' : '' }}">{{ $label }} ({{ $counts[$key] }})</a>
                            @endforeach
                        </div>
                    </div>
                    <div class="yc-list">
                        @forelse ($conversations as $row)
                            @php $us = \Webkul\Admin\Support\Contact\YelpMessageStore::fromBusiness($row->user_type); @endphp
                            <a href="{{ $chatUrl($row->source_lead_id) }}" class="yc-item {{ $current && $current->source_lead_id === $row->source_lead_id ? 'is-current' : '' }}">
                                <div class="yc-item-main">
                                    <div class="yc-item-top">
                                        <span class="yc-item-name">{{ $row->name }}</span>
                                        <span class="yc-item-time">{{ $ago($row) }}</span>
                                    </div>
                                    <div class="yc-item-text">{{ $us ? 'You: ' : '' }}{{ $row->text ?: '📎 '.($row->attachment_url ? 'Attachment' : '') }}</div>
                                    @unless ($row->lead)
                                        <div class="yc-item-tag">No lead in Krayin yet</div>
                                    @endunless
                                </div>
                                @if ($row->unread)
                                    <span class="yc-unread">{{ $row->unread }}</span>
                                @endif
                            </a>
                        @empty
                            <div class="yc-empty" style="padding: 28px 14px; text-align: center; color: var(--tc-muted);">
                                {{ $counts['all'] ? 'No conversation matches.' : 'No Yelp messages yet.' }}
                            </div>
                        @endforelse
                    </div>
                </section>
                @endif

                {{-- Chosen conversation --}}
                <section class="tc-card is-plain">
                    @if ($current)
                        <div class="tc-head yc-chat-head">
                            <div class="tc-title">
                                <h2 style="margin: 0; font-size: 16px; font-weight: 600;">{{ $current->name }}</h2>
                                <p>{{ $current->lead?->title ?? 'Yelp lead '.$current->source_lead_id.' (not in Krayin yet)' }}</p>
                            </div>
                            <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                                @if ($current->lead)
                                    <a href="{{ route('admin.leads.view', $current->lead->id) }}" class="tc-btn">Open lead</a>
                                @endif
                                @if ($replyUrl)
                                    <a href="{{ $replyUrl }}" target="_blank" rel="noopener noreferrer" class="tc-btn">Reply in Yelp ↗</a>
                                @endif
                            </div>
                        </div>
                        <div class="tc-body">
                            @include('admin::leads.yelp-chat-thread', ['thread' => $thread, 'height' => '560px'])
                            @include('admin::leads.yelp-reply-box', ['chat' => $current->source_lead_id])
                        </div>
                    @else
                        <div class="tc-body">
                            <div class="tc-empty-state">
                                @include('admin::settings.txn.partials.icon', ['name' => 'message', 'size' => 34])
                                <strong>No conversation chosen</strong>
                                Messages appear here once Zapier sends them.
                            </div>
                        </div>
                    @endif
                </section>
            </div>
        @endif
    </div>
</x-admin::layouts>
