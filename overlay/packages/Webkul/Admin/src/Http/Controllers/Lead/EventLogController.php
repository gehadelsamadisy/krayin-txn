<?php

namespace Webkul\Admin\Http\Controllers\Lead;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Admin\Support\Contact\PipelineEvents;

/**
 * Leads > Event log: every pipeline step from txn_events (PipelineEvents),
 * newest first, with filters (dates, type, customer/lead, who) and CSV export.
 */
class EventLogController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $this->filters($request);
        $events = $this->query($filters)->orderByDesc('e.id')->paginate(50)->withQueryString();

        // Totals for the chosen dates (other filters ignored, so the cards stay a summary).
        $totals = $this->query(['from' => $filters['from'], 'to' => $filters['to']])
            ->selectRaw('e.event, COUNT(*) AS n')
            ->groupBy('e.event')
            ->pluck('n', 'event');

        $checked = (int) $this->query(['from' => $filters['from'], 'to' => $filters['to']])
            ->where('e.event', 'lead_checked')
            ->count();

        return view('admin::leads.event-log', [
            'events' => $events,
            'filters' => $filters,
            'types' => PipelineEvents::EVENTS,
            'groups' => PipelineEvents::GROUPS,
            'stats' => [
                ['Leads checked', $checked, 'leads'],
                ['Links sent', (int) ($totals['link_sent'] ?? 0), 'links'],
                ['Bookings received', (int) ($totals['booking_received'] ?? 0), 'bookings'],
                ['Cancellations', (int) ($totals['booking_cancelled'] ?? 0), 'bookings'],
                ['Not sent / failed', (int) (($totals['automation_blocked'] ?? 0) + ($totals['automation_failed'] ?? 0)), 'automation'],
            ],
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $query = $this->query($this->filters($request))->orderByDesc('e.id')->limit(20000);
        $name = 'txn-event-log-'.now()->format('Y-m-d-His').'.csv';

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['When', 'Event', 'Summary', 'Who', 'How', 'Lead', 'Customer', 'Details'], escape: '');

            foreach ($query->cursor() as $row) {
                fputcsv($out, [
                    $row->occurred_at,
                    PipelineEvents::EVENTS[$row->event][0] ?? $row->event,
                    $row->summary,
                    $row->actor,
                    $row->source,
                    $row->lead_title ?? $row->lead_id,
                    $row->person_name ?? $row->person_id,
                    $row->data,
                ], escape: '');
            }

            fclose($out);
        }, $name, ['Content-Type' => 'text/csv']);
    }

    /** @return array{from: string, to: string, type: string, q: string, who: string} */
    private function filters(Request $request): array
    {
        $date = fn ($value, $default) => is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : $default;
        $today = CarbonImmutable::now(config('slot_finder.timezone'));

        return [
            'from' => $date($request->query('from'), $today->subDays(6)->toDateString()),
            'to' => $date($request->query('to'), $today->toDateString()),
            'type' => (string) $request->query('type', ''),
            'q' => trim((string) $request->query('q', '')),
            'who' => in_array($request->query('who'), ['automatic', 'staff'], true) ? $request->query('who') : '',
        ];
    }

    private function query(array $filters): Builder
    {
        $query = DB::table('txn_events as e')
            ->leftJoin('leads as l', 'l.id', '=', 'e.lead_id')
            ->leftJoin('persons as p', 'p.id', '=', 'e.person_id')
            ->select('e.*', 'l.title as lead_title', 'p.name as person_name');

        // The dates are company-time-zone days; stored times are app (UTC) times.
        if (! empty($filters['from'])) {
            $query->where('e.occurred_at', '>=', CarbonImmutable::parse($filters['from'], config('slot_finder.timezone'))->startOfDay()->utc()->toDateTimeString());
        }

        if (! empty($filters['to'])) {
            $query->where('e.occurred_at', '<=', CarbonImmutable::parse($filters['to'], config('slot_finder.timezone'))->endOfDay()->utc()->toDateTimeString());
        }

        $type = $filters['type'] ?? '';

        if (str_starts_with($type, 'group:')) {
            $events = array_keys(array_filter(PipelineEvents::EVENTS, fn ($meta) => $meta[1] === substr($type, 6)));
            $query->whereIn('e.event', $events ?: ['-']);
        } elseif ($type !== '' && isset(PipelineEvents::EVENTS[$type])) {
            $query->where('e.event', $type);
        }

        if (($filters['q'] ?? '') !== '') {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $filters['q']).'%';
            $query->where(fn ($q) => $q->where('p.name', 'like', $like)->orWhere('l.title', 'like', $like)->orWhere('e.summary', 'like', $like));
        }

        match ($filters['who'] ?? '') {
            'automatic' => $query->whereIn('e.source', ['automation', 'webhook', 'scheduler']),
            'staff' => $query->where('e.source', 'button'),
            default => null,
        };

        return $query;
    }
}
