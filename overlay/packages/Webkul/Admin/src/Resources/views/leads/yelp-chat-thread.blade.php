{{-- One Yelp conversation as a chat (used by Leads > Yelp messages and the lead card).
     Data: $thread (yelp_message rows, oldest first), optional $height (CSS max-height). Customer left, us right. --}}
@pushOnce('styles')
    <style>
        /* column-reverse + newest first in the HTML: oldest at the top, and the box opens scrolled to the newest. */
        .tc .yc-thread { display: flex; flex-direction: column-reverse; gap: 10px; padding: 16px; overflow-y: auto; background: var(--tc-band); border-radius: 10px; }
        /* A row is only as wide as its bubble: customer on the left, us on the right. */
        .tc .yc-row { display: flex; flex-direction: column; align-items: flex-start; align-self: flex-start; max-width: min(75%, 520px); }
        .tc .yc-row.is-us { align-self: flex-end; align-items: flex-end; }
        .tc .yc-who { font-size: 12px; color: var(--tc-muted); margin: 0 6px 3px; }
        .tc .yc-bubble { padding: 9px 13px; border-radius: 14px; line-height: 1.45; white-space: pre-wrap; overflow-wrap: anywhere;
            background: var(--tc-panel); border: 1px solid var(--tc-line); color: var(--tc-ink); border-bottom-left-radius: 4px; }
        .tc .yc-row.is-us .yc-bubble { background: var(--tc-brand); border-color: var(--tc-brand); color: #fff; border-bottom-left-radius: 14px; border-bottom-right-radius: 4px; }
        .tc .yc-row.is-new .yc-bubble { box-shadow: 0 0 0 2px var(--tc-warn); }
        .tc .yc-attach { display: inline-flex; align-items: center; gap: 6px; margin-top: 6px; font-size: 13px; text-decoration: underline; color: inherit; }
        .tc .yc-thumb { display: block; margin-top: 6px; max-width: 220px; max-height: 220px; border-radius: 8px; }
        .tc .yc-when { font-size: 11px; color: var(--tc-muted); margin: 3px 6px 0; }
        .tc .yc-empty { padding: 28px 12px; text-align: center; color: var(--tc-muted); }
    </style>
@endPushOnce

@php $tzLabel = \Webkul\Admin\Support\Contact\TxnSettings::timezoneLabel(); @endphp

<div class="yc-thread" style="max-height: {{ $height ?? '520px' }};">
    @forelse ($thread->reverse() as $m)
        @php
            $us = \Webkul\Admin\Support\Contact\YelpMessageStore::fromBusiness($m->user_type);
            $when = \Webkul\Admin\Support\Contact\YelpMessageStore::localTime($m->sent_at ?? $m->received_at);
            $isImage = $m->attachment_url && preg_match('~\.(jpe?g|png|gif|webp)(\?|$)~i', $m->attachment_url);
        @endphp
        <div class="yc-row {{ $us ? 'is-us' : '' }} {{ ! $us && ! $m->read_at ? 'is-new' : '' }}">
            <span class="yc-who">{{ $us ? 'Us'.($m->user_name ? ' ('.$m->user_name.')' : '') : ($m->user_name ?: 'Customer') }}</span>
            <div class="yc-bubble">
                @if ($m->text){{ $m->text }}@endif
                @if ($m->attachment_url)
                    @if ($isImage)
                        <a href="{{ $m->attachment_url }}" target="_blank" rel="noopener noreferrer"><img class="yc-thumb" src="{{ $m->attachment_url }}" alt="{{ $m->attachment_text ?: 'Attachment' }}" loading="lazy" referrerpolicy="no-referrer"></a>
                    @endif
                    <a class="yc-attach" href="{{ $m->attachment_url }}" target="_blank" rel="noopener noreferrer">📎 {{ $m->attachment_text ?: 'Open attachment' }}</a>
                @endif
            </div>
            <span class="yc-when" title="{{ $tzLabel }}">{{ $when?->format('D M j, g:i A') }}</span>
        </div>
    @empty
        <div class="yc-empty">No messages in this conversation yet.</div>
    @endforelse
</div>
