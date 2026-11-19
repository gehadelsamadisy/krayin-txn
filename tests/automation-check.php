<?php

/*
 * Automation check (Settings > TXN Settings > Automation, Support\Automation,
 * BookingLinkSender, PipelineEvents, Cal.com webhook, follow-up gates).
 *
 * Run inside the Krayin container, after the migrations:
 *   php tests/automation-check.php
 *
 * One database transaction, rolled back at the end. Cal.com, Geocodio and
 * Twilio are faked (nothing leaves this machine); mail goes to the array
 * mailer; only test addresses are used. Exit code = number of failed checks.
 */

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Webkul\Admin\Models\Worker;
use Webkul\Admin\Support\Automation\Automation;
use Webkul\Admin\Support\Contact\BookingLinkSender;
use Webkul\Admin\Support\Contact\FollowUpService;
use Webkul\Admin\Support\Contact\LeadTriageService;
use Webkul\Admin\Support\Contact\TxnSettings;
use Webkul\Contact\Repositories\PersonRepository;
use Webkul\Lead\Models\Lead;
use Webkul\Lead\Repositories\LeadRepository;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->bootstrap();
$app->instance('request', Request::create('/admin'));

$pass = new class { public function handle($request, $next) { return $next($request); } };
foreach ([
    Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class,
    Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
    App\Http\Middleware\VerifyCsrfToken::class,
] as $class) {
    $app->instance($class, $pass);
}

$results = [];
$section = '';
$check = function (string $name, bool $ok, string $detail = '') use (&$results, &$section) {
    $results[] = compact('section', 'name', 'ok', 'detail');
};

// ---------------------------------------------------------------------------
// Fakes: Geocodio (Houston), Cal.com (link + bookings), Twilio.
// ---------------------------------------------------------------------------
$booking = null; // what GET /v2/bookings/{uid} answers
$linkNo = 0;
Http::fake(function ($request) use (&$booking, &$linkNo) {
    $url = $request->url();

    return match (true) {
        str_contains($url, 'api.geocod.io') => Http::response(['results' => [['location' => ['lat' => 29.7604, 'lng' => -95.3698], 'accuracy_type' => 'rooftop']]]),
        (bool) preg_match('#/v2/schedules$#', $url) => Http::response(['data' => ['id' => 11]]),
        (bool) preg_match('#/v2/event-types/\d+/private-links#', $url) => Http::response(['data' => ['bookingUrl' => 'https://cal.com/d/auto-check-'.(++$linkNo).'/home-visit']]),
        (bool) preg_match('#/v2/event-types$#', $url) => Http::response(['data' => ['id' => 22, 'locations' => []]]),
        str_contains($url, '/v2/bookings/') => Http::response(['data' => $booking]),
        str_contains($url, '/v2/bookings') => Http::response(['data' => []]),
        str_contains($url, 'api.twilio.com') => Http::response(['sid' => 'SMcheck'], 201),
        default => Http::response([], 500),
    };
});

$calcomCalls = fn () => Http::recorded()->filter(fn ($pair) => str_contains($pair[0]->url(), '/v2/event-types') && $pair[0]->method() === 'POST')->count();
$twilioCalls = fn () => Http::recorded()->filter(fn ($pair) => str_contains($pair[0]->url(), 'api.twilio.com'))->count();
$mails = function () {
    return collect(app('mail.manager')->mailer('array')->getSymfonyTransport()->messages())
        ->map(fn ($sent) => collect($sent->getOriginalMessage()->getTo())->map(fn ($address) => $address->getAddress())->implode(','));
};
$events = fn (string $event, $lead = null) => DB::table('txn_events')->where('event', $event)->when($lead, fn ($q) => $q->where('lead_id', $lead->id))->get();
$test = 'auto.check@txn-check.example';

DB::beginTransaction();

try {
    config([
        'mail.default' => 'array', 'queue.default' => 'sync', 'cache.default' => 'array',
        'slot_finder.geocodio_api_key' => 'check-key',
        'calcom.api_key' => 'check-key',
        'sms.account_sid' => 'AC'.str_repeat('a', 32), 'sms.auth_token' => 'check-token', 'sms.messaging_service_sid' => 'MG'.str_repeat('b', 32),
        'txn.guard_email' => true, 'mail_guard.allowed_recipients' => [$test],
        'txn.guard_sms' => true, 'sms.allowed_recipients' => ['+17135550199'],
        'txn.guard_calcom' => true, 'calcom.allow_customer_email' => true, 'calcom.test_recipient_emails' => [$test], 'calcom.single_test_attempt' => false,
        'automation.enabled' => false, 'automation.email.enabled' => false, 'automation.sms.enabled' => false, 'automation.staff.enabled' => false, 'automation.webhook.enabled' => false,
        'automation.email.send_link' => true, 'automation.email.flows' => ['standard'],
    ]);

    $adminId = DB::table('users')->join('roles', 'roles.id', '=', 'users.role_id')
        ->where('roles.permission_type', 'all')->where('users.status', 1)->value('users.id');
    auth()->guard('user')->setUser(Webkul\User\Models\User::find($adminId));

    Worker::query()->create([
        'name' => 'Automation Check Worker', 'is_active' => true, 'start_address' => '1 Check St, Houston, TX',
        'home_latitude' => 29.76, 'home_longitude' => -95.37, 'work_hours' => [], 'priority' => 1, 'services' => [],
    ]);

    $n = 0;
    $makePerson = function (string $email, string $phone) use ($adminId, &$n) {
        $n++;

        return app(PersonRepository::class)->create([
            'entity_type' => 'persons', 'name' => "Auto Check {$n}",
            'emails' => [['label' => 'work', 'value' => $email]],
            'contact_numbers' => [['label' => 'work', 'value' => $phone]],
            'user_id' => $adminId,
            'csv_address' => "{$n}00 Main St", 'csv_city' => 'Houston', 'csv_state_province' => 'TX', 'csv_postal_code' => '77002',
        ]);
    };
    $settings = config('lead_triage.import');
    $defaultId = (int) DB::table('lead_pipelines')->where('is_default', 1)->value('id');
    $newStage = (int) DB::table('lead_pipeline_stages')->where('lead_pipeline_id', $defaultId)->where('code', 'new')->value('id');
    $makeLead = fn (string $service, $person) => app(LeadRepository::class)->create([
        'entity_type' => 'leads', 'title' => "{$service} - {$person->name}", 'lead_value' => 0, 'status' => 1,
        'lead_source_id' => $settings['source_id'], 'lead_type_id' => $settings['type_id'], 'user_id' => $settings['owner_id'],
        'lead_pipeline_id' => $defaultId, 'lead_pipeline_stage_id' => $newStage, 'person' => ['id' => $person->id],
    ]);
    $stageCode = fn ($lead) => DB::table('lead_pipeline_stages')->where('id', DB::table('leads')->where('id', $lead->id)->value('lead_pipeline_stage_id'))->value('code');
    $links = fn ($lead) => DB::table('calcom_booking_links')->where('lead_id', $lead->id)->count();
    $triage = app(LeadTriageService::class);

    $person = $makePerson($test, '713-555-0199');
    config(['calcom.test_person_ids' => [$person->id]]);

    // =====================================================================
    $section = 'Everything off (as shipped)';
    // =====================================================================
    $check('Defaults: master switch and every engine off', ! config('automation.enabled') && ! Automation::on('email') && ! Automation::on('sms') && ! Automation::on('staff') && ! Automation::webhookOn() && ! Automation::anyFollowUps());

    $lead = $makeLead('Gutter repair', $person);
    $before = $calcomCalls();
    $triage->check($lead, true);
    $check('A new ready lead gets no link', $links($lead) === 0 && $calcomCalls() === $before && $stageCode($lead) === 'new');
    $check('... the log says why ("Email engine is off")', $events('automation_skipped', $lead)->contains(fn ($e) => str_contains($e->summary, 'Email engine is off')));
    $check('... and records the check itself', $events('lead_checked', $lead)->isNotEmpty());

    // =====================================================================
    $section = 'Email engine on';
    // =====================================================================
    config(['automation.enabled' => true, 'automation.email.enabled' => true]);
    $lead2 = $makeLead('Gutter repair', $person);
    $triage->check($lead2, true);
    $check('New ready lead -> link created for that lead', $links($lead2) === 1, (string) $links($lead2));
    $check('... emailed to the test address only', $mails()->contains($test) && $mails()->every(fn ($to) => $to === $test), $mails()->implode(' | '));
    $check('... lead moved to Contacted', $stageCode($lead2) === 'contacted', (string) $stageCode($lead2));
    $check('... follow-ups started', DB::table('txn_follow_ups')->where('lead_id', $lead2->id)->exists());
    $check('... no text (SMS engine off)', $twilioCalls() === 0);
    $sent = $events('link_sent', $lead2)->first();
    $check('Event log: times suggested, link created (with times), link sent, by "Automation"',
        $events('times_suggested', $lead2)->isNotEmpty() && $events('link_created', $lead2)->contains(fn ($e) => str_contains($e->summary, 'with '))
        && $sent && $sent->actor === 'Automation' && str_contains($sent->summary, 'by email'), json_encode($sent));

    $triage->check($lead2->fresh());
    $outcome = app(BookingLinkSender::class)->sendAutomatic(Lead::find($lead2->id), false);
    $check('Never twice: re-check and a second automatic send do nothing', $links($lead2) === 1 && $outcome['status'] === 'skipped', json_encode($outcome));

    $check('Staff edits never send: an existing Raw Lead re-checked (not new) gets no link', (function () use ($triage, $makeLead, $person, $links) {
        $old = $makeLead('Gutter repair', $person);
        config(['automation.enabled' => false]);
        $triage->check($old, true);          // arrives while off
        config(['automation.enabled' => true]);
        $triage->check($old->fresh());       // later edit / re-check

        return $links($old) === 0;
    })());

    config(['automation.email.flows' => []]);
    $lead3 = $makeLead('Gutter repair', $person);
    $triage->check($lead3, true);
    $check('Flow not chosen -> skipped, logged', $links($lead3) === 0 && $events('automation_skipped', $lead3)->contains(fn ($e) => str_contains($e->summary, 'flow')));
    config(['automation.email.flows' => ['standard']]);

    $roof = $makeLead('Roof replacement', $person);
    $triage->check($roof, true);
    $check('Roof lead -> waits for the roof report, no link', $links($roof) === 0 && $stageCode($roof) === 'waiting-roof-report', (string) $stageCode($roof));

    // =====================================================================
    $section = 'Guards';
    // =====================================================================
    $stranger = $makePerson('someone@txn-check.example', '713-555-0177');
    $lead4 = $makeLead('Gutter repair', $stranger);
    $before = $calcomCalls();
    $triage->check($lead4, true);
    $check('Cal.com guard on, not a test contact -> blocked, no Cal.com call', $links($lead4) === 0 && $calcomCalls() === $before && $events('automation_blocked', $lead4)->isNotEmpty());

    $threw = false;
    try {
        app(Webkul\Admin\Support\Contact\CalcomBookingLinkService::class)->createBookingLink($stranger, ['2030-01-07T09:00' => 1], 'America/Chicago', []);
    } catch (Webkul\Admin\Support\Contact\CalcomApiException) {
        $threw = true;
    }
    $check('The link service itself refuses (BookingLinkGuard), whatever the caller', $threw);

    // =====================================================================
    $section = 'SMS engine';
    // =====================================================================
    config(['automation.sms.enabled' => true, 'automation.sms.booking_link' => true]);
    $lead5 = $makeLead('Gutter repair', $person);
    $triage->check($lead5, true);
    $check('SMS engine on -> the link is texted to the allowed test number', $twilioCalls() === 1 && $events('link_sent', $lead5)->contains(fn ($e) => str_contains($e->summary, 'by SMS')), (string) $twilioCalls());
    config(['sms.allowed_recipients' => []]);
    $lead6 = $makeLead('Gutter repair', $person);
    $triage->check($lead6, true);
    $check('SMS guard still applies (number not allowed -> no text, logged as blocked)', $twilioCalls() === 1 && $events('link_sent', $lead6)->contains(fn ($e) => str_contains($e->summary, 'SMS blocked')));

    // =====================================================================
    $section = 'Staff notifications';
    // =====================================================================
    config(['automation.staff.enabled' => true, 'automation.staff.recipients' => [$test]]);
    $count = $mails()->count();
    $odd = $makeLead('Pool cleaning', $person);
    $triage->check($odd, true);
    $check('A new lead needing review -> staff emailed, logged', $mails()->count() === $count + 1 && $events('staff_notified', $odd)->isNotEmpty());
    config(['automation.staff.enabled' => false]);
    $odd2 = $makeLead('Pool cleaning', $person);
    $triage->check($odd2, true);
    $check('Staff notifications off -> nothing', $events('staff_notified', $odd2)->isEmpty());

    // =====================================================================
    $section = 'Clock (follow-up gates)';
    // =====================================================================
    DB::table('txn_follow_ups')->where('lead_id', $lead2->id)->update(['link_sent_at' => now()->subHours(25)]);
    $off = ['reminder' => false, 'final' => false, 'lost' => false, 'sms_reminder' => false];
    app(FollowUpService::class)->run(false, CarbonImmutable::now(), $off);
    $check('Reminder switched off -> not sent when due', DB::table('txn_follow_ups')->where('lead_id', $lead2->id)->value('reminder_sent_at') === null);
    app(FollowUpService::class)->run(false, CarbonImmutable::now(), ['reminder' => true] + $off);
    $check('Reminder on -> sent when due, logged', DB::table('txn_follow_ups')->where('lead_id', $lead2->id)->value('reminder_sent_at') !== null && $events('reminder_sent', $lead2)->isNotEmpty());
    config(['automation.email.reminder' => true]);
    $check('Clock gates follow the switches', Automation::followUpGates()['reminder'] === true && (config(['automation.enabled' => false]) || true) && Automation::anyFollowUps() === false);
    config(['automation.enabled' => true]);

    // =====================================================================
    $section = 'Cal.com webhook';
    // =====================================================================
    $hook = function (array $payload, ?string $secret) use ($kernel) {
        $body = json_encode($payload);
        $request = Request::create('/txn/calcom-webhook', 'POST', [], [], [], array_filter([
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CAL_SIGNATURE_256' => $secret ? hash_hmac('sha256', $body, $secret) : null,
        ]), $body);
        app()->instance('request', $request);
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response); // runs the after-response work

        return $response->getStatusCode();
    };
    $link = DB::table('calcom_booking_links')->where('lead_id', $lead2->id)->first();
    $slot = array_key_first(json_decode($link->slot_workers, true));
    $start = CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $slot, config('slot_finder.timezone'))->utc();
    $booking = [
        'uid' => 'auto-check-1', 'id' => 991, 'status' => 'accepted', 'eventTypeId' => 22,
        'eventType' => ['slug' => $link->event_type_slug],
        'start' => $start->toIso8601ZuluString(), 'end' => $start->addHour()->toIso8601ZuluString(),
        'createdAt' => now()->toIso8601ZuluString(), 'updatedAt' => now()->toIso8601ZuluString(),
        'attendees' => [['name' => 'Auto Check', 'email' => $test]],
    ];
    $payload = ['triggerEvent' => 'BOOKING_CREATED', 'payload' => ['uid' => 'auto-check-1']];

    $check('Webhook off -> 404', $hook($payload, 'check-secret-0123456789') === 404);
    config(['automation.webhook.enabled' => true, 'automation.webhook.secret' => 'check-secret-0123456789']);
    $check('Bad signature -> 401', $hook($payload, 'wrong-secret-9876543210') === 401);
    $check('No signature -> 401', $hook($payload, null) === 401);
    $check('Ping / other events -> accepted, nothing done', $hook(['triggerEvent' => 'PING', 'payload' => []], 'check-secret-0123456789') === 200);
    $status = $hook($payload, 'check-secret-0123456789');
    $received = $events('booking_received', $lead2)->first();
    $check('Signed booking -> 202, saved like the Sync button, lead -> Appointment Scheduled', $status === 202 && $stageCode($lead2) === 'appointment-set', "HTTP {$status}, stage ".$stageCode($lead2));
    $check('... logged "booked … with …" by "Cal.com webhook"', $received && $received->actor === 'Cal.com webhook' && str_contains($received->summary, 'booked'), json_encode($received));

    // =====================================================================
    $section = 'Screens';
    // =====================================================================
    $page = fn (string $uri) => $kernel->handle(Request::create($uri, 'GET'));
    $auto = $page(route('admin.settings.txn.automation', [], false));
    $check('Automation screen opens', $auto->getStatusCode() === 200 && str_contains($auto->getContent(), 'Email engine') && str_contains($auto->getContent(), 'Cal.com webhook'), 'HTTP '.$auto->getStatusCode());
    $log = $page(route('admin.leads.event_log', ['from' => '2020-01-01'], false));
    $check('Event log opens and lists the events', $log->getStatusCode() === 200 && str_contains($log->getContent(), 'Booking received'), 'HTTP '.$log->getStatusCode());
    $csv = $page(route('admin.leads.event_log.export', ['from' => '2020-01-01'], false));
    $check('Event log CSV export', $csv->getStatusCode() === 200);
    $leadPage = $page(route('admin.leads.view', $lead2->id, false));
    $check('Lead page shows its Events card', $leadPage->getStatusCode() === 200 && str_contains($leadPage->getContent(), 'Events'), 'HTTP '.$leadPage->getStatusCode());

    $saveUrl = route('admin.settings.txn.automation.update', [], false);
    $kernel->handle(Request::create($saveUrl, 'PUT', ['automation__enabled' => '1', 'automation__email__enabled' => '0']));
    $check('Switching an engine on the screen is saved and logged', TxnSettings::isSaved('automation.enabled') && $events('engine_switched')->isNotEmpty());
    $kernel->handle(Request::create($saveUrl, 'PUT', ['reset' => 'all']));
    $check('"Reset (all off)" -> everything back off', ! TxnSettings::isSaved('automation.enabled') && ! config('automation.enabled'));

    $check('No call left this machine (all faked)', Http::recorded()->every(fn ($pair) => preg_match('/geocod\.io|cal\.com|twilio\.com/', $pair[0]->url())), Http::recorded()->count().' requests');
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
