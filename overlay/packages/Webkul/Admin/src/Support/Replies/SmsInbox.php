<?php

namespace Webkul\Admin\Support\Replies;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;
use Webkul\Admin\Support\Contact\ContactDataNormalizer;
use Webkul\Admin\Support\Contact\PipelineEvents;

/**
 * Text messages with customers (table txn_sms_messages; Leads > SMS
 * messages). storeIncoming() keeps a text Twilio delivered (once per
 * MessageSid) and matches it: the sender's number -> the one contact with
 * that number -> their newest open lead. A number on several contacts is
 * marked "Needs staff"; an unknown number stays without a contact.
 * recordOutgoing() keeps the texts Krayin sends, so threads are complete.
 * Never sends anything.
 */
final class SmsInbox
{
    public static function ready(): bool
    {
        try {
            return Schema::hasTable('txn_sms_messages');
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * One incoming text (Twilio's fields: MessageSid, From, To, Body,
     * NumMedia, MediaUrl0, MediaContentType0, ...).
     *
     * @return array{status: string, id?: int, lead_id?: ?int, person_id?: ?int, needs_staff?: bool}
     *               status: stored, duplicate, invalid
     */
    public function storeIncoming(array $params, mixed $sentAt = null): array
    {
        $sid = trim((string) ($params['MessageSid'] ?? $params['SmsMessageSid'] ?? ''));
        $from = ContactDataNormalizer::phone($params['From'] ?? null);

        if ($sid === '' || $from === null) {
            return ['status' => 'invalid'];
        }

        if (DB::table('txn_sms_messages')->where('message_sid', $sid)->exists()) {
            return ['status' => 'duplicate'];
        }

        $media = [];

        for ($i = 0; $i < min(10, (int) ($params['NumMedia'] ?? 0)); $i++) {
            if (! empty($params["MediaUrl{$i}"])) {
                $media[] = ['url' => (string) $params["MediaUrl{$i}"], 'type' => (string) ($params["MediaContentType{$i}"] ?? '')];
            }
        }

        $people = self::personsWithNumber($from);
        $personId = count($people) === 1 ? $people[0] : null;
        $leadId = $personId ? EmailReplyImporter::openLeadOf($personId) : null;
        $needsStaff = count($people) > 1;
        $body = mb_substr((string) ($params['Body'] ?? ''), 0, 5000);

        $id = DB::table('txn_sms_messages')->insertGetId([
            'message_sid' => mb_substr($sid, 0, 64),
            'direction' => 'in',
            'customer_number' => $from,
            'our_number' => mb_substr((string) ($params['To'] ?? ''), 0, 40) ?: null,
            'body' => $body,
            'media' => $media ? json_encode($media) : null,
            'person_id' => $personId,
            'lead_id' => $leadId,
            'needs_staff' => $needsStaff,
            'sent_at' => $sentAt ?? now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $preview = $body !== '' ? mb_strimwidth($body, 0, 200, '…') : ($media ? 'Picture or file' : '(empty)');
        PipelineEvents::record(
            'sms_received',
            match (true) {
                $needsStaff => "Text from {$from} (number on ".count($people).' contacts: needs staff): '.$preview,
                ! $personId => "Text from {$from} (unknown number): {$preview}",
                default => "Text from {$from}: {$preview}",
            },
            ['message_sid' => $sid, 'from' => $from, 'needs_staff' => $needsStaff, 'contacts' => $people],
            $leadId,
            $personId
        );

        return ['status' => 'stored', 'id' => $id, 'lead_id' => $leadId, 'person_id' => $personId, 'needs_staff' => $needsStaff];
    }

    /** A text Krayin sent (SmsSender). Never fails the send. */
    public static function recordOutgoing(string $sid, string $to, ?string $from, string $body, ?int $personId, ?int $leadId, ?int $userId, mixed $sentAt = null): void
    {
        try {
            if (! self::ready() || $sid === '' || DB::table('txn_sms_messages')->where('message_sid', $sid)->exists()) {
                return;
            }

            DB::table('txn_sms_messages')->insert([
                'message_sid' => mb_substr($sid, 0, 64),
                'direction' => 'out',
                'customer_number' => ContactDataNormalizer::phone($to) ?? mb_substr($to, 0, 20),
                'our_number' => $from ? mb_substr($from, 0, 40) : null,
                'body' => mb_substr($body, 0, 5000),
                'person_id' => $personId,
                'lead_id' => $leadId,
                'sent_by' => $userId,
                'sent_at' => $sentAt ?? now(),
                'read_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (Throwable $exception) {
            \Illuminate\Support\Facades\Log::warning('SMS sent, but the copy for SMS messages could not be saved.', ['to' => $to, 'error' => $exception->getMessage()]);
        }
    }

    /**
     * IDs of the contacts that have this number (any written format).
     *
     * @return array<int, int>
     */
    public static function personsWithNumber(string $e164): array
    {
        $last = substr(preg_replace('/\D/', '', $e164), -7);

        // Rough filter in SQL (digits only), exact check in PHP.
        return DB::table('persons')
            ->whereRaw("REGEXP_REPLACE(COALESCE(contact_numbers, ''), '[^0-9]', '') LIKE ?", ['%'.$last.'%'])
            ->get(['id', 'contact_numbers'])
            ->filter(function ($person) use ($e164) {
                foreach ((array) json_decode((string) $person->contact_numbers, true) as $row) {
                    if (ContactDataNormalizer::phone(is_array($row) ? ($row['value'] ?? null) : $row) === $e164) {
                        return true;
                    }
                }

                return false;
            })
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /** One row per customer number, newest first: last text, unread count, contact, lead, needs staff. */
    public function conversations(): Collection
    {
        $last = DB::table('txn_sms_messages')
            ->selectRaw('customer_number, MAX(id) as last_id, SUM(CASE WHEN direction = ? AND read_at IS NULL THEN 1 ELSE 0 END) as unread, MAX(needs_staff) as needs_staff', ['in'])
            ->groupBy('customer_number');

        $rows = DB::table('txn_sms_messages as m')
            ->joinSub($last, 'c', 'c.last_id', '=', 'm.id')
            ->orderByDesc('m.id')
            ->limit(500)
            ->get(['m.*', 'c.unread', 'c.needs_staff as thread_needs_staff']);

        // Contact and lead of the thread: the latest message that has them.
        $links = DB::table('txn_sms_messages')
            ->whereIn('customer_number', $rows->pluck('customer_number'))
            ->whereNotNull('person_id')
            ->orderByDesc('id')
            ->get(['customer_number', 'person_id', 'lead_id'])
            ->unique('customer_number')
            ->keyBy('customer_number');

        $persons = DB::table('persons')->whereIn('id', $links->pluck('person_id'))->pluck('name', 'id');
        $leads = DB::table('leads')->whereIn('id', $links->pluck('lead_id')->filter())->get(['id', 'title'])->keyBy('id');

        return $rows->map(function ($row) use ($links, $persons, $leads) {
            $link = $links[$row->customer_number] ?? null;
            $row->person_id = $link?->person_id;
            $row->person_name = $link ? ($persons[$link->person_id] ?? null) : null;
            $row->lead = $link && $link->lead_id ? ($leads[$link->lead_id] ?? null) : null;
            $row->unread = (int) $row->unread;
            $row->needs_staff = (bool) $row->thread_needs_staff && ! $link;
            $row->name = $row->person_name ?: $row->customer_number;

            return $row;
        });
    }

    /** Every text with one number, oldest first. */
    public function thread(string $number): Collection
    {
        return DB::table('txn_sms_messages')->where('customer_number', $number)->orderBy('sent_at')->orderBy('id')->get();
    }

    public function markRead(string $number): void
    {
        DB::table('txn_sms_messages')->where('customer_number', $number)->where('direction', 'in')->whereNull('read_at')->update(['read_at' => now()]);
    }

    /** Media list of a row. */
    public static function media(object $row): array
    {
        return array_values(array_filter((array) json_decode((string) ($row->media ?? ''), true), fn ($m) => is_array($m) && ! empty($m['url'])));
    }
}
