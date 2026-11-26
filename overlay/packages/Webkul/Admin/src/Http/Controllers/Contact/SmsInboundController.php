<?php

namespace Webkul\Admin\Http\Controllers\Contact;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Throwable;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Admin\Support\Contact\PipelineEvents;
use Webkul\Admin\Support\Replies\SmsInbox;

/**
 * POST /txn/sms-inbound (AppServiceProvider; "api" group, no session).
 * Twilio posts every text sent to our number here, once its number's
 * "A message comes in" webhook points at this address.
 *
 * - Off (Settings > TXN Settings > Automation > Incoming texts): 404.
 * - X-Twilio-Signature must match (HMAC-SHA1 of the public address + the
 *   sorted fields, keyed with Krayin's Twilio auth token): else 403.
 * - Saved once per MessageSid (SmsInbox). Never replies: the answer is
 *   an empty TwiML <Response/>. Database problem: 500, so Twilio retries
 *   and shows the error in its log.
 */
class SmsInboundController extends Controller
{
    private const EMPTY_TWIML = '<?xml version="1.0" encoding="UTF-8"?><Response></Response>';

    public function __invoke(Request $request, SmsInbox $inbox): Response
    {
        if (! config('automation.sms_intake.enabled')) {
            return response('not found', 404);
        }

        if (! self::validSignature($request)) {
            Log::warning('Incoming text refused: bad or missing X-Twilio-Signature.', ['from' => $request->input('From')]);

            return response('forbidden', 403);
        }

        try {
            $result = PipelineEvents::as('Twilio', 'webhook', fn () => $inbox->storeIncoming($request->post()));
        } catch (Throwable $exception) {
            Log::error('Incoming text not saved.', ['sid' => $request->input('MessageSid'), 'error' => $exception->getMessage()]);

            return response('could not save, try again', 500);
        }

        if ($result['status'] === 'invalid') {
            Log::warning('Incoming text without MessageSid or a usable From number.', ['from' => $request->input('From')]);
        }

        return response(self::EMPTY_TWIML, 200)->header('Content-Type', 'text/xml');
    }

    /**
     * Twilio's request signature: base64(HMAC-SHA1(auth token, URL + each
     * POST field name and value, sorted by name)). The URL is the public
     * address Twilio called (Automation > Incoming texts), because behind a
     * proxy Krayin sees a different one.
     */
    public static function validSignature(Request $request): bool
    {
        $token = (string) config('sms.auth_token');
        $given = (string) $request->header('X-Twilio-Signature');

        if ($token === '' || $token === 'CHANGE_ME' || $given === '') {
            return false;
        }

        return hash_equals(self::signature(self::publicUrl($request), $request->post(), $token), $given);
    }

    public static function signature(string $url, array $fields, string $token): string
    {
        ksort($fields, SORT_STRING);
        $data = $url;

        foreach ($fields as $name => $value) {
            $data .= $name.(is_array($value) ? implode('', $value) : (string) $value);
        }

        return base64_encode(hash_hmac('sha1', $data, $token, true));
    }

    /** The address Twilio was given: the configured public one, else this request's. */
    public static function publicUrl(?Request $request = null): string
    {
        $configured = trim((string) config('automation.sms_intake.public_url'));

        if ($configured !== '') {
            return $configured;
        }

        return $request ? $request->fullUrl() : route('txn.sms_inbound');
    }
}
