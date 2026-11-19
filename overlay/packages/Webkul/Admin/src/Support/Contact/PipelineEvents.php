<?php

namespace Webkul\Admin\Support\Contact;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Pipeline event log (table txn_events; Leads > Event log, and the Events
 * card on each lead and contact). Every step writes one readable line, the
 * same whether staff clicked a button or automation did it:
 *
 *   PipelineEvents::record('link_sent', 'Sent the booking link to Jane Doe by email and SMS', [...], $lead, $person);
 *
 * Who did it: the logged-in staff member (source "button"), or the actor set
 * around a block with PipelineEvents::as('Cal.com webhook', 'webhook', fn () => ...).
 * Writing an event never breaks the step it describes: a failure is only logged.
 */
final class PipelineEvents
{
    /** event => [label, group] (filters and badges on the Event log page). */
    public const EVENTS = [
        'leads_imported' => ['Leads imported', 'leads'],
        'leads_checked' => ['Leads checked', 'leads'],
        'lead_checked' => ['Lead checked', 'leads'],
        'times_suggested' => ['Times suggested', 'links'],
        'link_created' => ['Link created', 'links'],
        'link_sent' => ['Link sent', 'links'],
        'reminder_sent' => ['Reminder sent', 'follow_ups'],
        'final_sent' => ['Final email sent', 'follow_ups'],
        'fresh_link_created' => ['Fresh link created', 'follow_ups'],
        'follow_up_stopped' => ['Follow-ups stopped', 'follow_ups'],
        'marked_lost' => ['Marked Lost', 'follow_ups'],
        'booking_received' => ['Booking received', 'bookings'],
        'booking_rescheduled' => ['Booking rescheduled', 'bookings'],
        'booking_cancelled' => ['Booking cancelled', 'bookings'],
        'bookings_synced' => ['Bookings synced', 'bookings'],
        'staff_notified' => ['Staff notified', 'automation'],
        'automation_skipped' => ['Automation skipped', 'automation'],
        'automation_blocked' => ['Blocked by a guard', 'automation'],
        'automation_failed' => ['Automation failed', 'automation'],
        'engine_switched' => ['Engine switched', 'automation'],
    ];

    public const GROUPS = [
        'leads' => 'Leads',
        'links' => 'Booking links',
        'follow_ups' => 'Follow-ups',
        'bookings' => 'Bookings',
        'automation' => 'Automation',
    ];

    /** Stack of [actor, source] set by as(). */
    private static array $context = [];

    /** Runs $callback with $actor / $source as "who did it" for every event inside. */
    public static function as(string $actor, string $source, callable $callback): mixed
    {
        self::$context[] = [$actor, $source];

        try {
            return $callback();
        } finally {
            array_pop(self::$context);
        }
    }

    /** @return array{0: string, 1: string, 2: ?int} [actor, source, user id] */
    public static function actor(): array
    {
        $userId = auth()->guard('user')->id();

        if (self::$context) {
            [$actor, $source] = end(self::$context);

            return [$actor, $source, $userId];
        }

        if ($userId) {
            return [(string) (auth()->guard('user')->user()?->name ?? 'Staff'), 'button', $userId];
        }

        return app()->runningInConsole() ? ['Command', 'command', null] : ['System', 'system', null];
    }

    /** True while automation (an engine) is the actor. */
    public static function isAutomation(): bool
    {
        return self::$context && in_array(end(self::$context)[1], ['automation', 'scheduler', 'webhook'], true);
    }

    /**
     * One event. $lead / $person: model, id or null.
     */
    public static function record(string $event, string $summary, array $data = [], mixed $lead = null, mixed $person = null): void
    {
        try {
            if (! self::ready()) {
                return;
            }

            [$actor, $source, $userId] = self::actor();
            $leadId = is_object($lead) ? $lead->id : (is_numeric($lead) ? (int) $lead : null);
            $personId = is_object($person) ? $person->id : (is_numeric($person) ? (int) $person : null);

            if (! $personId && is_object($lead) && isset($lead->person_id)) {
                $personId = (int) $lead->person_id;
            }

            DB::table('txn_events')->insert([
                'occurred_at' => now(),
                'event' => mb_substr($event, 0, 40),
                'actor' => mb_substr($actor, 0, 120),
                'user_id' => $userId,
                'source' => mb_substr($source, 0, 20),
                'lead_id' => $leadId,
                'person_id' => $personId,
                'summary' => mb_substr($summary, 0, 500),
                'data' => $data ? json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            ]);
        } catch (Throwable $exception) {
            Log::warning('Pipeline event not recorded.', ['event' => $event, 'error' => $exception->getMessage()]);
        }
    }

    /** "2026-11-20T09:00" (company time zone) -> "Wed Nov 20, 9:00 AM". */
    public static function slotLabel(string $value, ?string $timezone = null): string
    {
        try {
            return CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $value, $timezone ?? config('slot_finder.timezone'))->format('D M j, g:i A');
        } catch (Throwable) {
            return $value;
        }
    }

    /** [time => worker id] -> "Wed Nov 20, 9:00 AM (Sam), Wed Nov 20, 1:00 PM (Ana)". */
    public static function slotsText(array $slotWorkers, ?string $timezone = null): string
    {
        $names = DB::table('workers')->whereIn('id', array_filter(array_map('intval', $slotWorkers)))->pluck('name', 'id');
        ksort($slotWorkers);

        return collect($slotWorkers)
            ->map(fn ($workerId, $slot) => self::slotLabel((string) $slot, $timezone).($names[(int) $workerId] ?? null ? ' ('.$names[(int) $workerId].')' : ''))
            ->implode(', ');
    }

    /** The person's name for summaries. */
    public static function name(mixed $person): string
    {
        return is_object($person) && trim((string) $person->name) !== '' ? trim((string) $person->name) : 'the customer';
    }

    private static ?bool $ready = null;

    private static function ready(): bool
    {
        return self::$ready ??= Schema::hasTable('txn_events');
    }
}
