<?php

namespace Webkul\Admin\Support\Contact;

use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;
use Webkul\Lead\Models\Lead;

/**
 * Yelp chat messages, kept in the lead database next to `lead` (table
 * integration.yelp_message, docs/sql/yelp_message.sql). Zapier posts each
 * message to POST /txn/yelp-message (YelpMessageController), which saves it
 * here; the lead page card and Leads > Yelp messages read it back.
 *
 * Connection "msg_db": the lead database's host, port and name with its own
 * login (lead_triage.msg_db), which may only SELECT/INSERT/UPDATE this table.
 * A message belongs to a Krayin lead through the "[TXN-YELP-<id>]" title tag
 * (same Yelp lead id as yelp_message.source_lead_id and lead.source_lead_id).
 */
final class YelpMessageStore
{
    public const CONNECTION = 'msg_db';

    public const TABLE = 'yelp_message';

    /** The msg_db connection settings: lead database + the message login. */
    public static function connectionConfig(): array
    {
        return array_merge((array) config('lead_triage.lead_db'), [
            'username' => (string) config('lead_triage.msg_db.username'),
            'password' => (string) config('lead_triage.msg_db.password'),
            // Pages that show messages (and the unread badge) must not hang when it is down.
            'options' => [\PDO::ATTR_TIMEOUT => 3],
        ]);
    }

    private function table()
    {
        return DB::connection(self::CONNECTION)->table(self::TABLE);
    }

    /**
     * Saves one message from Zapier. Returns "stored" or "duplicate" (same
     * event_id already saved: Zapier retries and test steps are kept once).
     * Throws when the database can't be reached (the caller answers 503).
     *
     * @param  array{source_lead_id: string, event_id: string, user_type: ?string, user_name: ?string, text: ?string, attachment_url: ?string, attachment_text: ?string, time: ?string}  $message
     */
    public function store(array $message, array $raw): string
    {
        if ($this->table()->where('event_id', $message['event_id'])->exists()) {
            return 'duplicate';
        }

        // Our own reply typed in Krayin coming back from Yelp: the row Krayin
        // saved when sending gets Yelp's event id and time ("matched"), so the
        // chat shows it once, marked "Seen in Yelp".
        if (self::fromBusiness($message['user_type'] ?? null) && ($row = $this->pendingReply($message['source_lead_id'], $message['text'] ?? null))) {
            $this->table()->where('id', $row->id)->update([
                'event_id' => mb_substr($message['event_id'], 0, 255),
                'sent_at' => self::utc($message['time'] ?? null) ?? $row->sent_at,
                'raw_payload' => json_encode(array_merge(self::krayinPart($row), [
                    'status' => 'confirmed', 'confirmed_at' => now('UTC')->format('Y-m-d H:i:s'), 'yelp' => $raw,
                ]), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);

            return 'matched';
        }

        try {
            $this->table()->insert([
                'source_lead_id' => mb_substr($message['source_lead_id'], 0, 255),
                'event_id' => mb_substr($message['event_id'], 0, 255),
                'user_type' => self::cut($message['user_type'] ?? null, 32),
                'user_name' => self::cut($message['user_name'] ?? null, 255),
                'text' => self::cut($message['text'] ?? null, 65000),
                'attachment_url' => self::cut($message['attachment_url'] ?? null, 1024),
                'attachment_text' => self::cut($message['attachment_text'] ?? null, 65000),
                'sent_at' => self::utc($message['time'] ?? null),
                'raw_payload' => json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                // Set here (UTC), not left to the column default, whose time zone is the server's.
                'received_at' => now('UTC')->format('Y-m-d H:i:s'),
            ]);
        } catch (QueryException $exception) {
            // Two deliveries of the same message at the same moment.
            if (($exception->errorInfo[1] ?? null) === 1062) {
                return 'duplicate';
            }

            throw $exception;
        }

        return 'stored';
    }

    /**
     * A reply typed in Krayin (YelpReplySender), saved before it is handed to
     * Zapier: event id "krayin-<uuid>" until Yelp's copy comes back, business
     * sender, already read. raw_payload holds source "krayin", its status
     * (sending, handed, failed, confirmed) and who sent it. Returns the row id.
     */
    public function storeReply(string $sourceLeadId, string $text, string $requestId, array $krayin): int
    {
        $now = now('UTC')->format('Y-m-d H:i:s');

        return (int) $this->table()->insertGetId([
            'source_lead_id' => mb_substr($sourceLeadId, 0, 255),
            'event_id' => 'krayin-'.$requestId,
            'user_type' => 'BIZ',
            'user_name' => self::cut($krayin['user_name'] ?? null, 255),
            'text' => mb_substr($text, 0, 65000),
            'sent_at' => $now,
            'read_at' => $now,
            'raw_payload' => json_encode(['source' => 'krayin', 'request_id' => $requestId, 'status' => 'sending'] + $krayin, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'received_at' => $now,
        ]);
    }

    /** Merges $changes into a Krayin reply's raw_payload (its status, error). */
    public function updateReply(int $id, array $changes): void
    {
        $row = $this->table()->where('id', $id)->first();

        if ($row) {
            $this->table()->where('id', $id)->update([
                'raw_payload' => json_encode(array_merge(self::krayinPart($row), $changes), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        }
    }

    /**
     * The Krayin reply Yelp's copy belongs to: same chat, same text (spaces
     * ignored), sent from Krayin in the last 3 days, not matched yet. Oldest first.
     */
    private function pendingReply(string $sourceLeadId, ?string $text): ?object
    {
        $text = self::sameText($text);

        if ($text === '') {
            return null;
        }

        return $this->table()
            ->where('source_lead_id', $sourceLeadId)
            ->where('event_id', 'like', 'krayin-%')
            ->where('received_at', '>=', now('UTC')->subDays(3)->format('Y-m-d H:i:s'))
            ->orderBy('id')
            ->get()
            ->first(fn ($row) => self::sameText($row->text) === $text && (self::reply($row)['status'] ?? null) !== 'confirmed');
    }

    /**
     * For a message typed in Krayin: its raw_payload part (source "krayin",
     * status, user_name, error, ...); null for messages that came from Yelp.
     */
    public static function reply(object $row): ?array
    {
        $part = self::krayinPart($row);

        return ($part['source'] ?? null) === 'krayin' ? $part : null;
    }

    private static function krayinPart(object $row): array
    {
        $payload = json_decode((string) ($row->raw_payload ?? ''), true);

        return is_array($payload) ? $payload : [];
    }

    private static function sameText(?string $text): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', (string) $text)));
    }

    /** True when the message was written by the business (not the customer). */
    public static function fromBusiness(?string $userType): bool
    {
        return (bool) preg_match('/biz|business|owner|merchant/i', (string) $userType);
    }

    /** Messages of one Yelp lead, oldest first. */
    public function thread(string $sourceLeadId, int $limit = 300): Collection
    {
        return $this->table()
            ->where('source_lead_id', $sourceLeadId)
            ->orderByRaw('COALESCE(sent_at, received_at)')
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    /** Marks the customer's messages of one Yelp lead as read. */
    public function markRead(string $sourceLeadId): void
    {
        $this->table()->where('source_lead_id', $sourceLeadId)->whereNull('read_at')->update(['read_at' => now('UTC')]);
    }

    /**
     * One row per Yelp conversation, newest first: source_lead_id, last
     * message (text, sender, time), message count, unread customer messages.
     */
    public function conversations(int $limit = 200): Collection
    {
        // Two plain queries, no self-join (MySQL can't open a TEMPORARY table
        // twice in one query, which the check script uses).
        $groups = $this->table()
            ->selectRaw('source_lead_id, MAX(id) AS last_id, COUNT(*) AS messages')
            ->groupBy('source_lead_id')
            ->orderByDesc('last_id')
            ->limit($limit)
            ->get();

        $last = $this->table()
            ->whereIn('id', $groups->pluck('last_id'))
            ->get(['id', 'source_lead_id', 'text', 'attachment_url', 'user_type', 'user_name', 'sent_at', 'received_at'])
            ->keyBy('id');

        $rows = $groups
            ->filter(fn ($group) => isset($last[$group->last_id]))
            ->map(function ($group) use ($last) {
                $row = clone $last[$group->last_id];
                $row->messages = (int) $group->messages;

                return $row;
            })
            ->values();

        $unread = $this->table()
            ->whereIn('source_lead_id', $rows->pluck('source_lead_id'))
            ->whereNull('read_at')
            ->get(['source_lead_id', 'user_type'])
            ->reject(fn ($m) => self::fromBusiness($m->user_type))
            ->countBy('source_lead_id');

        return $rows->map(function ($row) use ($unread) {
            $row->unread = (int) ($unread[$row->source_lead_id] ?? 0);

            return $row;
        });
    }

    /** Unread customer messages in all conversations (for the button badge); 0 when unreachable. */
    public function unreadCount(): int
    {
        try {
            return $this->table()->whereNull('read_at')->get(['user_type'])
                ->reject(fn ($m) => self::fromBusiness($m->user_type))->count();
        } catch (Throwable) {
            return 0;
        }
    }

    /** Krayin leads for Yelp lead ids: source_lead_id => Lead (with person). */
    public static function leadsFor(iterable $sourceLeadIds): Collection
    {
        $ids = collect($sourceLeadIds)->map(fn ($id) => (string) $id)->filter()->unique()->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        $leads = Lead::query()->with('person')
            ->where(function ($query) use ($ids) {
                foreach ($ids as $id) {
                    $query->orWhere('title', 'like', '%[TXN-YELP-'.addcslashes($id, '%_\\').']%');
                }
            })
            ->get();

        return $ids->mapWithKeys(function ($id) use ($leads) {
            $lead = $leads->first(fn ($lead) => str_contains((string) $lead->title, '[TXN-YELP-'.$id.']'));

            return $lead ? [$id => $lead] : [];
        });
    }

    /** The Yelp lead id in a Krayin lead's title ("[TXN-YELP-abc]" -> "abc"), or null. */
    public static function sourceLeadId(mixed $lead): ?string
    {
        return preg_match('/\[TXN-YELP-([^\]\s]+)\]/', (string) ($lead->title ?? ''), $match) ? $match[1] : null;
    }

    /** A stored UTC time (sent_at / received_at) in the company time zone. */
    public static function localTime(?string $utc): ?CarbonImmutable
    {
        return $utc ? CarbonImmutable::parse($utc, 'UTC')->setTimezone(config('slot_finder.timezone')) : null;
    }

    private static function cut(?string $value, int $max): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    /** Zapier's time ("2026-11-22T10:04:37+00:00", unix seconds, ...) as UTC, or null. */
    private static function utc(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        try {
            $time = ctype_digit($value) ? CarbonImmutable::createFromTimestamp((int) (strlen($value) > 11 ? intdiv((int) $value, 1000) : $value)) : CarbonImmutable::parse($value);

            return $time->setTimezone('UTC')->format('Y-m-d H:i:s');
        } catch (Throwable) {
            return null;
        }
    }
}
