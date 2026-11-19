<?php

namespace Webkul\Admin\Support\Contact;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Throwable;

/**
 * Test buttons on Settings > TXN Settings > Connections. Each test uses the
 * values on the form (saved or not) and only checks that the login works:
 * no SMS, no email, no booking. Geocodio's test uses 1 lookup credit.
 * Messages never contain a key, token or password.
 */
final class TxnConnectionTester
{
    public const TESTS = ['calcom', 'twilio', 'smtp', 'graph', 'geocodio', 'lead_db'];

    /** @var array<string, mixed> config path => value to test with */
    private array $values;

    public function __construct(array $values)
    {
        $this->values = $values;
    }

    /** @return array{ok: bool, message: string} */
    public function run(string $test): array
    {
        try {
            [$ok, $message] = match ($test) {
                'calcom' => $this->calcom(),
                'twilio' => $this->twilio(),
                'smtp' => $this->smtp(),
                'graph' => $this->graph(),
                'geocodio' => $this->geocodio(),
                'lead_db' => $this->leadDb(),
            };
        } catch (Throwable $exception) {
            [$ok, $message] = [false, 'Could not connect ('.class_basename($exception).'). Check the host, network and values.'];
        }

        return ['ok' => $ok, 'message' => $this->scrub($message)];
    }

    private function value(string $key): string
    {
        return trim((string) ($this->values[$key] ?? ''));
    }

    /** Removes any secret that a remote error message might echo. */
    private function scrub(string $message): string
    {
        foreach (TxnSettings::fields() as $key => $field) {
            $secret = $this->value($key);

            if ($field[1] === 'secret' && mb_strlen($secret) >= 4) {
                $message = str_replace($secret, '***', $message);
            }
        }

        return mb_substr($message, 0, 300);
    }

    private function missing(array $keys): ?array
    {
        $labels = array_map(fn ($key) => TxnSettings::fields()[$key][2], array_filter($keys, fn ($key) => $this->value($key) === ''));

        return $labels ? [false, 'Missing: '.implode(', ', $labels).'.'] : null;
    }

    private function calcom(): array
    {
        if ($missing = $this->missing(['calcom.api_key'])) {
            return $missing;
        }

        $response = Http::withToken($this->value('calcom.api_key'))->acceptJson()->timeout(10)->get('https://api.cal.com/v2/me');

        if (! $response->successful()) {
            return [false, "Cal.com refused the key (HTTP {$response->status()})."];
        }

        $who = $response->json('data.email') ?? $response->json('data.username') ?? 'the account';

        return [true, "Connected to Cal.com as {$who}."];
    }

    private function twilio(): array
    {
        if ($missing = $this->missing(['sms.account_sid', 'sms.auth_token'])) {
            return $missing;
        }

        [$sid, $token] = [$this->value('sms.account_sid'), $this->value('sms.auth_token')];
        $http = fn () => Http::withBasicAuth($sid, $token)->acceptJson()->timeout(10);

        $account = $http()->get("https://api.twilio.com/2010-04-01/Accounts/{$sid}.json");

        if (! $account->successful()) {
            return [false, "Twilio refused the Account SID / Auth token (HTTP {$account->status()})."];
        }

        $text = 'Connected to Twilio: '.($account->json('friendly_name') ?? $sid)
            .' ('.ucfirst((string) $account->json('type')).', '.$account->json('status').').';

        if ($service = $this->value('sms.messaging_service_sid')) {
            $found = $http()->get("https://messaging.twilio.com/v1/Services/{$service}");

            return $found->successful()
                ? [true, $text.' Messaging Service "'.$found->json('friendly_name').'" found. No SMS was sent.']
                : [false, $text." But the Messaging Service {$service} was not found in this account."];
        }

        if ($from = $this->value('sms.from_number')) {
            $numbers = $http()->get("https://api.twilio.com/2010-04-01/Accounts/{$sid}/IncomingPhoneNumbers.json", ['PhoneNumber' => $from]);

            return $numbers->successful() && count((array) $numbers->json('incoming_phone_numbers')) > 0
                ? [true, $text." The number {$from} belongs to this account. No SMS was sent."]
                : [false, $text." But the number {$from} is not in this account."];
        }

        return [false, $text.' But there is no Messaging Service SID or From number, so texts can\'t be sent.'];
    }

    private function smtp(): array
    {
        if ($missing = $this->missing(['mail.mailers.smtp.host', 'mail.mailers.smtp.port', 'mail.mailers.smtp.username', 'mail.mailers.smtp.password'])) {
            return $missing;
        }

        $port = (int) $this->value('mail.mailers.smtp.port');
        $transport = new EsmtpTransport($this->value('mail.mailers.smtp.host'), $port, $port === 465 ? true : null);
        $transport->getStream()->setTimeout(10);
        $transport->setUsername($this->value('mail.mailers.smtp.username'));
        $transport->setPassword($this->value('mail.mailers.smtp.password'));

        try {
            $transport->start();
        } catch (Throwable $exception) {
            return [false, 'SMTP login failed: '.$exception->getMessage()];
        } finally {
            try {
                $transport->stop();
            } catch (Throwable) {
            }
        }

        return [true, 'Logged in to '.$this->value('mail.mailers.smtp.host').'. No email was sent.'];
    }

    private function graph(): array
    {
        if ($missing = $this->missing(['graph_mail.tenant_id', 'graph_mail.client_id', 'graph_mail.client_secret'])) {
            return $missing;
        }

        $response = Http::asForm()->timeout(10)->post(sprintf('https://login.microsoftonline.com/%s/oauth2/v2.0/token', rawurlencode($this->value('graph_mail.tenant_id'))), [
            'client_id' => $this->value('graph_mail.client_id'),
            'client_secret' => $this->value('graph_mail.client_secret'),
            'scope' => 'https://graph.microsoft.com/.default',
            'grant_type' => 'client_credentials',
        ]);

        if (! $response->successful() || ! $response->json('access_token')) {
            return [false, 'Microsoft 365 refused the app login: '.($response->json('error') ?? "HTTP {$response->status()}").'.'];
        }

        return [true, 'Microsoft 365 accepted the app login. No email was sent (the mailbox permission is checked on the first real email).'];
    }

    private function geocodio(): array
    {
        if ($missing = $this->missing(['slot_finder.geocodio_api_key'])) {
            return $missing;
        }

        $place = (string) (config('slot_finder.service_area.address') ?: config('slot_finder.service_area.center_lat').','.config('slot_finder.service_area.center_lon'));
        $response = Http::timeout(10)->get('https://api.geocod.io/v1.7/geocode', ['q' => $place, 'limit' => 1, 'api_key' => $this->value('slot_finder.geocodio_api_key')]);

        if (! $response->successful()) {
            return [false, "Geocodio refused the key (HTTP {$response->status()})."];
        }

        return [true, 'Geocodio works: found "'.($response->json('results.0.formatted_address') ?? $place).'" (1 lookup used).'];
    }

    private function leadDb(): array
    {
        if ($missing = $this->missing(['lead_triage.lead_db.host', 'lead_triage.lead_db.port', 'lead_triage.lead_db.database', 'lead_triage.lead_db.username'])) {
            return $missing;
        }

        config(['database.connections.lead_db_test' => array_merge((array) config('lead_triage.lead_db'), [
            'host' => $this->value('lead_triage.lead_db.host'),
            'port' => $this->value('lead_triage.lead_db.port'),
            'database' => $this->value('lead_triage.lead_db.database'),
            'username' => $this->value('lead_triage.lead_db.username'),
            'password' => $this->value('lead_triage.lead_db.password'),
            'options' => [\PDO::ATTR_TIMEOUT => 5],
        ])]);

        try {
            DB::connection('lead_db_test')->select('select 1');
            $count = DB::connection('lead_db_test')->table('lead')->count();
        } catch (Throwable $exception) {
            return [false, 'Lead database: '.$exception->getMessage()];
        } finally {
            DB::purge('lead_db_test');
        }

        return [true, "Connected to the lead database: {$count} leads in the lead table."];
    }
}
