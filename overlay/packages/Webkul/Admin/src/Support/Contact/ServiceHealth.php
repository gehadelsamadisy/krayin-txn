<?php

namespace Webkul\Admin\Support\Contact;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Whether the services Krayin uses (Twilio, Cal.com, email, Geocodio, the lead
 * database) work, as seen by Krayin's own calls to them. Shown as a bar on
 * every admin page (layouts/index) and on Settings > TXN Settings > Connections.
 *
 *  - failed():  a call came back with a login/connection error (wrong key,
 *               "invalid username", can't connect). Red. The full error is kept.
 *  - noKey():   something tried to use the service but it has no key. Amber.
 *  - ok():      a call worked, a Test passed or a new key was saved: cleared.
 *
 * Errors about one customer (a bad phone number, an address not on the map)
 * are not recorded. A service nobody uses gives no warning. Recording never
 * breaks the call it is about: database problems here are ignored.
 */
final class ServiceHealth
{
    /** Service => [label, Connections card id]. */
    public const SERVICES = [
        'twilio' => ['Twilio', 'twilio'],
        'calcom' => ['Cal.com', 'calcom'],
        'mail' => ['Email sending', 'email'],
        'geocodio' => ['Geocodio', 'geocodio'],
        'lead_db' => ['Lead database', 'lead_db'],
        'msg_db' => ['Yelp messages database', 'msg_db'],
    ];

    private const TABLE = 'txn_service_status';

    public static function label(string $service): string
    {
        return self::SERVICES[$service][0] ?? $service;
    }

    /** Message shown when a service is used without a key. */
    public static function noKeyMessage(string $service): string
    {
        return self::label($service).' has no key assigned. Add it in Settings > TXN Settings > Connections.';
    }

    /**
     * Whether the service has what it needs to be called. Empty values and the
     * CHANGE_ME placeholder count as missing.
     */
    public static function hasKey(string $service): bool
    {
        $set = fn (string $key) => ($value = trim((string) config($key))) !== '' && strcasecmp($value, 'CHANGE_ME') !== 0;

        return match ($service) {
            'twilio' => $set('sms.account_sid') && $set('sms.auth_token') && ($set('sms.messaging_service_sid') || $set('sms.from_number')),
            'calcom' => $set('calcom.api_key'),
            'geocodio' => $set('slot_finder.geocodio_api_key'),
            'mail' => match ((string) config('mail.default')) {
                'smtp' => $set('mail.mailers.smtp.host') && $set('mail.mailers.smtp.username') && $set('mail.mailers.smtp.password'),
                'graph' => $set('graph_mail.tenant_id') && $set('graph_mail.client_id') && $set('graph_mail.client_secret'),
                default => true, // array/log (tests) or a mailer that needs no key
            },
            'lead_db' => $set('lead_triage.lead_db.host') && $set('lead_triage.lead_db.database') && $set('lead_triage.lead_db.username') && $set('lead_triage.lead_db.password'),
            'msg_db' => $set('lead_triage.lead_db.host') && $set('lead_triage.lead_db.database') && $set('lead_triage.msg_db.username') && $set('lead_triage.msg_db.password'),
            default => true,
        };
    }

    /** Records "used without a key" and returns the message to show the user. */
    public static function noKey(string $service): string
    {
        $message = self::noKeyMessage($service);
        self::write($service, 'no_key', $message);

        return $message;
    }

    /** Records a login/connection failure with the service's full error message. */
    public static function failed(string $service, string $message): void
    {
        self::write($service, 'failed', $message);
    }

    /** Clears the service: it works (or has a new key to try). */
    public static function ok(string $service): void
    {
        try {
            if (Schema::hasTable(self::TABLE)) {
                DB::table(self::TABLE)->where('service', $service)->delete();
            }
        } catch (Throwable) {
            // never break the call this is about
        }
    }

    /** A response that means the key or login is wrong (not a problem with one customer). */
    public static function isAuthFailure(Response $response): bool
    {
        return in_array($response->status(), [401, 403], true) || (int) $response->json('code') === 20003;
    }

    /**
     * Current problems, oldest first: list of [service, label, card, state
     * (failed|no_key), message, failed_at].
     */
    public static function problems(): array
    {
        try {
            if (! Schema::hasTable(self::TABLE)) {
                return [];
            }

            return DB::table(self::TABLE)->orderBy('failed_at')->get()
                ->filter(fn ($row) => isset(self::SERVICES[$row->service]))
                ->map(fn ($row) => [
                    'service' => $row->service,
                    'label' => self::label($row->service),
                    'card' => self::SERVICES[$row->service][1],
                    'state' => $row->state,
                    'message' => (string) $row->message,
                    'failed_at' => $row->failed_at,
                ])
                ->values()
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    /** The service a Connections setting belongs to (null = none, e.g. the webhook token). */
    public static function serviceForKey(string $key): ?string
    {
        return match (true) {
            str_starts_with($key, 'calcom.') => 'calcom',
            str_starts_with($key, 'sms.') => 'twilio',
            str_starts_with($key, 'mail.'), str_starts_with($key, 'graph_mail.') => 'mail',
            $key === 'slot_finder.geocodio_api_key' => 'geocodio',
            str_starts_with($key, 'lead_triage.lead_db.') => 'lead_db',
            str_starts_with($key, 'lead_triage.msg_db.') => 'msg_db',
            default => null,
        };
    }

    /** The service a Connections test belongs to (smtp/graph are both email). */
    public static function serviceForTest(string $test): ?string
    {
        return match ($test) {
            'smtp', 'graph' => 'mail',
            'calcom', 'twilio', 'geocodio', 'lead_db', 'msg_db' => $test,
            default => null,
        };
    }

    private static function write(string $service, string $state, string $message): void
    {
        try {
            if (! Schema::hasTable(self::TABLE)) {
                return;
            }

            DB::table(self::TABLE)->updateOrInsert(
                ['service' => $service],
                ['state' => $state, 'message' => $message, 'failed_at' => now(), 'updated_at' => now()]
            );
        } catch (Throwable) {
            // never break the call this is about
        }
    }
}
