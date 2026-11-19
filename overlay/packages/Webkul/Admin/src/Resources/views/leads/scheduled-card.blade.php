{{-- "Scheduled" card on a lead page (view hook in AppServiceProvider): this lead's follow-up schedule. Data: $lead (from the hook). --}}
@if (bouncer()->hasPermission('leads.scheduled') && \Webkul\Admin\Support\Automation\MessageScheduler::ready() && isset($lead))
    @php
        $rows = \Webkul\Admin\Http\Controllers\Lead\ScheduledMessagesController::query()
            ->where('s.lead_id', $lead->id)
            ->orderByRaw("s.status = 'pending' DESC")
            ->orderBy('s.due_at')
            ->limit(10)
            ->get();
    @endphp

    @if ($rows->isNotEmpty())
        @include('admin::settings.txn.partials.tc-styles')

        <section v-pre class="tc tc-card is-plain" style="margin-top: 12px;">
            @include('admin::settings.txn.partials.card-head', [
                'icon' => 'clock',
                'title' => 'Scheduled',
                'text' => $rows->where('status', 'pending')->count().' follow-up(s) planned for this lead.',
                'aside' => '<a href="'.e(route('admin.leads.scheduled', ['status' => 'all', 'q' => $lead->title])).'" class="tc-btn">All scheduled</a>',
            ])
            <div class="tc-body">
                @include('admin::leads.scheduled-rows', [
                    'rows' => $rows,
                    'scheduler' => app(\Webkul\Admin\Support\Automation\MessageScheduler::class),
                    'canEdit' => bouncer()->hasPermission('leads.scheduled.edit'),
                    'showCustomer' => false,
                ])
            </div>
        </section>
    @endif
@endif
