{{-- Pipeline events as a timeline (Leads > Event log, and the Events card on a lead or contact).
     Data: $events (rows of txn_events, optionally with lead_title / person_name), optional $showWho (default true), $linkCustomer (default true). --}}
@php
    $groupIcon = ['leads' => 'list', 'links' => 'link', 'follow_ups' => 'bell', 'bookings' => 'calendar', 'automation' => 'sparkle'];
    $tone = fn (string $event) => match (true) {
        in_array($event, ['automation_failed', 'booking_cancelled', 'marked_lost'], true) => 'is-bad',
        in_array($event, ['automation_blocked', 'automation_skipped', 'follow_up_stopped'], true) => 'is-warn',
        in_array($event, ['link_sent', 'booking_received', 'reminder_sent', 'final_sent'], true) => 'is-ok',
        default => '',
    };
    $types = \Webkul\Admin\Support\Contact\PipelineEvents::EVENTS;
    $tz = config('slot_finder.timezone');
@endphp

<ol class="tc-events">
    @foreach ($events as $row)
        @php
            [$label, $group] = $types[$row->event] ?? [$row->event, 'automation'];
            $when = \Carbon\CarbonImmutable::parse($row->occurred_at, 'UTC')->setTimezone($tz);
            $automatic = in_array($row->source, ['automation', 'webhook', 'scheduler'], true);
        @endphp
        <li class="tc-event {{ $tone($row->event) }}">
            <span class="tc-event-icon">@include('admin::settings.txn.partials.icon', ['name' => $groupIcon[$group] ?? 'info', 'size' => 15])</span>
            <div class="tc-event-body">
                <div class="tc-event-head">
                    <span class="tc-event-type">{{ $label }}</span>
                    <span class="tc-event-when" title="{{ $when->format('D M j, Y g:i:s A') }}">{{ $when->format('M j, g:i A') }}</span>
                    @if ($showWho ?? true)
                        <span class="tc-chip {{ $automatic ? '' : 'is-muted' }}">{{ $automatic ? '⚡ ' : '' }}{{ $row->actor }}</span>
                    @endif
                </div>
                <p class="tc-event-summary">{{ $row->summary }}</p>
                @if (($linkCustomer ?? true) && ($row->lead_id || $row->person_id))
                    <p class="tc-event-links">
                        @if ($row->lead_id)
                            <a href="{{ route('admin.leads.view', $row->lead_id) }}">{{ $row->lead_title ?? "Lead #{$row->lead_id}" }}</a>
                        @endif
                        @if ($row->person_id)
                            <a href="{{ route('admin.contacts.persons.view', $row->person_id) }}">{{ $row->person_name ?? "Contact #{$row->person_id}" }}</a>
                        @endif
                    </p>
                @endif
            </div>
        </li>
    @endforeach
</ol>
