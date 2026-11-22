<?php

namespace Webkul\Admin\Support\Contact;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * A reply typed in Krayin to a Yelp chat (Leads > Yelp messages, the Yelp
 * card on a lead). Krayin can't write in Yelp itself (Yelp's API is for
 * approved partners only), so the reply goes to a second Zap:
 *   Webhooks by Zapier > Catch Hook (automation.yelp.reply_hook)
 *   -> Yelp Leads send-message action (lead_id, text).
 *
 * Order: switch (automation.yelp.reply_enabled) -> Yelp reply guard
 * (txn.guard_yelp: only the chats in automation.yelp.test_lead_ids) -> hook
 * address -> the reply is saved in yelp_message (status "sending") -> posted
 * to the hook -> status "handed" (Zapier took it) or "failed" (with the
 * error). When the New Message Zap brings Yelp's copy back,
 * YelpMessageStore::store() marks it "confirmed" (Seen in Yelp).
 */
final class YelpReplySender
{
    public const MAX_LENGTH = 5000;

    public function __construct(private YelpMessageStore $store) {}

    /**
     * @return array{status: string, message: string, id?: int}
     *   status: sent | off | blocked | no_hook | invalid | failed
     */
    public function send(string $sourceLeadId, string $text, ?object $user = null): array
    {
        $text = trim(str_replace("\r\n", "\n", $text));
        $sourceLeadId = trim($sourceLeadId);
        $lead = YelpMessageStore::leadsFor([$sourceLeadId])->first();
        $who = trim((string) ($user->name ?? '')) ?: 'Krayin';

        if ($sourceLeadId === '' || $text === '') {
            return ['status' => 'invalid', 'message' => 'Type a message first.'];
        }

        if (mb_strlen($text) > self::MAX_LENGTH) {
            return ['status' => 'invalid', 'message' => 'The message is too long (at most '.self::MAX_LENGTH.' characters).'];
        }

        if (! config('automation.yelp.reply_enabled')) {
            return ['status' => 'off', 'message' => 'Yelp replies from Krayin are off (Settings > TXN Settings > Automation > Yelp messages).'];
        }

        if (! self::allowed($sourceLeadId)) {
            PipelineEvents::record('yelp_reply_blocked', "Reply to Yelp chat {$sourceLeadId} not sent: the Yelp reply guard is on and this chat is not an allowed test chat.",
                ['source_lead_id' => $sourceLeadId], $lead);

            return ['status' => 'blocked', 'message' => "Not sent: the Yelp reply guard is on. To test with this chat, add {$sourceLeadId} to Settings > TXN Settings > Safety > Yelp replies."];
        }

        $hook = trim((string) config('automation.yelp.reply_hook'));

        if (! str_starts_with($hook, 'https://')) {
            return ['status' => 'no_hook', 'message' => 'Not sent: the reply Zap address is not set (Settings > TXN Settings > Automation > Yelp messages).'];
        }

        if (! ServiceHealth::hasKey('msg_db')) {
            return ['status' => 'failed', 'message' => 'Not sent: the Yelp messages database is not set up (Connections > Yelp messages database).'];
        }

        $requestId = (string) Str::uuid();
        $sentAt = now('UTC');

        try {
            $id = $this->store->storeReply($sourceLeadId, $text, $requestId, ['user_id' => $user->id ?? null, 'user_name' => $who]);
        } catch (Throwable $exception) {
            ServiceHealth::failed('msg_db', 'Krayin could not save a Yelp reply: '.$exception->getMessage());

            return ['status' => 'failed', 'message' => 'Not sent: Krayin could not save the reply in the Yelp messages database.'];
        }

        try {
            $response = Http::timeout(20)->acceptJson()->asJson()->post($hook, [
                'lead_id' => $sourceLeadId,
                'text' => $text,
                'request_id' => $requestId,
                'sent_by' => $who,
                'sent_at' => $sentAt->toIso8601ZuluString(),
            ]);
            $error = $response->successful() ? null : 'Zapier answered HTTP '.$response->status().($response->body() !== '' ? ': '.mb_strimwidth($response->body(), 0, 200, '…') : '');
        } catch (Throwable $exception) {
            $error = 'Zapier could not be reached: '.$exception->getMessage();
        }

        $preview = mb_strimwidth($text, 0, 200, '…');

        if ($error) {
            $this->store->updateReply($id, ['status' => 'failed', 'error' => $error, 'failed_at' => now('UTC')->format('Y-m-d H:i:s')]);
            PipelineEvents::record('yelp_reply_failed', "Reply to Yelp not sent ({$error}): {$preview}", ['source_lead_id' => $sourceLeadId, 'request_id' => $requestId, 'error' => $error], $lead);

            return ['status' => 'failed', 'message' => "Not sent: {$error}", 'id' => $id];
        }

        $this->store->updateReply($id, ['status' => 'handed', 'handed_at' => now('UTC')->format('Y-m-d H:i:s')]);
        PipelineEvents::record('yelp_reply_sent', "{$who} replied on Yelp from Krayin: {$preview}", ['source_lead_id' => $sourceLeadId, 'request_id' => $requestId], $lead);

        return ['status' => 'sent', 'message' => 'Sent to Zapier. It shows as "Seen in Yelp" once Yelp has it (a few minutes).', 'id' => $id];
    }

    /** Whether a chat may get a reply now: guard off, or an allowed test chat. */
    public static function allowed(string $sourceLeadId): bool
    {
        if (! config('txn.guard_yelp', true)) {
            return true;
        }

        return in_array($sourceLeadId, array_map('strval', (array) config('automation.yelp.test_lead_ids', [])), true);
    }
}
