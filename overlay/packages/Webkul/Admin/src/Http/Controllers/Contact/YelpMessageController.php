<?php

namespace Webkul\Admin\Http\Controllers\Contact;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Admin\Support\Contact\PipelineEvents;
use Webkul\Admin\Support\Contact\ServiceHealth;
use Webkul\Admin\Support\Contact\YelpMessageStore;

/**
 * POST /txn/yelp-message (AppServiceProvider; "api" group, no session).
 * A Zap (Yelp "New Message" -> Webhooks POST) sends every Yelp chat message,
 * the customer's and ours, as JSON:
 *
 *   lead_id, event_id, user_type, user, text, time, attachment_url, attachment_text
 *
 * - Off (Settings > TXN Settings > Automation > Yelp messages): 404.
 * - Header X-TXN-Token must equal automation.yelp.token. Wrong/missing: 401.
 * - lead_id and event_id required, and text or an attachment: else 422.
 * - Saved in integration.yelp_message (YelpMessageStore); the same event_id
 *   again is kept once ("duplicate"). Database unreachable: 503, so the Zap
 *   run shows as Errored and can be sent again.
 */
class YelpMessageController extends Controller
{
    /** Field => the names a Zap may use for it (first one wins). */
    private const FIELDS = [
        'source_lead_id' => ['lead_id', 'source_lead_id', 'yelp_lead_id'],
        'event_id' => ['event_id', 'message_id', 'id'],
        'user_type' => ['user_type', 'sender_type'],
        'user_name' => ['user', 'user_name', 'user_display_name', 'sender'],
        'text' => ['text', 'message', 'message_text'],
        'time' => ['time', 'time_created', 'sent_at', 'timestamp'],
        'attachment_url' => ['attachment_url', 'attachement_url', 'attachemetn_url'],
        'attachment_text' => ['attachment_text', 'attachement_text', 'attachemetn_text'],
    ];

    public function __invoke(Request $request, YelpMessageStore $store): JsonResponse
    {
        if (! config('automation.yelp.enabled')) {
            return response()->json(['message' => 'not found'], 404);
        }

        $token = (string) config('automation.yelp.token');

        if ($token === '' || ! hash_equals($token, (string) $request->header('X-TXN-Token'))) {
            Log::warning('Yelp message refused: bad or missing X-TXN-Token.');

            return response()->json(['message' => 'unauthorized'], 401);
        }

        $message = [];

        foreach (self::FIELDS as $field => $names) {
            $value = null;

            foreach ($names as $name) {
                $candidate = $request->input($name);

                if (is_scalar($candidate) && trim((string) $candidate) !== '') {
                    $value = trim((string) $candidate);
                    break;
                }
            }

            $message[$field] = $value;
        }

        $errors = array_filter([
            'lead_id' => $message['source_lead_id'] === null ? 'The Yelp lead id is missing.' : null,
            'event_id' => $message['event_id'] === null ? 'The message (event) id is missing.' : null,
            'text' => $message['text'] === null && $message['attachment_url'] === null ? 'The message has no text and no attachment.' : null,
        ]);

        if ($errors) {
            return response()->json(['message' => 'invalid', 'errors' => $errors], 422);
        }

        if (! ServiceHealth::hasKey('msg_db')) {
            ServiceHealth::noKey('msg_db');

            return response()->json(['message' => 'messages database not set up'], 503);
        }

        try {
            $result = $store->store($message, $request->all());
        } catch (Throwable $exception) {
            ServiceHealth::failed('msg_db', 'Krayin could not save a Yelp message: '.$exception->getMessage());
            Log::error('Yelp message not saved.', ['event_id' => $message['event_id'], 'error' => $exception->getMessage()]);

            return response()->json(['message' => 'could not save, try again'], 503);
        }

        ServiceHealth::ok('msg_db');

        if ($result === 'stored') {
            $lead = YelpMessageStore::leadsFor([$message['source_lead_id']])->first();
            $business = YelpMessageStore::fromBusiness($message['user_type']);
            $preview = $message['text'] ?? ('Attachment: '.($message['attachment_text'] ?? $message['attachment_url']));

            PipelineEvents::as('Yelp (Zapier)', 'webhook', fn () => PipelineEvents::record(
                $business ? 'yelp_message_sent' : 'yelp_message_received',
                ($business ? 'We wrote on Yelp: ' : ($message['user_name'] ?? 'Customer').' wrote on Yelp: ').mb_strimwidth($preview, 0, 200, '…'),
                ['source_lead_id' => $message['source_lead_id'], 'event_id' => $message['event_id'], 'matched' => (bool) $lead],
                $lead,
            ));
        }

        return response()->json(['message' => $result], 200);
    }
}
