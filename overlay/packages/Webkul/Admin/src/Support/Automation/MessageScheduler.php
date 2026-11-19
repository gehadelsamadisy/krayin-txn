<?php

namespace Webkul\Admin\Support\Automation;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;
use Webkul\Admin\Jobs\SendScheduledMessage;
use Webkul\Admin\Support\Contact\FollowUpService;
use Webkul\Admin\Support\Contact\PipelineEvents;

/**
 * The follow-up schedule (table txn_scheduled_messages; Leads > Scheduled
 * messages). When a booking link is emailed and the Email engine is on, each
 * follow-up step becomes a row with its exact time:
 *
 *   reminder  link time + reminder hours   email (+ text if the SMS engine allows)
 *   final     link time + final hours      email (+ text)
 *   lost      link time + Lost hours       lead → Lost (only if still in Contacted)
 *
 * Each pending row has a delayed job (SendScheduledMessage, database queue
 * "txn-schedule") that fires at due_at. The row is the truth: a job whose row
 * is no longer pending, or whose time was changed, does nothing.
 *
 * Rows are cancelled (with the reason) when the customer books, the lead
 * leaves Contacted, a newer link is sent, or the step / engine is switched
 * off; switching it on again schedules the steps still in the future. Staff
 * can cancel, send now or change the time of a row.
 */
final class MessageScheduler
{
    /** kind => [label, hours setting, engine rule] */
    public const KINDS = [
        'reminder' => ['Reminder', 'follow_ups.reminder_after_hours', 'email.reminder'],
        'final' => ['Final email', 'follow_ups.final_after_hours', 'email.final'],
        'lost' => ['Mark Lost', 'follow_ups.lost_after_hours', 'email.lost'],
    ];

    public const QUEUE = 'txn-schedule';

    public static function ready(): bool
    {
        static $ready = null;

        return $ready ??= Schema::hasTable('txn_scheduled_messages');
    }

    /** A booking link was emailed (FollowUpService::start): schedule its steps that are switched on. */
    public function scheduleFollowUp(int $followUpId): void
    {
        if (! self::ready() || ! Automation::on('email')) {
            return;
        }

        $followUp = DB::table('txn_follow_ups')->find($followUpId);

        foreach (self::KINDS as $kind => [, , $rule]) {
            if ($followUp && Automation::rule($rule)) {
                $this->create($followUp, $kind);
            }
        }
    }

    /** One row (if its time is still ahead) + its job. */
    private function create(object $followUp, string $kind): ?int
    {
        $due = $this->dueFor($followUp, $kind);

        if ($due->isPast()) {
            return null;
        }

        $id = (int) DB::table('txn_scheduled_messages')->insertGetId([
            'kind' => $kind,
            'follow_up_id' => $followUp->id,
            'person_id' => $followUp->person_id,
            'lead_id' => $followUp->lead_id,
            'due_at' => $due->utc()->toDateTimeString(),
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = DB::table('txn_scheduled_messages')->find($id);
        $this->dispatch($row);

        PipelineEvents::record('message_scheduled', 'Scheduled '.$this->describe($row).' for '.$this->when($row->due_at), [
            'scheduled_id' => $id, 'kind' => $kind, 'due_at' => $row->due_at,
        ], $followUp->lead_id, $followUp->person_id);

        return $id;
    }

    private function dueFor(object $followUp, string $kind): CarbonImmutable
    {
        return CarbonImmutable::parse($followUp->link_sent_at)->addHours((int) config(self::KINDS[$kind][1]));
    }

    /** The job that fires the row at its exact time. */
    private function dispatch(object $row): void
    {
        try {
            SendScheduledMessage::dispatch((int) $row->id, (string) $row->due_at)
                ->onConnection('database')
                ->onQueue(self::QUEUE)
                ->delay(CarbonImmutable::parse($row->due_at, 'UTC'));
        } catch (Throwable $exception) {
            Log::error('Scheduled message: the job could not be queued (run txn:schedule-repair).', ['id' => $row->id, 'error' => $exception->getMessage()]);
        }
    }

    /**
     * The job's work, at the row's time. Does nothing unless the row is still
     * pending at exactly the time this job was made for.
     */
    public function fire(int $id, string $dueAt): void
    {
        if (! $this->claim($id, $dueAt)) {
            return;
        }

        PipelineEvents::as('Scheduler', 'scheduler', fn () => $this->run($id, false, 'Scheduler'));
    }

    /** Staff: send this row now (same checks, but the step switch doesn't stop it). */
    public function sendNow(int $id, string $by): array
    {
        if (! $this->claim($id, null)) {
            return ['status' => 'skipped', 'reason' => 'it is no longer pending'];
        }

        return $this->run($id, true, $by);
    }

    /** pending → sending, atomically, so a row is never sent twice. */
    private function claim(int $id, ?string $dueAt): bool
    {
        return DB::table('txn_scheduled_messages')
            ->where('id', $id)
            ->where('status', 'pending')
            ->when($dueAt !== null, fn ($q) => $q->where('due_at', $dueAt))
            ->update(['status' => 'sending', 'updated_at' => now()]) === 1;
    }

    private function run(int $id, bool $manual, string $by): array
    {
        $row = DB::table('txn_scheduled_messages')->find($id);

        try {
            $result = app(FollowUpService::class)->runStep($row, $manual);
        } catch (Throwable $exception) {
            Log::error('Scheduled message failed.', ['id' => $id, 'error' => $exception->getMessage()]);
            $result = ['status' => 'failed', 'reason' => $exception->getMessage()];
        }

        if ($result['status'] === 'retry') {
            // Cal.com couldn't be asked whether the customer booked: try again in 15 minutes.
            $due = CarbonImmutable::now('UTC')->addMinutes(15)->toDateTimeString();
            DB::table('txn_scheduled_messages')->where('id', $id)->update(['status' => 'pending', 'due_at' => $due, 'reason' => $result['reason'], 'updated_at' => now()]);
            $this->dispatch(DB::table('txn_scheduled_messages')->find($id));

            return $result;
        }

        DB::table('txn_scheduled_messages')->where('id', $id)->update([
            'status' => $result['status'],
            'reason' => mb_substr((string) $result['reason'], 0, 500),
            'done_at' => now(),
            'done_by' => mb_substr($by, 0, 120),
            'updated_at' => now(),
        ]);

        if ($result['status'] === 'sent') {
            $late = CarbonImmutable::parse($row->due_at, 'UTC')->diffInMinutes(now(), false);

            if (! $manual && $late >= 5) {
                PipelineEvents::record('message_late', ucfirst($this->describe($row)).' went out '.(int) $late.' min late (the worker was busy or down)', ['scheduled_id' => $id], $row->lead_id, $row->person_id);
            }
        } else {
            PipelineEvents::record($result['status'] === 'failed' ? 'automation_failed' : 'message_cancelled',
                ucfirst($this->describe($row)).' '.($result['status'] === 'failed' ? 'failed' : 'cancelled').": {$result['reason']}",
                ['scheduled_id' => $id, 'kind' => $row->kind], $row->lead_id, $row->person_id);
        }

        return $result;
    }

    /** Staff: cancel one row. */
    public function cancelOne(int $id, string $by): bool
    {
        return $this->cancelRows(DB::table('txn_scheduled_messages')->where('id', $id), "Cancelled by {$by}", $by) > 0;
    }

    /** Staff: move one row to another time (in the company time zone). */
    public function changeTime(int $id, CarbonImmutable $when, string $by): bool
    {
        $row = DB::table('txn_scheduled_messages')->where('id', $id)->where('status', 'pending')->first();

        if (! $row) {
            return false;
        }

        $due = $when->utc()->toDateTimeString();
        DB::table('txn_scheduled_messages')->where('id', $id)->update(['due_at' => $due, 'reason' => null, 'updated_at' => now()]);
        $this->dispatch(DB::table('txn_scheduled_messages')->find($id));

        PipelineEvents::record('message_rescheduled', ucfirst($this->describe($row)).' moved from '.$this->when($row->due_at).' to '.$this->when($due)." by {$by}", [
            'scheduled_id' => $id, 'old_due_at' => $row->due_at, 'due_at' => $due,
        ], $row->lead_id, $row->person_id);

        return true;
    }

    public function cancelForFollowUps(array $followUpIds, string $reason): int
    {
        return $followUpIds ? $this->cancelRows(DB::table('txn_scheduled_messages')->whereIn('follow_up_id', $followUpIds), $reason) : 0;
    }

    public function cancelForLead(int $leadId, string $reason): int
    {
        return $this->cancelRows(DB::table('txn_scheduled_messages')->where('lead_id', $leadId), $reason);
    }

    /**
     * The customer booked: cancel the link's lead's rows (or all of the
     * contact's when the link had no lead) and stop those follow-ups.
     */
    public function customerBooked(int $personId, ?int $leadId, string $what): void
    {
        if (! self::ready()) {
            return;
        }

        $query = DB::table('txn_scheduled_messages')->where('person_id', $personId)
            ->when($leadId, fn ($q) => $q->where(fn ($w) => $w->where('lead_id', $leadId)->orWhereNull('lead_id')));

        $followUps = (clone $query)->where('status', 'pending')->pluck('follow_up_id')->unique()->all();
        $this->cancelRows($query, "Customer booked ({$what})");

        if ($followUps) {
            DB::table('txn_follow_ups')->whereIn('id', $followUps)->whereNull('stopped_at')
                ->update(['stopped_at' => now(), 'stop_reason' => 'Customer booked', 'updated_at' => now()]);
        }
    }

    /** Pending rows of $query → cancelled with $reason, one event each. */
    private function cancelRows($query, string $reason, ?string $by = null): int
    {
        if (! self::ready()) {
            return 0;
        }

        $rows = (clone $query)->where('status', 'pending')->get();

        foreach ($rows as $row) {
            $changed = DB::table('txn_scheduled_messages')->where('id', $row->id)->where('status', 'pending')->update([
                'status' => 'cancelled', 'reason' => mb_substr($reason, 0, 500), 'done_at' => now(), 'done_by' => $by, 'updated_at' => now(),
            ]);

            if ($changed) {
                PipelineEvents::record('message_cancelled', ucfirst($this->describe($row))." (was due {$this->when($row->due_at)}) cancelled: {$reason}", [
                    'scheduled_id' => $row->id, 'kind' => $row->kind,
                ], $row->lead_id, $row->person_id);
            }
        }

        return $rows->count();
    }

    /**
     * After Automation or Follow-ups settings are saved: a step switched off
     * cancels its pending rows; switched on, schedules it for open follow-ups
     * (only times still ahead); changed hours re-time the pending rows.
     */
    public function syncWithSettings(): void
    {
        if (! self::ready()) {
            return;
        }

        $done = ['reminder' => 'reminder_sent_at', 'final' => 'final_sent_at', 'lost' => 'lost_at'];

        foreach (self::KINDS as $kind => [$label, , $rule]) {
            if (! Automation::rule($rule)) {
                $this->cancelRows(DB::table('txn_scheduled_messages')->where('kind', $kind), "{$label} switched off (Settings > TXN Settings > Automation)");

                continue;
            }

            // Re-time pending rows to the current hours.
            foreach (DB::table('txn_scheduled_messages')->where('kind', $kind)->where('status', 'pending')->get() as $row) {
                $followUp = DB::table('txn_follow_ups')->find($row->follow_up_id);
                $due = $followUp ? $this->dueFor($followUp, $kind)->utc()->toDateTimeString() : null;

                if ($due && $due !== $row->due_at) {
                    CarbonImmutable::parse($due, 'UTC')->isPast()
                        ? $this->cancelRows(DB::table('txn_scheduled_messages')->where('id', $row->id), 'The new follow-up hours put it in the past')
                        : $this->changeTime((int) $row->id, CarbonImmutable::parse($due, 'UTC'), 'the new follow-up hours');
                }
            }

            // Open follow-ups without this step yet.
            $open = DB::table('txn_follow_ups as f')
                ->whereNull('f.stopped_at')->whereNull('f.lost_at')->whereNull("f.{$done[$kind]}")
                ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('txn_scheduled_messages as s')
                    ->whereColumn('s.follow_up_id', 'f.id')->where('s.kind', $kind)->whereIn('s.status', ['pending', 'sending', 'sent']))
                ->get('f.*');

            foreach ($open as $followUp) {
                $this->create($followUp, $kind);
            }
        }
    }

    /** Re-queues a job for every pending row (after a restore, or if a job was lost). Returns how many. */
    public function repair(): int
    {
        if (! self::ready()) {
            return 0;
        }

        // A row stuck in "sending" (worker died mid-send) goes back to pending.
        DB::table('txn_scheduled_messages')->where('status', 'sending')->where('updated_at', '<', now()->subMinutes(10))
            ->update(['status' => 'pending', 'updated_at' => now()]);

        $rows = DB::table('txn_scheduled_messages')->where('status', 'pending')->get();

        foreach ($rows as $row) {
            $this->dispatch($row);
        }

        return $rows->count();
    }

    /** "the reminder to Jane Doe (email + text)" */
    public function describe(object $row): string
    {
        $name = DB::table('persons')->where('id', $row->person_id)->value('name') ?: 'the customer';
        $sms = fn (string $rule) => Automation::rule($rule) ? ' (email + text)' : ' (email)';

        return match ($row->kind) {
            'reminder' => "the reminder to {$name}".$sms('sms.reminder'),
            'final' => "the final email to {$name}".$sms('sms.final'),
            'lost' => "marking {$name}'s lead Lost",
            default => "{$row->kind} for {$name}",
        };
    }

    /** UTC "2026-11-21 16:14:00" → "Thu Nov 21, 10:14 AM" (company time zone). */
    public function when(string $utc): string
    {
        return CarbonImmutable::parse($utc, 'UTC')->setTimezone(config('slot_finder.timezone'))->format('D M j, g:i A');
    }
}
