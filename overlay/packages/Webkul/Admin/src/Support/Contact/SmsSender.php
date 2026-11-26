<?php

namespace Webkul\Admin\Support\Contact;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Webkul\Activity\Models\ActivityProxy;
use Webkul\Contact\Models\Person;

/**
 * Sends a text through Twilio (config/sms.php) and notes it on the contact
 * and lead. While testing, only numbers in SMS_ALLOWED_RECIPIENTS get a text;
 * anything else is blocked and logged, like the email guard.
 */
final class SmsSender
{
    private const API_URL = 'https://api.twilio.com/2010-04-01/Accounts/%s/Messages.json';

    /**
     * @return array{status: string, to: ?string, sid?: string, error?: string}
     *               status: sent, blocked (not an allowed test number), skipped (no usable number), failed
     */
    public function send(Person $person, string $body, $lead = null, ?string $number = null): array
    {
        // $number: reply to this exact number (Leads > SMS messages); else the contact's first one.
        $to = $number !== null ? ContactDataNormalizer::phone($number) : $this->numberFor($person);

        if ($to === null || ! str_starts_with($to, '+')) {
            return ['status' => 'skipped', 'to' => null, 'error' => 'No phone number in international format (+country code) on this contact.'];
        }

        if (! self::allowedNumber($to)) {
            Log::warning('SMS not sent: the number is not in SMS_ALLOWED_RECIPIENTS.', ['to' => $to, 'person_id' => $person->id]);

            return ['status' => 'blocked', 'to' => $to, 'error' => 'Not an allowed test number (SMS_ALLOWED_RECIPIENTS).'];
        }

        $sid = config('sms.account_sid');
        $token = config('sms.auth_token');
        $service = config('sms.messaging_service_sid');
        $from = config('sms.from_number');

        if (! ServiceHealth::hasKey('twilio')) {
            return ['status' => 'failed', 'to' => $to, 'error' => ServiceHealth::noKey('twilio')];
        }

        try {
            $response = Http::asForm()
                ->withBasicAuth($sid, $token)
                ->timeout(20)
                ->post(sprintf(self::API_URL, $sid), array_filter([
                    'To' => $to,
                    'Body' => $body,
                    'MessagingServiceSid' => $service ?: null,
                    'From' => $service ? null : $from,
                ]));
        } catch (ConnectionException $exception) {
            ServiceHealth::failed('twilio', 'Krayin could not connect to Twilio: '.$exception->getMessage());

            return ['status' => 'failed', 'to' => $to, 'error' => 'Krayin could not connect to Twilio.'];
        }

        if (! $response->successful()) {
            $error = $response->json('message') ?? "HTTP {$response->status()}";
            Log::error('Twilio rejected an SMS.', ['to' => $to, 'person_id' => $person->id, 'error' => $error, 'code' => $response->json('code')]);

            // A wrong account or token (not a problem with this one number).
            if (ServiceHealth::isAuthFailure($response)) {
                ServiceHealth::failed('twilio', "Twilio: {$error}".($response->json('code') ? ' (code '.$response->json('code').')' : ''));
            }

            return ['status' => 'failed', 'to' => $to, 'error' => "Twilio: {$error}"];
        }

        ServiceHealth::ok('twilio');

        $messageSid = (string) $response->json('sid');
        $this->note($person, $lead, $to, $body, $messageSid);

        // Copy for Leads > SMS messages, so the thread with the customer is complete.
        \Webkul\Admin\Support\Replies\SmsInbox::recordOutgoing($messageSid, $to, (string) ($response->json('from') ?: $from), $body, (int) $person->id, is_object($lead) ? (int) $lead->id : null, auth()->guard('user')->id());

        return ['status' => 'sent', 'to' => $to, 'sid' => $messageSid];
    }

    /** Whether the SMS guard lets a text go to this number (guard off, or an allowed test number). */
    public static function allowedNumber(?string $number): bool
    {
        $to = ContactDataNormalizer::phone($number);

        if ($to === null) {
            return false;
        }

        if (! config('txn.guard_sms', true)) {
            return true;
        }

        return in_array($to, array_filter(array_map([ContactDataNormalizer::class, 'phone'], config('sms.allowed_recipients', []))), true);
    }

    /** The contact's first number Twilio can dial: US numbers are +1…; others need their + country code. */
    public function numberFor(Person $person): ?string
    {
        foreach ($person->contact_numbers ?? [] as $row) {
            $number = ContactDataNormalizer::phone($row['value'] ?? null);

            if ($number !== null && str_starts_with($number, '+')) {
                return $number;
            }
        }

        return null;
    }

    /** "SMS sent" note on the contact and the lead, with the text and Twilio's message id. */
    private function note(Person $person, $lead, string $to, string $body, string $messageSid): void
    {
        try {
            $activity = ActivityProxy::modelClass()::create([
                'type' => 'note',
                'title' => "SMS sent to {$to}",
                'comment' => $body."\n\n(Twilio message {$messageSid})",
                'is_done' => 1,
                'user_id' => auth()->id() ?: config('calcom.activity_user_id', 1),
            ]);

            $person->activities()->syncWithoutDetaching([$activity->id]);
            $lead?->activities()->syncWithoutDetaching([$activity->id]);
        } catch (\Throwable $exception) {
            Log::warning('SMS sent, but the note could not be saved.', ['person_id' => $person->id, 'error' => $exception->getMessage()]);
        }
    }
}
