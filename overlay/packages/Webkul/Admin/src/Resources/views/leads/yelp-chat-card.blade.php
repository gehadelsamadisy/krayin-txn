{{-- "Yelp messages" card on a Yelp lead's page (view hook in AppServiceProvider): the Yelp chat saved from Zapier.
     Data: $lead (from the hook). Shown only for "[TXN-YELP-…]" leads with messages; a read error hides the card
     (the every-page service alert reports it). Opening the lead marks the messages read. v-pre: customer text is not a template. --}}
@php
    $yelpId = isset($lead) ? \Webkul\Admin\Support\Contact\YelpMessageStore::sourceLeadId($lead) : null;
    $thread = collect();

    if ($yelpId && bouncer()->hasPermission('yelp_messages') && \Webkul\Admin\Support\Contact\ServiceHealth::hasKey('msg_db')) {
        try {
            $store = app(\Webkul\Admin\Support\Contact\YelpMessageStore::class);
            $thread = $store->thread($yelpId);
            $store->markRead($yelpId);
        } catch (\Throwable $exception) {
            \Webkul\Admin\Support\Contact\ServiceHealth::failed('msg_db', 'Krayin could not read the Yelp messages: '.$exception->getMessage());
        }
    }
@endphp

@if ($thread->isNotEmpty())
    @include('admin::settings.txn.partials.tc-styles')

    @php
        $new = $thread->filter(fn ($m) => ! $m->read_at && ! \Webkul\Admin\Support\Contact\YelpMessageStore::fromBusiness($m->user_type))->count();
        $replyUrl = \Webkul\Admin\Http\Controllers\Lead\YelpMessagesController::replyUrl($yelpId);
        $aside = '<a href="'.e(route('admin.leads.yelp_messages', ['chat' => $yelpId])).'" class="tc-btn">All Yelp messages</a>'
            .($replyUrl ? ' <a href="'.e($replyUrl).'" target="_blank" rel="noopener noreferrer" class="tc-btn">Reply in Yelp ↗</a>' : '');
    @endphp

    <section v-pre class="tc tc-card is-plain" style="margin-top: 12px;">
        @include('admin::settings.txn.partials.card-head', [
            'icon' => 'message',
            'title' => 'Yelp messages',
            'text' => $thread->count().' message(s)'.($new ? ", {$new} new" : '').'. Newest at the bottom.',
            'aside' => $aside,
        ])
        <div class="tc-body">
            @include('admin::leads.yelp-chat-thread', ['thread' => $thread, 'height' => '380px'])
            @include('admin::leads.yelp-reply-box', ['chat' => $yelpId])
        </div>
    </section>
@endif
