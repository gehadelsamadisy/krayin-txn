{{-- Leads > Scheduled messages (ScheduledMessagesController, MessageScheduler). Styles: settings/txn/partials/tc-styles (.tc). --}}
<x-admin::layouts>
    <x-slot:title>
        Scheduled messages
    </x-slot>

    @include('admin::settings.txn.partials.tc-styles')

    @php
        $tabs = ['pending' => 'Scheduled', 'sent' => 'Sent', 'cancelled' => 'Cancelled', 'failed' => 'Failed', 'all' => 'All'];
    @endphp

    <div class="flex flex-col gap-4">
        <div class="tc tc-top">
            <span class="tc-top-icon">@include('admin::settings.txn.partials.icon', ['name' => 'clock', 'size' => 22])</span>
            <div class="tc-top-text">
                <span class="tc-crumb">Leads</span>
                <h1>Scheduled messages</h1>
                <p>Every follow-up planned after a booking link, with its exact time. Each one goes out at that time, unless the customer books, the lead leaves Contacted, a newer link is sent or the step is switched off; then it is cancelled with the reason.</p>
                <div class="tc-stats">
                    <span class="tc-stat"><b>{{ (int) ($counts['pending'] ?? 0) }}</b> scheduled</span>
                    <span class="tc-stat"><b>{{ (int) ($counts['sent'] ?? 0) }}</b> sent</span>
                    <span class="tc-stat"><b>{{ (int) ($counts['cancelled'] ?? 0) }}</b> cancelled</span>
                    @if ($counts['failed'] ?? 0)
                        <span class="tc-stat"><b>{{ (int) $counts['failed'] }}</b> failed</span>
                    @endif
                </div>
            </div>
            <a href="{{ route('admin.settings.txn.automation') }}" class="secondary-button">Automation settings</a>
            <a href="{{ route('admin.leads.index') }}" class="secondary-button">Back to leads</a>
        </div>

        <form method="GET" action="{{ route('admin.leads.scheduled') }}" class="tc tc-card is-plain">
            <div class="tc-body" style="flex-direction: row; flex-wrap: wrap; align-items: center; gap: 10px;">
                <div class="tc-switch" role="tablist">
                    @foreach ($tabs as $key => $label)
                        <label>
                            <input type="radio" name="status" value="{{ $key }}" @checked($status === $key) onchange="this.form.submit()">
                            <span>{{ $label }}</span>
                        </label>
                    @endforeach
                </div>
                <input type="search" name="q" value="{{ $q }}" placeholder="Customer or lead" class="tc-input" style="max-width: 260px;">
                <button type="submit" class="secondary-button">Search</button>
            </div>
        </form>

        <section class="tc tc-card is-plain">
            <div class="tc-body">
                @if ($rows->isEmpty())
                    <div class="tc-empty-state">
                        @include('admin::settings.txn.partials.icon', ['name' => 'clock', 'size' => 34])
                        <strong>Nothing here</strong>
                        Follow-ups are scheduled when a booking link is emailed while the Email engine is on (Settings &gt; TXN Settings &gt; Automation).
                    </div>
                @else
                    @include('admin::leads.scheduled-rows', ['rows' => $rows])
                @endif
            </div>
        </section>

        <div>{{ $rows->links() }}</div>
    </div>
</x-admin::layouts>
