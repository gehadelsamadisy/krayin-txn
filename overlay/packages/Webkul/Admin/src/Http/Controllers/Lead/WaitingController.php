<?php

namespace Webkul\Admin\Http\Controllers\Lead;

use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Admin\Support\Automation\MessageScheduler;
use Webkul\Admin\Support\Contact\PipelineEvents;
use Webkul\Admin\Support\Contact\StageRoles;

/**
 * Leads > Waiting for booking: every lead in the Contacted stage (booking
 * link sent, not booked yet), with when the link went out and how, the
 * times offered, where the follow-ups stand and the next scheduled step.
 */
class WaitingController extends Controller
{
    public function __construct(private MessageScheduler $scheduler) {}

    public function index(Request $request): View
    {
        $age = in_array($request->query('age'), ['today', 'week', 'older'], true) ? $request->query('age') : '';
        $plan = in_array($request->query('plan'), ['planned', 'none'], true) ? $request->query('plan') : '';
        $q = trim((string) $request->query('q', ''));
        $tz = config('slot_finder.timezone');
        $now = CarbonImmutable::now($tz);

        $rows = $this->rows();

        $totals = [
            ['Waiting', $rows->count()],
            ['Waiting over 3 days', $rows->filter(fn ($r) => $r['sent_at'] && $r['sent_at']->lt($now->subDays(3)))->count()],
            ['Follow-ups in the next 24 h', $rows->filter(fn ($r) => $r['next'] && CarbonImmutable::parse($r['next']->due_at, 'UTC')->lt($now->addDay()))->count()],
            ['No follow-up planned', $rows->filter(fn ($r) => ! $r['next'])->count()],
        ];

        $rows = $rows
            ->filter(fn ($r) => match ($age) {
                'today' => $r['sent_at'] && $r['sent_at']->isSameDay($now),
                'week' => $r['sent_at'] && $r['sent_at']->gte($now->subDays(7)),
                'older' => ! $r['sent_at'] || $r['sent_at']->lt($now->subDays(3)),
                default => true,
            })
            ->filter(fn ($r) => match ($plan) {
                'planned' => (bool) $r['next'],
                'none' => ! $r['next'],
                default => true,
            })
            ->filter(fn ($r) => $q === '' || str_contains(mb_strtolower($r['person_name'].' '.$r['lead_title']), mb_strtolower($q)))
            ->values();

        return view('admin::leads.waiting', [
            'rows' => $rows,
            'totals' => $totals,
            'filters' => compact('age', 'plan', 'q'),
            'scheduler' => $this->scheduler,
            'canSchedule' => bouncer()->hasPermission('leads.scheduled.edit'),
            'canLink' => bouncer()->hasPermission('contacts.persons.view'),
        ]);
    }

    /** Staff: stop all follow-ups of one booking link (pending entries cancelled). */
    public function stop(int $followUpId): RedirectResponse
    {
        $followUp = DB::table('txn_follow_ups')->find($followUpId);

        if (! $followUp || $followUp->stopped_at || $followUp->lost_at) {
            return back()->with('warning', 'These follow-ups were already stopped.');
        }

        $who = (string) (auth()->guard('user')->user()?->name ?? 'Staff');
        DB::table('txn_follow_ups')->where('id', $followUpId)->update(['stopped_at' => now(), 'stop_reason' => "Stopped by {$who}", 'updated_at' => now()]);
        $this->scheduler->cancelForFollowUps([$followUpId], "Follow-ups stopped by {$who}");

        $name = DB::table('persons')->where('id', $followUp->person_id)->value('name');
        PipelineEvents::record('follow_up_stopped', 'Stopped the follow-ups for '.($name ?: 'the customer')." (by {$who})", ['follow_up_id' => $followUpId], $followUp->lead_id, $followUp->person_id);

        return back()->with('success', 'Follow-ups stopped: nothing more will be sent for this link.');
    }

    /**
     * One entry per lead in a Contacted-role stage: lead, customer, the
     * latest follow-up (link) for it, the link's offered times, how the link
     * was sent (event log) and the next pending scheduled step.
     */
    private function rows()
    {
        $tz = config('slot_finder.timezone');
        $contacted = app(StageRoles::class)->stageIds([StageRoles::CONTACTED]);

        if (! $contacted) {
            return collect();
        }

        $leads = DB::table('leads as l')
            ->leftJoin('persons as p', 'p.id', '=', 'l.person_id')
            ->leftJoin('lead_pipeline_stages as st', 'st.id', '=', 'l.lead_pipeline_stage_id')
            ->whereIn('l.lead_pipeline_stage_id', $contacted)
            ->get(['l.id', 'l.title', 'l.person_id', 'l.updated_at', 'p.name as person_name', 'st.name as stage_name']);

        if ($leads->isEmpty()) {
            return collect();
        }

        $personIds = $leads->pluck('person_id')->filter()->unique()->all();
        $followUps = DB::table('txn_follow_ups')->whereIn('person_id', $personIds)->orderByDesc('id')->get();
        $links = DB::table('calcom_booking_links')->whereIn('id', $followUps->pluck('booking_link_id')->filter()->unique()->all())->get()->keyBy('id');
        $linksByLead = DB::table('calcom_booking_links')->whereIn('lead_id', $leads->pluck('id')->all())->orderByDesc('id')->get()->groupBy('lead_id');
        $workers = DB::table('workers')->pluck('name', 'id');
        $pending = MessageScheduler::ready()
            ? DB::table('txn_scheduled_messages')->whereIn('follow_up_id', $followUps->pluck('id')->all())->where('status', 'pending')->orderBy('due_at')->get()->groupBy('follow_up_id')
            : collect();
        $sentEvents = DB::table('txn_events')->whereIn('lead_id', $leads->pluck('id')->all())->where('event', 'link_sent')->orderByDesc('id')->get()->groupBy('lead_id');

        return $leads->map(function ($lead) use ($followUps, $links, $linksByLead, $workers, $pending, $sentEvents, $tz) {
            // This lead's own link first, else one sent from the contact page (no lead).
            $followUp = $followUps->first(fn ($f) => (int) $f->lead_id === (int) $lead->id)
                ?? $followUps->first(fn ($f) => (int) $f->person_id === (int) $lead->person_id && ! $f->lead_id);
            $link = ($followUp && $followUp->booking_link_id ? $links->get($followUp->booking_link_id) : null)
                ?? $linksByLead->get($lead->id)?->first();
            $times = collect(json_decode($link->slot_workers ?? '[]', true) ?: [])
                ->sortKeys()
                ->map(fn ($workerId, $slot) => [
                    'label' => PipelineEvents::slotLabel((string) $slot, $link->timezone ?? $tz),
                    'worker' => $workers[(int) $workerId] ?? null,
                    'past' => CarbonImmutable::createFromFormat('!Y-m-d\TH:i', (string) $slot, $link->timezone ?? $tz)->isPast(),
                ])->values();
            $event = $sentEvents->get($lead->id)?->first();
            $eventData = $event ? (json_decode((string) $event->data, true) ?: []) : [];

            return [
                'lead_id' => $lead->id,
                'lead_title' => (string) $lead->title,
                'person_id' => $lead->person_id,
                'person_name' => (string) ($lead->person_name ?? ''),
                'stage' => $lead->stage_name,
                'follow_up' => $followUp,
                'sent_at' => $followUp ? CarbonImmutable::parse($followUp->link_sent_at, 'UTC')->setTimezone($tz) : null,
                'url' => $followUp->booking_url ?? null,
                'email' => $followUp->email ?? null,
                'sms' => ($eventData['sms']['status'] ?? null) === 'sent' ? ($eventData['sms']['to'] ?? 'yes') : null,
                'times' => $times,
                'next' => $followUp ? $pending->get($followUp->id)?->first() : null,
            ];
        })->sortByDesc(fn ($r) => $r['sent_at']?->timestamp ?? 0)->values();
    }
}
