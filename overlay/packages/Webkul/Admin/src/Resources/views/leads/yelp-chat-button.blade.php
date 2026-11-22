{{-- "Yelp chat" button(s) among the actions of a lead page (hook admin.leads.view.actions.before, data $lead)
     and a contact page (hook admin.contact.persons.view.actions.before, data $person), AppServiceProvider.
     Opens Leads > Yelp messages on that Yelp lead's chat, even before any message. Yelp leads only
     ("[TXN-YELP-<id>]" in the title); a contact with several Yelp leads gets one button per lead. --}}
@if (bouncer()->hasPermission('yelp_messages'))
    @php
        if (isset($lead)) {
            $yelpChats = ($id = \Webkul\Admin\Support\Contact\YelpMessageStore::sourceLeadId($lead)) ? collect([['id' => $id, 'title' => null]]) : collect();
        } elseif (isset($person)) {
            $yelpChats = \Webkul\Lead\Models\Lead::query()
                ->where('person_id', $person->id)
                ->where('title', 'like', '%[TXN-YELP-%')
                ->orderByDesc('id')
                ->get(['id', 'title'])
                ->map(fn ($l) => ['id' => \Webkul\Admin\Support\Contact\YelpMessageStore::sourceLeadId($l), 'title' => trim(preg_replace('/\s*\[TXN-YELP-[^\]]*\]/', '', (string) $l->title))])
                ->filter(fn ($chat) => $chat['id'])
                ->values();
        } else {
            $yelpChats = collect();
        }
    @endphp

    @foreach ($yelpChats as $chat)
        <a href="{{ route('admin.leads.yelp_messages', ['chat' => $chat['id']]) }}" class="secondary-button"
            title="Yelp chat with this customer{{ $chat['title'] ? ' ('.$chat['title'].')' : '' }}">
            Yelp chat{{ $yelpChats->count() > 1 && $chat['title'] ? ': '.\Illuminate\Support\Str::limit($chat['title'], 30) : '' }}
        </a>
    @endforeach
@endif
