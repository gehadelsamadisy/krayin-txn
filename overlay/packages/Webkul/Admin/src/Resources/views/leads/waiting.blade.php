{{-- Leads > Waiting for booking (WaitingController): leads in Contacted, with link, times offered, follow-ups and next step. Styles: settings/txn/partials/tc-styles (.tc). --}}
<x-admin::layouts>
    <x-slot:title>
        Waiting for booking
    </x-slot>

    @include('admin::settings.txn.partials.tc-styles')

    @php
        $tz = config('slot_finder.timezone');
        $ago = fn ($date) => $date ? $date->diffForHumans() : null;
        $step = fn ($followUp) => match (true) {
            ! $followUp => [null, 'No booking link from Krayin (contacted another way)'],
            (bool) $followUp->stopped_at => ['is-off', 'Follow-ups stopped: '.($followUp->stop_reason ?: '')],
            (bool) $followUp->final_sent_at => ['is-on', 'Final email sent '.\Carbon\CarbonImmutable::parse($followUp->final_sent_at, 'UTC')->setTimezone($tz)->format('D M j, g:i A')],
            (bool) $followUp->reminder_sent_at => ['is-on', 'Reminder sent '.\Carbon\CarbonImmutable::parse($followUp->reminder_sent_at, 'UTC')->setTimezone($tz)->format('D M j, g:i A')],
            default => ['is-off', 'No follow-up sent yet'],
        };
        $choices = [
            'age' => ['' => 'Any time', 'today' => 'Sent today', 'week' => 'Last 7 days', 'older' => 'Over 3 days ago'],
            'plan' => ['' => 'Any', 'planned' => 'Follow-up planned', 'none' => 'No follow-up planned'],
        ];
    @endphp

    <div class="flex flex-col gap-4">
        <div class="tc tc-top">
            <span class="tc-top-icon">@include('admin::settings.txn.partials.icon', ['name' => 'link', 'size' => 22])</span>
            <div class="tc-top-text">
                <span class="tc-crumb">Leads</span>
                <h1>Waiting for booking</h1>
                <p>Customers who were sent a booking link (lead in Contacted) and haven't booked yet: when and how the link went out, the times offered, where the follow-ups stand and what happens next.</p>
            </div>
            <a href="{{ route('admin.leads.scheduled') }}" class="secondary-button">Scheduled messages</a>
            <a href="{{ route('admin.leads.index') }}" class="secondary-button">Back to leads</a>
        </div>

        <div class="tc tc-statgrid" style="grid-template-columns: repeat(4, minmax(0, 1fr));">
            @foreach ($totals as [$label, $value])
                <div class="tc-statcard"><b>{{ $value }}</b><span>{{ $label }}</span></div>
            @endforeach
        </div>

        <form method="GET" action="{{ route('admin.leads.waiting') }}" class="tc tc-card is-plain">
            <div class="tc-body" style="flex-direction: row; flex-wrap: wrap; align-items: end; gap: 10px;">
                <div class="tc-field">
                    <label class="tc-label" for="f-age">Link sent</label>
                    <select id="f-age" name="age" class="tc-input" onchange="this.form.submit()">
                        @foreach ($choices['age'] as $value => $label)
                            <option value="{{ $value }}" @selected($filters['age'] === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="tc-field">
                    <label class="tc-label" for="f-plan">Follow-ups</label>
                    <select id="f-plan" name="plan" class="tc-input" onchange="this.form.submit()">
                        @foreach ($choices['plan'] as $value => $label)
                            <option value="{{ $value }}" @selected($filters['plan'] === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="tc-field" style="flex: 1 1 220px;">
                    <label class="tc-label" for="f-q">Customer or lead</label>
                    <input id="f-q" type="search" name="q" value="{{ $filters['q'] }}" class="tc-input" placeholder="Name or lead title">
                </div>
                <button type="submit" class="primary-button">Show</button>
            </div>
        </form>

        <section class="tc tc-card is-plain">
            @include('admin::settings.txn.partials.card-head', [
                'icon' => 'users',
                'title' => $rows->count().' '.($rows->count() === 1 ? 'customer' : 'customers'),
                'text' => 'Newest link first. Times in '.\Webkul\Admin\Support\Contact\TxnSettings::timezoneLabel().'.',
            ])
            <div class="tc-body">
                @if ($rows->isEmpty())
                    <div class="tc-empty-state">
                        @include('admin::settings.txn.partials.icon', ['name' => 'check', 'size' => 34])
                        <strong>Nobody is waiting</strong>
                        No lead is in Contacted with these filters.
                    </div>
                @else
                    <div class="tc-rows">
                        @foreach ($rows as $row)
                            @php
                                [$stepClass, $stepText] = $step($row['follow_up']);
                                $next = $row['next'];
                                $nextDue = $next ? \Carbon\CarbonImmutable::parse($next->due_at, 'UTC')->setTimezone($tz) : null;
                                $old = $row['sent_at'] && $row['sent_at']->lt(now($tz)->subDays(3));
                            @endphp
                            <div class="tc-svc tc-wait" style="{{ $old ? 'border-left: 4px solid var(--tc-warn);' : '' }}">
                                {{-- Customer --}}
                                <div style="min-width: 0;">
                                    <strong style="display: block; font-size: 14px;">
                                        @if ($row['person_id'])
                                            <a href="{{ route('admin.contacts.persons.view', $row['person_id']) }}" style="color: var(--tc-ink);">{{ $row['person_name'] ?: 'Contact #'.$row['person_id'] }}</a>
                                        @else
                                            No contact
                                        @endif
                                    </strong>
                                    <a href="{{ route('admin.leads.view', $row['lead_id']) }}" class="tc-sub" style="color: var(--tc-brand); font-weight: 600;">{{ $row['lead_title'] }}</a>
                                    @if ($row['sent_at'])
                                        <span class="tc-sub" style="margin-top: 6px;">
                                            Link sent <strong style="color: var(--tc-ink);">{{ $row['sent_at']->format('D M j, g:i A') }}</strong> ({{ $ago($row['sent_at']) }})
                                        </span>
                                        <div class="tc-chips" style="margin-top: 4px;">
                                            @if ($row['email'])<span class="tc-chip is-muted">✉ {{ $row['email'] }}</span>@endif
                                            @if ($row['sms'])<span class="tc-chip is-muted">💬 {{ $row['sms'] }}</span>@endif
                                        </div>
                                    @endif
                                </div>

                                {{-- Times offered --}}
                                <div style="min-width: 0;">
                                    <span class="tc-label" style="font-size: 12px;">Times offered</span>
                                    @if ($row['times']->isEmpty())
                                        <span class="tc-sub">—</span>
                                    @else
                                        <div class="tc-chips" style="margin-top: 4px;">
                                            @foreach ($row['times'] as $time)
                                                <span class="tc-chip {{ $time['past'] ? 'is-muted' : '' }}" title="{{ $time['past'] ? 'Already past' : '' }}" style="{{ $time['past'] ? 'text-decoration: line-through;' : '' }}">{{ $time['label'] }}{{ $time['worker'] ? ' · '.$time['worker'] : '' }}</span>
                                            @endforeach
                                        </div>
                                    @endif
                                </div>

                                {{-- Follow-ups --}}
                                <div style="min-width: 0;">
                                    <span class="tc-label" style="font-size: 12px;">Follow-ups</span>
                                    @if ($stepClass)
                                        <span class="tc-status {{ $stepClass }}" style="white-space: normal; margin-top: 4px;">{{ $stepText }}</span>
                                    @else
                                        <span class="tc-sub">{{ $stepText }}</span>
                                    @endif
                                    <span class="tc-sub" style="margin-top: 6px;">
                                        @if ($next)
                                            Next: <strong style="color: var(--tc-ink);">{{ ucfirst(\Illuminate\Support\Str::before($scheduler->describe($next), ' to ')) }}</strong>
                                            · {{ $nextDue->format('D M j, g:i A') }} ({{ $nextDue->isPast() ? 'overdue' : $ago($nextDue) }})
                                        @elseif ($row['follow_up'] && ! $row['follow_up']->stopped_at)
                                            <span style="color: var(--tc-warn); font-weight: 600;">No follow-up planned</span> (sent while the Email engine was off: use txn:follow-ups, or send a new link)
                                        @endif
                                    </span>
                                </div>

                                {{-- Actions --}}
                                <div style="display: flex; flex-direction: column; gap: 6px; align-items: stretch;">
                                    <a href="{{ route('admin.leads.view', $row['lead_id']) }}" class="tc-btn">Open lead</a>
                                    @if ($row['url'])
                                        <a href="{{ $row['url'] }}" target="_blank" rel="noopener noreferrer" class="tc-btn">Booking page</a>
                                    @endif
                                    @if ($canLink && $row['person_id'])
                                        <a href="{{ route('admin.contacts.persons.booking_link.create', [$row['person_id'], 'lead' => $row['lead_id']]) }}" class="tc-btn">New link</a>
                                    @endif
                                    @if ($canSchedule && $next)
                                        <form method="POST" action="{{ route('admin.leads.scheduled.send_now', $next->id) }}" onsubmit="return confirm('Send the next follow-up now?')">
                                            @csrf
                                            <button type="submit" class="tc-btn" style="width: 100%;">Send next now</button>
                                        </form>
                                    @endif
                                    @if ($canSchedule && $row['follow_up'] && ! $row['follow_up']->stopped_at && ! $row['follow_up']->lost_at)
                                        <form method="POST" action="{{ route('admin.leads.waiting.stop', $row['follow_up']->id) }}" onsubmit="return confirm('Stop all follow-ups for this link? Nothing more will be sent.')">
                                            @csrf
                                            <button type="submit" class="tc-btn is-danger" style="width: 100%;">Stop follow-ups</button>
                                        </form>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </section>
    </div>
</x-admin::layouts>
