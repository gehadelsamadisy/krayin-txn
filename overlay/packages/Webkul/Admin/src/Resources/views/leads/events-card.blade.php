{{-- "Events" card on a lead or contact page (view hooks in AppServiceProvider): that customer's pipeline events.
     Data: $lead or $person (from the hook). --}}
@if (bouncer()->hasPermission('leads.event_log') && \Illuminate\Support\Facades\Schema::hasTable('txn_events'))
    @php
        $forLead = isset($lead) && $lead;
        $rows = \Illuminate\Support\Facades\DB::table('txn_events as e')
            ->leftJoin('leads as l', 'l.id', '=', 'e.lead_id')
            ->leftJoin('persons as p', 'p.id', '=', 'e.person_id')
            ->select('e.*', 'l.title as lead_title', 'p.name as person_name')
            ->when($forLead,
                fn ($q) => $q->where(fn ($w) => $w->where('e.lead_id', $lead->id)->orWhere(fn ($x) => $x->whereNull('e.lead_id')->where('e.person_id', $lead->person_id))),
                fn ($q) => $q->where('e.person_id', $person->id))
            ->orderByDesc('e.id')
            ->limit(15)
            ->get();
        $search = $forLead ? ($lead->person?->name ?? $lead->title) : $person->name;
    @endphp

    @include('admin::settings.txn.partials.tc-styles')

    <section v-pre class="tc tc-card is-plain" style="margin-top: 12px;">
        @include('admin::settings.txn.partials.card-head', [
            'icon' => 'history',
            'title' => 'Events',
            'text' => $rows->isEmpty() ? 'Nothing recorded yet for this '.($forLead ? 'lead' : 'contact').'.' : 'What happened, newest first. ⚡ = automatic.',
            'aside' => '<a href="'.e(route('admin.leads.event_log', ['q' => $search, 'from' => '2020-01-01'])).'" class="tc-btn">Full log</a>',
        ])
        @if ($rows->isNotEmpty())
            <div class="tc-body">
                @include('admin::leads.events-timeline', ['events' => $rows, 'linkCustomer' => false])
            </div>
        @endif
    </section>
@endif
