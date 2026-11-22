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
use Webkul\Admin\Support\Automation\MessageScheduler;
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
    $sentCopy = DB::table('emails')->where('lead_id', $lead2->id)->where('folders', 'like', '%"sent"%')->first();
    $check('... and a copy is in Krayin Mail > Sent, on the contact and the lead', $sentCopy && (int) $sentCopy->person_id === (int) $person->id && str_contains((string) $sentCopy->reply_to, $test) && $sentCopy->subject !== '' && ! str_contains((string) $sentCopy->reply, '<html'), json_encode($sentCopy));
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
    $check('Staff notifications are not copied to Mail > Sent (customer emails only)', DB::table('emails')->whereIn('lead_id', [$odd->id, $odd2->id])->doesntExist());

    // =====================================================================
    $section = 'Follow-up schedule (exact times)';
    // =====================================================================
    $sched = app(MessageScheduler::class);
    $rowsOf = fn ($lead, ?string $status = null) => DB::table('txn_scheduled_messages')->where('lead_id', $lead->id)->when($status, fn ($q) => $q->where('status', $status))->orderBy('id')->get();
    $rowOf = fn ($lead, string $kind, string $status = 'pending') => DB::table('txn_scheduled_messages')->where('lead_id', $lead->id)->where('kind', $kind)->where('status', $status)->orderByDesc('id')->first();
    $fu = DB::table('txn_follow_ups')->where('lead_id', $lead2->id)->whereNull('stopped_at')->first();
    $expect = fn (int $hours) => CarbonImmutable::parse($fu->link_sent_at)->addHours($hours)->utc()->toDateTimeString();

    $r2 = $rowsOf($lead2, 'pending');
    $check('Link sent with the Email engine on -> reminder, final and Lost scheduled at link time + their hours',
        $r2->pluck('kind')->all() === ['reminder', 'final', 'lost']
        && $r2[0]->due_at === $expect((int) config('follow_ups.reminder_after_hours'))
        && $r2[1]->due_at === $expect((int) config('follow_ups.final_after_hours'))
        && $r2[2]->due_at === $expect((int) config('follow_ups.lost_after_hours')), $r2->map(fn ($r) => "{$r->kind}@{$r->due_at}")->implode(', '));
    $check('... each has a job waiting for its exact time (database queue)', DB::table('jobs')->where('queue', MessageScheduler::QUEUE)->count() >= 3);
    $check('... and "scheduled" is in the event log', $events('message_scheduled', $lead2)->count() === 3);

    $reminder = $rowOf($lead2, 'reminder');
    $sched->fire($reminder->id, '2000-01-01 00:00:00');
    $check('A job made for another time does nothing', $rowOf($lead2, 'reminder')?->id === $reminder->id);

    $sched->changeTime($reminder->id, CarbonImmutable::now()->addHours(2), 'Check');
    $moved = DB::table('txn_scheduled_messages')->find($reminder->id);
    $check('Change time -> moved, logged', $moved->due_at !== $reminder->due_at && $events('message_rescheduled', $lead2)->isNotEmpty());
    $sched->fire($reminder->id, $reminder->due_at);
    $check('... the old job then does nothing', DB::table('txn_scheduled_messages')->find($reminder->id)->status === 'pending');

    $sched->fire($moved->id, $moved->due_at);
    $check('At its time: sent (reminder email), entry marked sent, logged', DB::table('txn_scheduled_messages')->find($moved->id)->status === 'sent'
        && DB::table('txn_follow_ups')->where('id', $fu->id)->value('reminder_sent_at') !== null && $events('reminder_sent', $lead2)->count() === 1);
    $sched->fire($moved->id, $moved->due_at);
    $check('... never twice', $events('reminder_sent', $lead2)->count() === 1);

    config(['automation.email.final' => false]);
    $sched->syncWithSettings();
    $cancelledFinal = $rowOf($lead2, 'final', 'cancelled');
    $check('Final email switched off -> its entry cancelled, with the reason', $cancelledFinal && str_contains((string) $cancelledFinal->reason, 'switched off') && ! $rowOf($lead2, 'final'));
    config(['automation.email.final' => true]);
    $sched->syncWithSettings();
    $check('Switched back on -> scheduled again', (bool) $rowOf($lead2, 'final'));

    config(['follow_ups.lost_after_hours' => 120]);
    $sched->syncWithSettings();
    $check('New Lost hours -> the pending Lost entry is re-timed', $rowOf($lead2, 'lost')?->due_at === $expect(120), (string) $rowOf($lead2, 'lost')?->due_at);
    config(['follow_ups.lost_after_hours' => 96]);
    $sched->syncWithSettings();

    $lostRow = $rowOf($lead2, 'lost');
    $sched->cancelOne($lostRow->id, 'Check Staff');
    $check('Staff cancel -> cancelled, with who did it', ($c = DB::table('txn_scheduled_messages')->find($lostRow->id))->status === 'cancelled' && $c->done_by === 'Check Staff');

    $prospect = (int) DB::table('lead_pipeline_stages')->where('lead_pipeline_id', $defaultId)->where('code', 'prospect')->value('id');
    $pendingBefore = $rowsOf($lead5, 'pending')->count();
    // Like staff dragging the card on the board: the lead record's stage changes.
    Lead::find($lead5->id)->update(['lead_pipeline_stage_id' => $prospect]);
    // (Some of lead 5's entries were already cancelled earlier, e.g. by "Final email switched off".)
    $check('Lead moved out of Contacted (Visit Done) -> its entries cancelled', $pendingBefore > 0 && $rowsOf($lead5, 'pending')->isEmpty()
        && $rowsOf($lead5, 'cancelled')->filter(fn ($r) => str_contains((string) $r->reason, 'moved to Visit Done'))->count() === $pendingBefore,
        "{$pendingBefore} before, ".$rowsOf($lead5, 'pending')->count().' still pending, reasons: '.$rowsOf($lead5, 'cancelled')->pluck('reason')->implode(' | '));

    $oldRows = $rowsOf($lead6, 'pending')->pluck('id');
    app(FollowUpService::class)->start(Webkul\Contact\Models\Person::find($person->id), Lead::find($lead6->id), $test, 'https://cal.com/d/auto-check-newer/home-visit');
    $check('Newer link sent -> the old entries cancelled, new ones scheduled', $oldRows->isNotEmpty()
        && DB::table('txn_scheduled_messages')->whereIn('id', $oldRows)->where('status', 'cancelled')->count() === $oldRows->count()
        && $rowsOf($lead6, 'pending')->count() === 3);

    $page = fn (string $uri) => $kernel->handle(Request::create($uri, 'GET'));
    $sp = $page(route('admin.leads.scheduled', [], false));
    $check('Scheduled messages page opens and lists them', $sp->getStatusCode() === 200 && str_contains($sp->getContent(), 'Scheduled messages') && str_contains($sp->getContent(), 'Change time'), 'HTTP '.$sp->getStatusCode());
    $rem6 = $rowOf($lead6, 'reminder');
    $kernel->handle(Request::create(route('admin.leads.scheduled.send_now', $rem6->id, false), 'POST'));
    $check('Staff "Send now" -> sent at once', DB::table('txn_scheduled_messages')->find($rem6->id)->status === 'sent');
    $fin6 = $rowOf($lead6, 'final');
    $kernel->handle(Request::create(route('admin.leads.scheduled.retime', $fin6->id, false), 'POST', ['when' => CarbonImmutable::now(config('slot_finder.timezone'))->addDays(3)->format('Y-m-d\TH:i')]));
    $check('Staff "Change time" from the page -> moved', DB::table('txn_scheduled_messages')->find($fin6->id)->due_at !== $fin6->due_at);

    config(['automation.staff.enabled' => true, 'automation.staff.recipients' => [$test]]);
    $lost6 = $rowOf($lead6, 'lost');
    $sched->sendNow($lost6->id, 'Check Staff');
    $check('Lost step -> lead marked Lost, and staff told "A lead was marked Lost"', $stageCode($lead6) === 'lost'
        && $events('marked_lost', $lead6)->isNotEmpty() && $events('staff_notified', $lead6)->contains(fn ($e) => str_contains($e->summary, 'Marked Lost')), (string) $stageCode($lead6));
    config(['automation.staff.enabled' => false]);

    $check('Final text and reminder text are on by default (requirements A7/A8), still only with the SMS engine on',
        config('automation.sms.reminder') === true && config('automation.sms.final') === true && in_array('marked_lost', (array) config('automation.staff.events'), true));

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
    $check('Booked -> no follow-up left on the schedule for that lead', $rowsOf($lead2, 'pending')->isEmpty());

    // The customer cancels: a cancellation email (test address) and no follow-ups.
    $booking = array_merge($booking, ['status' => 'cancelled', 'updatedAt' => now()->addMinute()->toIso8601ZuluString()]);
    $status = $hook(['triggerEvent' => 'BOOKING_CANCELLED', 'payload' => ['uid' => 'auto-check-1']], 'check-secret-0123456789');
    $notice = $events('cancel_notice_sent', $lead2)->first();
    $check('Customer cancels -> lead Canceled, cancellation email sent, logged', $status === 202 && $stageCode($lead2) === 'appointment-canceled'
        && $notice && str_contains($notice->summary, 'by email'), json_encode($notice));
    $check('... and nothing new on the schedule', $rowsOf($lead2, 'pending')->isEmpty());

    // =====================================================================
    $section = 'Screens';
    // =====================================================================
    $page = fn (string $uri) => $kernel->handle(Request::create($uri, 'GET'));
    $auto = $page(route('admin.settings.txn.automation', [], false));
    $check('Automation screen opens', $auto->getStatusCode() === 200 && str_contains($auto->getContent(), 'Email engine') && str_contains($auto->getContent(), 'Cal.com webhook'), 'HTTP '.$auto->getStatusCode());
    $html = $auto->getContent();
    $check('Each switch shows its own title (not a leftover list label)',
        str_contains($html, 'Reminder email') && str_contains($html, 'Final email') && str_contains($html, 'Mark Lost after the last follow-up')
        && str_contains($html, 'Text the booking link') && str_contains($html, 'Reminder text')
        && substr_count($html, 'Roof report first') <= 3, substr_count($html, 'Roof report first').' x "Roof report first"');
    $log = $page(route('admin.leads.event_log', ['from' => '2020-01-01'], false));
    $check('Event log opens and lists the events', $log->getStatusCode() === 200 && str_contains($log->getContent(), 'Booking received'), 'HTTP '.$log->getStatusCode());
    $csv = $page(route('admin.leads.event_log.export', ['from' => '2020-01-01'], false));
    $check('Event log CSV export', $csv->getStatusCode() === 200);
    $leadPage = $page(route('admin.leads.view', $lead2->id, false));
    $check('Lead page shows its Events card', $leadPage->getStatusCode() === 200 && str_contains($leadPage->getContent(), 'Events'), 'HTTP '.$leadPage->getStatusCode());

    // Waiting for booking: a contacted test lead with its link and next follow-up.
    $waitingLead = $makeLead('Gutter repair', $person);
    $triage->check($waitingLead, true);
    $wait = $page(route('admin.leads.waiting', [], false));
    $check('Waiting for booking opens and lists a contacted lead with its next follow-up', $wait->getStatusCode() === 200
        && str_contains($wait->getContent(), 'Waiting for booking') && str_contains($wait->getContent(), e($waitingLead->title)) && str_contains($wait->getContent(), 'Next:'), 'HTTP '.$wait->getStatusCode());
    $waitFu = DB::table('txn_follow_ups')->where('lead_id', $waitingLead->id)->whereNull('stopped_at')->first();
    $kernel->handle(Request::create(route('admin.leads.waiting.stop', $waitFu->id, false), 'POST'));
    $check('"Stop follow-ups" -> stopped, pending entries cancelled', DB::table('txn_follow_ups')->find($waitFu->id)->stopped_at !== null && $rowsOf($waitingLead, 'pending')->isEmpty());

    $saveUrl = route('admin.settings.txn.automation.update', [], false);
    $kernel->handle(Request::create($saveUrl, 'PUT', ['automation__enabled' => '1', 'automation__email__enabled' => '0']));
    $check('Switching an engine on the screen is saved and logged', TxnSettings::isSaved('automation.enabled') && $events('engine_switched')->isNotEmpty());
    $kernel->handle(Request::create($saveUrl, 'PUT', ['reset' => 'all']));
    $check('"Reset (all off)" -> everything back off', ! TxnSettings::isSaved('automation.enabled') && ! config('automation.enabled'));

    // Every request was answered by the fake above (Geocodio, Cal.com, Twilio, and
    // the routing server's road times, which the fake answers with 500).
    $osrmHost = preg_quote((string) parse_url((string) config('slot_finder.routing.osrm_url'), PHP_URL_HOST), '/');
    $unexpected = Http::recorded()->map(fn ($pair) => $pair[0]->url())
        ->reject(fn ($url) => preg_match('/geocod\.io|cal\.com|twilio\.com'.($osrmHost !== '' ? "|{$osrmHost}" : '').'/', $url))
        ->values();
    $check('No call left this machine (all faked)', $unexpected->isEmpty(), $unexpected->take(3)->implode(' | '));
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
