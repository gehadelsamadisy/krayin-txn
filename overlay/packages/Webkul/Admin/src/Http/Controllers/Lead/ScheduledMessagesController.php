<?php

namespace Webkul\Admin\Http\Controllers\Lead;

use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Admin\Support\Automation\MessageScheduler;

/**
 * Leads > Scheduled messages (MessageScheduler): every planned follow-up with
 * its exact time; staff can cancel one, send it now or change its time.
 * The same actions are on each lead's "Scheduled" card.
 */
class ScheduledMessagesController extends Controller
{
    public function __construct(private MessageScheduler $scheduler) {}

    public function index(Request $request): View
    {
        $status = in_array($request->query('status'), ['pending', 'sent', 'cancelled', 'failed', 'all'], true) ? $request->query('status') : 'pending';
        $q = trim((string) $request->query('q', ''));

        $rows = $this->query()
            ->when($status !== 'all', fn ($query) => $query->where('s.status', $status === 'pending' ? 'pending' : $status))
            ->when($q !== '', function ($query) use ($q) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $q).'%';
                $query->where(fn ($w) => $w->where('p.name', 'like', $like)->orWhere('l.title', 'like', $like));
            })
            ->orderBy($status === 'pending' ? 's.due_at' : 's.updated_at', $status === 'pending' ? 'asc' : 'desc')
            ->paginate(50)
            ->withQueryString();

        return view('admin::leads.scheduled', [
            'rows' => $rows,
            'status' => $status,
            'q' => $q,
            'counts' => DB::table('txn_scheduled_messages')->selectRaw('status, COUNT(*) AS n')->groupBy('status')->pluck('n', 'status'),
            'scheduler' => $this->scheduler,
            'canEdit' => bouncer()->hasPermission('leads.scheduled.edit'),
        ]);
    }

    public function cancel(int $id): RedirectResponse
    {
        return back()->with(...$this->scheduler->cancelOne($id, $this->who())
            ? ['success', 'Cancelled: it will not be sent.']
            : ['warning', 'It was no longer pending.']);
    }

    public function sendNow(int $id): RedirectResponse
    {
        $result = $this->scheduler->sendNow($id, $this->who());

        return back()->with(...match ($result['status']) {
            'sent' => ['success', 'Sent now: '.$result['reason'].'.'],
            'retry' => ['warning', 'Not sent: '.$result['reason'].'.'],
            default => ['warning', 'Not sent: '.$result['reason'].'.'],
        });
    }

    public function retime(Request $request, int $id): RedirectResponse
    {
        $value = (string) $request->input('when', '');
        $when = preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $value)
            ? CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $value, config('slot_finder.timezone'))
            : null;

        if (! $when || $when->isPast()) {
            return back()->with('error', 'Choose a date and time in the future.');
        }

        return back()->with(...$this->scheduler->changeTime($id, $when, $this->who())
            ? ['success', 'Moved to '.$when->format('D M j, g:i A').'.']
            : ['warning', 'It was no longer pending.']);
    }

    /** Rows with the customer's and lead's names (also used by the lead card). */
    public static function query()
    {
        return DB::table('txn_scheduled_messages as s')
            ->leftJoin('persons as p', 'p.id', '=', 's.person_id')
            ->leftJoin('leads as l', 'l.id', '=', 's.lead_id')
            ->select('s.*', 'p.name as person_name', 'l.title as lead_title');
    }

    private function who(): string
    {
        return (string) (auth()->guard('user')->user()?->name ?? 'Staff');
    }
}
