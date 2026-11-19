{{-- Scheduled follow-ups as rows with actions (Leads > Scheduled messages, and the lead's Scheduled card).
     Data: $rows (txn_scheduled_messages + person_name, lead_title), $scheduler (MessageScheduler), $canEdit, optional $showCustomer (default true). --}}
@php
    $tz = config('slot_finder.timezone');
    $kindIcon = ['reminder' => 'bell', 'final' => 'mail', 'lost' => 'tag'];
    $statusClass = ['pending' => 'is-on', 'sending' => 'is-warn', 'sent' => 'is-off', 'cancelled' => 'is-off', 'failed' => 'is-warn'];
    $statusText = ['pending' => 'Scheduled', 'sending' => 'Sending…', 'sent' => 'Sent', 'cancelled' => 'Cancelled', 'failed' => 'Failed'];
@endphp

<div class="tc-rows">
    @foreach ($rows as $row)
        @php
            $due = \Carbon\CarbonImmutable::parse($row->due_at, 'UTC')->setTimezone($tz);
            $pending = $row->status === 'pending';
            $relative = $pending ? ($due->isPast() ? 'overdue '.$due->diffForHumans(null, true) : 'in '.$due->diffForHumans(null, true)) : null;
        @endphp
        <div class="tc-svc" style="grid-template-columns: 40px minmax(0, 1fr) auto; align-items: center; {{ $pending ? '' : 'opacity: .75;' }}">
            <span class="tc-event-icon" style="width: 36px; height: 36px;">@include('admin::settings.txn.partials.icon', ['name' => $kindIcon[$row->kind] ?? 'clock', 'size' => 16])</span>
            <div style="min-width: 0;">
                <div class="tc-event-head">
                    <strong style="font-size: 14px;">{{ ucfirst($scheduler->describe($row)) }}</strong>
                    <span class="tc-status {{ $statusClass[$row->status] ?? 'is-off' }}">{{ $statusText[$row->status] ?? $row->status }}</span>
                </div>
                <p class="tc-event-summary">
                    <span title="{{ $due->format('D M j, Y g:i A') }} ({{ \Webkul\Admin\Support\Contact\TxnSettings::timezoneLabel() }})"><strong>{{ $due->format('D M j, g:i A') }}</strong></span>
                    @if ($relative)
                        <span class="tc-sub" style="display: inline;"> · {{ $relative }}</span>
                    @endif
                    @if (! $pending && $row->reason)
                        <span class="tc-sub" style="display: inline;"> · {{ $row->reason }}{{ $row->done_by ? ' ('.$row->done_by.')' : '' }}</span>
                    @endif
                </p>
                @if (($showCustomer ?? true) && ($row->lead_id || $row->person_id))
                    <p class="tc-event-links">
                        @if ($row->lead_id)<a href="{{ route('admin.leads.view', $row->lead_id) }}">{{ $row->lead_title ?? "Lead #{$row->lead_id}" }}</a>@endif
                        @if ($row->person_id)<a href="{{ route('admin.contacts.persons.view', $row->person_id) }}">{{ $row->person_name ?? "Contact #{$row->person_id}" }}</a>@endif
                    </p>
                @endif
            </div>

            @if ($pending && $canEdit)
                <div style="display: flex; flex-wrap: wrap; gap: 6px; justify-content: flex-end; align-items: center;">
                    <form method="POST" action="{{ route('admin.leads.scheduled.send_now', $row->id) }}" onsubmit="return confirm('Send this now?')">
                        @csrf
                        <button type="submit" class="tc-btn">Send now</button>
                    </form>
                    <details style="position: relative;">
                        <summary class="tc-btn" style="list-style: none;">Change time</summary>
                        <form method="POST" action="{{ route('admin.leads.scheduled.retime', $row->id) }}" class="tc-card is-plain" style="position: absolute; right: 0; z-index: 10; margin-top: 6px; padding: 12px; display: flex; gap: 8px; align-items: center; white-space: nowrap;">
                            @csrf
                            <input type="datetime-local" name="when" value="{{ $due->format('Y-m-d\TH:i') }}" min="{{ now($tz)->format('Y-m-d\TH:i') }}" class="tc-input" required>
                            <button type="submit" class="primary-button">Move</button>
                        </form>
                    </details>
                    <form method="POST" action="{{ route('admin.leads.scheduled.cancel', $row->id) }}" onsubmit="return confirm('Cancel this? It will not be sent.')">
                        @csrf
                        <button type="submit" class="tc-btn is-danger">Cancel</button>
                    </form>
                </div>
            @else
                <span></span>
            @endif
        </div>
    @endforeach
</div>
