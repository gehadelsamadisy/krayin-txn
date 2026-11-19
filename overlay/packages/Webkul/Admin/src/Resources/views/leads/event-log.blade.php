{{-- Leads > Event log (EventLogController): every pipeline step, by staff or automation. Styles: settings/txn/partials/tc-styles (.tc). --}}
<x-admin::layouts>
    <x-slot:title>
        Event log
    </x-slot>

    @include('admin::settings.txn.partials.tc-styles')

    <div class="flex flex-col gap-4">
        <div class="tc tc-top">
            <span class="tc-top-icon">@include('admin::settings.txn.partials.icon', ['name' => 'history', 'size' => 22])</span>
            <div class="tc-top-text">
                <span class="tc-crumb">Leads</span>
                <h1>Event log</h1>
                <p>Every step of the pipeline: leads imported and checked, times suggested, links created and sent, follow-ups, bookings received, moved and cancelled. ⚡ marks steps done automatically.</p>
            </div>
            <a href="{{ route('admin.leads.event_log.export', request()->query()) }}" class="secondary-button">Export CSV</a>
            <a href="{{ route('admin.leads.index') }}" class="secondary-button">Back to leads</a>
        </div>

        <div class="tc tc-statgrid">
            @foreach ($stats as [$label, $value])
                <div class="tc-statcard"><b>{{ number_format($value) }}</b><span>{{ $label }}</span></div>
            @endforeach
        </div>

        <form method="GET" action="{{ route('admin.leads.event_log') }}" class="tc tc-card is-plain">
            <div class="tc-body">
                <div class="tc-filters">
                    <div class="tc-field">
                        <label for="f-from" class="tc-label">From</label>
                        <input id="f-from" type="date" name="from" value="{{ $filters['from'] }}" class="tc-input">
                    </div>
                    <div class="tc-field">
                        <label for="f-to" class="tc-label">To</label>
                        <input id="f-to" type="date" name="to" value="{{ $filters['to'] }}" class="tc-input">
                    </div>
                    <div class="tc-field">
                        <label for="f-type" class="tc-label">What</label>
                        <select id="f-type" name="type" class="tc-input">
                            <option value="">Everything</option>
                            @foreach ($groups as $group => $groupLabel)
                                <optgroup label="{{ $groupLabel }}">
                                    <option value="group:{{ $group }}" @selected($filters['type'] === "group:{$group}")>All {{ strtolower($groupLabel) }}</option>
                                    @foreach ($types as $event => [$label, $eventGroup])
                                        @if ($eventGroup === $group)
                                            <option value="{{ $event }}" @selected($filters['type'] === $event)>{{ $label }}</option>
                                        @endif
                                    @endforeach
                                </optgroup>
                            @endforeach
                        </select>
                    </div>
                    <div class="tc-field">
                        <label for="f-q" class="tc-label">Customer or lead</label>
                        <input id="f-q" type="search" name="q" value="{{ $filters['q'] }}" placeholder="Name, lead title, text" class="tc-input">
                    </div>
                    <div class="tc-field">
                        <label for="f-who" class="tc-label">Who</label>
                        <select id="f-who" name="who" class="tc-input">
                            <option value="">Anyone</option>
                            <option value="automatic" @selected($filters['who'] === 'automatic')>⚡ Automatic</option>
                            <option value="staff" @selected($filters['who'] === 'staff')>Staff</option>
                        </select>
                    </div>
                    <button type="submit" class="primary-button">Show</button>
                </div>
            </div>
        </form>

        <section class="tc tc-card is-plain">
            @include('admin::settings.txn.partials.card-head', [
                'icon' => 'history',
                'title' => $events->total().' '.($events->total() === 1 ? 'event' : 'events'),
                'text' => 'Newest first. Times in '.\Webkul\Admin\Support\Contact\TxnSettings::timezoneLabel().'.',
            ])
            <div class="tc-body">
                @if ($events->isEmpty())
                    <div class="tc-empty-state">
                        @include('admin::settings.txn.partials.icon', ['name' => 'history', 'size' => 34])
                        <strong>Nothing in this range</strong>
                        Change the dates or filters above.
                    </div>
                @else
                    @include('admin::leads.events-timeline', ['events' => $events])
                @endif
            </div>
        </section>

        <div>{{ $events->links() }}</div>
    </div>
</x-admin::layouts>
