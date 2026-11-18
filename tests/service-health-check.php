<?php

/*
 * Service health check (Support\Contact\ServiceHealth):
 *  - Krayin works with no API keys at all (pages open);
 *  - using a service with no key shows "<service> has no key assigned" (amber bar);
 *  - a real login/connection failure shows "<service> isn't working" (red bar) on
 *    every page and highlights the service on Connections;
 *  - the next success, a passing Test or a new key clears it;
 *  - a problem with one customer (bad phone number, address not on the map) doesn't count.
 *
 * Run inside the Krayin container, after the migrations:
 *   php tests/service-health-check.php
 *
 * One database transaction, rolled back at the end; every outgoing HTTP call is
 * faked (nothing reaches Twilio or Geocodio); mail goes to the array mailer.
 * Exit code = number of failed checks.
 */

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Webkul\Admin\Support\Contact\LeadDbImportService;
use Webkul\Admin\Support\Contact\ServiceHealth;
use Webkul\Admin\Support\Contact\SlotSuggestionService;
use Webkul\Admin\Support\Contact\SmsSender;
use Webkul\Admin\Support\Contact\TxnSettings;
use Webkul\Contact\Repositories\PersonRepository;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->bootstrap();
$app->instance('request', Request::create('/admin'));

config(['mail.default' => 'array', 'queue.default' => 'sync', 'cache.default' => 'array']);

// What the fake services answer; changed by the checks below.
$twilioSend = Http::response(['sid' => 'SMcheck'], 201);
$geocodio = Http::response(['results' => [['location' => ['lat' => 29.76, 'lng' => -95.37], 'accuracy_type' => 'rooftop']]]);
Http::fake(function ($request) use (&$twilioSend, &$geocodio) {
    $url = $request->url();

    if (str_contains($url, 'twilio.com')) {
        // Test button reads the account/service (GET); sending a text is a POST.
        return $request->method() === 'GET'
            ? Http::response(['friendly_name' => 'Check Account', 'type' => 'Full', 'status' => 'active'])
            : $twilioSend;
    }

    if (str_contains($url, 'geocod.io')) {
        return $geocodio;
    }

    return Http::response([], 500);
});

$pass = new class { public function handle($request, $next) { return $next($request); } };
foreach ([
    Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class,
    Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
    App\Http\Middleware\VerifyCsrfToken::class,
] as $class) {
    $app->instance($class, $pass);
}

$call = function (string $method, string $uri, array $params = []) use ($kernel) {
    $request = Request::create($uri, $method, $params);
    app()->instance('request', $request);

    return $kernel->handle($request);
};

$results = [];
$section = '';
$check = function (string $name, bool $ok, string $detail = '') use (&$results, &$section) {
    $results[] = compact('section', 'name', 'ok', 'detail');
};

$state = fn (string $service) => collect(ServiceHealth::problems())->firstWhere('service', $service)['state'] ?? null;
$message = fn (string $service) => (string) (collect(ServiceHealth::problems())->firstWhere('service', $service)['message'] ?? '');
$page = fn (string $route, array $params = []) => (string) $call('GET', route($route, $params, false))->getContent();
$twilioPosts = fn () => Http::recorded()->filter(fn ($pair) => str_contains($pair[0]->url(), 'twilio.com') && $pair[0]->method() === 'POST')->count();

DB::beginTransaction();

try {
    $adminId = DB::table('users')->join('roles', 'roles.id', '=', 'users.role_id')
        ->where('roles.permission_type', 'all')->where('users.status', 1)->value('users.id');
    auth()->guard('user')->setUser(Webkul\User\Models\User::find($adminId));

    DB::table('txn_settings')->delete();
    DB::table('txn_service_status')->delete();
    TxnSettings::apply();

    // No key for any service (as on a fresh install), SMS guard off so a text
    // would really be attempted (every HTTP call is faked).
    config([
        'calcom.api_key' => '', 'slot_finder.geocodio_api_key' => '',
        'sms.account_sid' => '', 'sms.auth_token' => '', 'sms.messaging_service_sid' => '', 'sms.from_number' => '',
        'lead_triage.lead_db.username' => '', 'lead_triage.lead_db.password' => '',
        'txn.guard_sms' => false,
    ]);

    $person = app(PersonRepository::class)->create([
        'entity_type' => 'persons', 'name' => 'Health Check', 'user_id' => $adminId,
        'emails' => [['label' => 'work', 'value' => 'health.check@txn-check.example']],
        'contact_numbers' => [['label' => 'work', 'value' => '+17135550100']],
    ]);

    // =====================================================================
    $section = 'Krayin works with no API keys';
    // =====================================================================
    foreach ([
        'Dashboard' => ['admin.dashboard.index', []],
        'Leads board' => ['admin.leads.index', []],
        'A contact page' => ['admin.contacts.persons.view', ['id' => $person->id]],
        'Settings' => ['admin.settings.index', []],
        'Connections' => ['admin.settings.txn.connections', []],
        'Safety' => ['admin.settings.txn.safety', []],
    ] as $label => [$route, $params]) {
        $status = $call('GET', route($route, $params, false))->getStatusCode();
        $check("{$label} opens with no keys", $status === 200, "HTTP {$status}");
    }
    $check('No warning while no service is used', ServiceHealth::problems() === []);

    // =====================================================================
    $section = 'Using a service with no key -> "has no key assigned" (amber)';
    // =====================================================================
    $sms = app(SmsSender::class)->send($person, 'Health check');
    $check('Twilio: the user sees "Twilio has no key assigned"', str_contains($sms['error'] ?? '', 'Twilio has no key assigned'), json_encode($sms));
    $check('... recorded as no key, nothing sent to Twilio', $state('twilio') === 'no_key' && $twilioPosts() === 0);

    try {
        app(SlotSuggestionService::class)->locatePlace('Houston, TX');
        $geoMessage = '';
    } catch (Throwable $exception) {
        $geoMessage = $exception->getMessage();
    }
    $check('Geocodio: "Geocodio has no key assigned"', str_contains($geoMessage, 'Geocodio has no key assigned') && $state('geocodio') === 'no_key', $geoMessage);

    try {
        app(LeadDbImportService::class)->importRecent(48);
        $leadMessage = '';
    } catch (Throwable $exception) {
        $leadMessage = $exception->getMessage();
    }
    $check('Lead database: "Lead database has no key assigned"', str_contains($leadMessage, 'Lead database has no key assigned') && $state('lead_db') === 'no_key', $leadMessage);

    $html = $page('admin.dashboard.index');
    $check('Amber bar on the dashboard: "Twilio has no key assigned"', str_contains($html, 'txn-service-banner is-nokey') && str_contains($html, 'Twilio has no key assigned'));
    $check('... and on the leads board', str_contains($page('admin.leads.index'), 'Twilio has no key assigned'));
    $html = $page('admin.settings.txn.connections');
    $check('Connections: Twilio card says "No key assigned" and flags the missing keys', str_contains($html, 'No key assigned.') && str_contains($html, 'This key is missing'));

    // =====================================================================
    $section = 'A real failure -> "isn\'t working" (red)';
    // =====================================================================
    config(['sms.account_sid' => 'AC'.str_repeat('a1', 16), 'sms.auth_token' => 'wrong-token-check', 'sms.messaging_service_sid' => 'MG'.str_repeat('b2', 16)]);
    $twilioSend = Http::response(['code' => 20003, 'message' => 'Authentication Error - invalid username'], 401);
    app(SmsSender::class)->send($person, 'Health check');
    $check('Twilio 401 / code 20003 -> failing', $state('twilio') === 'failed');
    $check('... with Twilio\'s full message', str_contains($message('twilio'), 'Authentication Error - invalid username') && str_contains($message('twilio'), '20003'), $message('twilio'));

    $html = $page('admin.dashboard.index');
    $check('Red bar on the dashboard: "Twilio isn\'t working" + the error', str_contains($html, 'txn-service-banner is-failed') && str_contains($html, "Twilio isn't working") && str_contains($html, 'Authentication Error - invalid username'));
    $html = $page('admin.settings.txn.connections');
    $check('Connections: Twilio card "Not working", full error, keys flagged "Check this key"', str_contains($html, 'Not working.') && str_contains($html, 'Authentication Error - invalid username') && str_contains($html, 'Check this key'));

    $twilioSend = Http::response(['sid' => 'SMcheck'], 201);
    app(SmsSender::class)->send($person, 'Health check');
    $check('The next successful text clears it', $state('twilio') === null);
    $check('... and the bar is gone', ! str_contains($page('admin.dashboard.index'), "Twilio isn't working"));

    $twilioSend = Http::response(['code' => 21211, 'message' => "The 'To' number is not a valid phone number."], 400);
    app(SmsSender::class)->send($person, 'Health check');
    $check('A bad customer number (21211) is not a Twilio failure', $state('twilio') === null);

    config(['slot_finder.geocodio_api_key' => 'geo-check-key']);
    $geocodio = Http::response(['error' => 'Invalid API key'], 403);
    try {
        app(SlotSuggestionService::class)->locatePlace('1 Check St, Houston, TX');
    } catch (Throwable) {
    }
    $check('Geocodio 403 -> failing, with its message', $state('geocodio') === 'failed' && str_contains($message('geocodio'), 'Invalid API key'), $message('geocodio'));

    $geocodio = Http::response(['error' => 'Could not geocode address'], 422);
    try {
        app(SlotSuggestionService::class)->locatePlace('2 Nowhere Rd, Atlantis');
    } catch (Throwable) {
    }
    $check('An address not on the map (422) is not a Geocodio failure (and clears it)', $state('geocodio') === null);

    config([
        'lead_triage.lead_db.host' => '127.0.0.1', 'lead_triage.lead_db.port' => '1',
        'lead_triage.lead_db.username' => 'check', 'lead_triage.lead_db.password' => 'check',
    ]);
    config(['database.connections.lead_db' => config('lead_triage.lead_db') + ['options' => [\PDO::ATTR_TIMEOUT => 3]]]);
    DB::purge('lead_db');
    try {
        app(LeadDbImportService::class)->importRecent(48);
    } catch (Throwable) {
    }
    $check('Lead database unreachable -> failing', $state('lead_db') === 'failed', $message('lead_db'));

    // =====================================================================
    $section = 'Clearing: a passing Test, or a new key';
    // =====================================================================
    $twilioSend = Http::response(['code' => 20003, 'message' => 'Authentication Error - invalid username'], 401);
    app(SmsSender::class)->send($person, 'Health check');
    $result = json_decode((string) $call('POST', route('admin.settings.txn.connections.test', [], false), [
        'unlocked' => '1', 'service' => 'twilio',
        'sms__account_sid' => config('sms.account_sid'), 'sms__messaging_service_sid' => config('sms.messaging_service_sid'),
    ])->getContent(), true);
    $check('Test Twilio passes -> the Twilio alert is cleared', ($result['ok'] ?? false) === true && $state('twilio') === null, json_encode($result));

    app(SmsSender::class)->send($person, 'Health check');
    $form = ['unlocked' => '1'];
    foreach (TxnSettings::keys('connections') as $key) {
        $form[str_replace('.', '__', $key)] = TxnSettings::isSecret($key) ? '' : (string) ($key === 'mail.default' ? TxnSettings::default($key) : config($key));
    }
    $form['sms__auth_token'] = 'a-new-token-'.bin2hex(random_bytes(6));
    $call('PUT', route('admin.settings.txn.connections.update', [], false), $form);
    $check('Saving a new Twilio token clears the Twilio alert', $state('twilio') === null && config('sms.auth_token') === $form['sms__auth_token']);
    $check('... the other alerts stay until those services work', $state('lead_db') === 'failed');

    $check('No call left this machine (every service call was faked)', Http::recorded()->every(fn ($pair) => preg_match('/twilio\.com|geocod\.io/', $pair[0]->url())), Http::recorded()->count().' requests');
} catch (Throwable $exception) {
    $check('Check script crashed', false, get_class($exception).': '.$exception->getMessage().' @ '.basename($exception->getFile()).':'.$exception->getLine());
} finally {
    DB::rollBack();
}

$failed = 0;
$current = null;

foreach ($results as $result) {
    if ($result['section'] !== $current) {
        $current = $result['section'];
        echo "\n== {$current}\n";
    }

    $failed += $result['ok'] ? 0 : 1;
    echo ($result['ok'] ? '  PASS  ' : '  FAIL  ').$result['name'].($result['detail'] !== '' && ! $result['ok'] ? "  [{$result['detail']}]" : '')."\n";
}

echo "\n".count($results).' checks, '.(count($results) - $failed)." passed, {$failed} failed. Database rolled back.\n";

exit(min($failed, 255));
