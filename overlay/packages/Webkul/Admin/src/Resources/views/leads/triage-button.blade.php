{{-- Lead page, under the title (hook admin.leads.view.title.after, AppServiceProvider).
     The About Lead panel cuts long values, so the check's result is shown here in full. --}}
@php
    $triage = DB::table('attribute_values as v')
        ->join('attributes as a', 'a.id', '=', 'v.attribute_id')
        ->where('v.entity_type', 'leads')
        ->where('v.entity_id', $lead->id)
        ->whereIn('a.code', ['txn_next_action', 'txn_location', 'txn_missing', 'txn_service_category'])
        ->pluck('v.text_value', 'a.code');
@endphp

@if ($triage->isNotEmpty())
    <div class="rounded-lg border border-gray-200 p-4 text-sm dark:border-gray-800 dark:text-gray-300">
        <p class="font-medium dark:text-white">{{ $triage['txn_next_action'] ?? '' }}</p>

        <p class="text-xs text-gray-500">
            {{ $triage['txn_service_category'] ?? '' }} · {{ $triage['txn_location'] ?? '' }} · Missing: {{ $triage['txn_missing'] ?? '' }}
        </p>
    </div>
@endif

<div class="flex flex-wrap gap-2">
    {{-- The booking link for this lead: its booking goes to this lead only. --}}
    @if ($lead->person_id && bouncer()->hasPermission('contacts.persons.view'))
        <a href="{{ route('admin.contacts.persons.booking_link.create', [$lead->person_id, 'lead' => $lead->id]) }}" class="primary-button">
            Send booking link
        </a>
    @endif

    @if (bouncer()->hasPermission('leads.edit'))
        <form method="POST" action="{{ route('admin.leads.triage', $lead->id) }}">
            @csrf

            <button type="submit" class="secondary-button" title="Clean the contact data, classify the service, check the location and set the next action">
                Re-check lead
            </button>
        </form>
    @endif
</div>
