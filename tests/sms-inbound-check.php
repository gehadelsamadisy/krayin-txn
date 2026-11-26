<?php

/*
 * Incoming texts check (POST /txn/sms-inbound, SmsInboundController,
 * Support\Replies\SmsInbox, DemoSms; Leads > SMS messages).
 *
 * Run inside the Krayin container (copy the file in first):
 *   docker cp tests/sms-inbound-check.php krayin:/var/www/laravel-crm/tests/
 *   docker exec -u www-data -e HOME=/tmp krayin php tests/sms-inbound-check.php
 *
 * Twilio is never contacted: requests are made up and signed with a fake
 * auth token; the one send goes to a faked Twilio. Fictional 555-01xx
 * numbers only. All rows are written inside a transaction that is rolled
 * back at the end. Exit code = number of failed checks.
 */

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Webkul\Admin\Http\Controllers\Contact\SmsInboundController;
use Webkul\Admin\Support\Contact\SmsSender;
use Webkul\Admin\Support\Contact\TxnSettings;
use Webkul\Admin\Support\Replies\DemoSms;
use Webkul\Admin\Support\Replies\SmsInbox;
use Webkul\Contact\Repositories\PersonRepository;
use Webkul\Lead\Repositories\LeadRepository;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$failures = 0;

function check(string $name, bool $ok, string $detail = ''): void
{
    global $failures;
    echo ($ok ? '  ok    ' : '  FAIL  ').$name.($ok || $detail === '' ? '' : " ({$detail})").PHP_EOL;
    $failures += $ok ? 0 : 1;
}

const PUBLIC_URL = 'https://krayin-check.example/txn/sms-inbound';
const TOKEN = 'check-auth-token';

/** Posts a text like Twilio does; $sign false = no signature, a string = that signature. */
function postText(array $fields, bool|string $sign = true): Illuminate\Http\Response|Symfony\Component\HttpFoundation\Response
{
    $signature = $sign === true ? SmsInboundController::signature(PUBLIC_URL, $fields, TOKEN) : ($sign ?: null);
    $server = $signature ? ['HTTP_X_TWILIO_SIGNATURE' => $signature] : [];

    return app(Illuminate\Contracts\Http\Kernel::class)->handle(Request::create('/txn/sms-inbound', 'POST', $fields, [], [], $server));
}

function text(string $sid, string $from, string $body, array $extra = []): array
{
    return ['MessageSid' => $sid, 'SmsMessageSid' => $sid, 'AccountSid' => 'AC'.str_repeat('0', 32), 'From' => $from, 'To' => '+12025550100', 'Body' => $body, 'NumMedia' => '0'] + $extra;
}

DB::beginTransaction();

try {
    DB::table('txn_settings')->delete();
    TxnSettings::apply();

    config([
        'cache.default' => 'array',
        'sms.auth_token' => TOKEN,
        'sms.account_sid' => 'AC'.str_repeat('a', 32),
        'sms.messaging_service_sid' => 'MG'.str_repeat('b', 32),
        'sms.from_number' => '+12025550100',
        'txn.guard_sms' => true,
        'sms.allowed_recipients' => ['+12025550142'],
        'automation.sms_intake.enabled' => false,
        'automation.sms_intake.public_url' => PUBLIC_URL,
    ]);

    $adminId = DB::table('users')->join('roles', 'roles.id', '=', 'users.role_id')
        ->where('roles.permission_type', 'all')->where('users.status', 1)->value('users.id');

    // Contact (number written differently from Twilio's form) with an open lead.
    $person = app(PersonRepository::class)->create([
        'entity_type' => 'persons', 'name' => 'SMS Check Customer',
        'emails' => [['label' => 'work', 'value' => 'sms-check@test.invalid']],
        'contact_numbers' => [['label' => 'mobile', 'value' => '(202) 555-0142']],
        'user_id' => $adminId,
    ]);
    $settings = config('lead_triage.import');
    $defaultId = (int) DB::table('lead_pipelines')->where('is_default', 1)->value('id');
    $stage = (int) DB::table('lead_pipeline_stages')->where('lead_pipeline_id', $defaultId)->where('code', 'new')->value('id');
    $lead = app(LeadRepository::class)->create([
        'entity_type' => 'leads', 'title' => 'Windows - SMS Check', 'lead_value' => 0, 'status' => 1,
        'lead_source_id' => $settings['source_id'], 'lead_type_id' => $settings['type_id'], 'user_id' => $settings['owner_id'],
        'lead_pipeline_id' => $defaultId, 'lead_pipeline_stage_id' => $stage, 'person' => ['id' => $person->id],
    ]);
    $count = fn () => DB::table('txn_sms_messages')->count();

    // 1. Switch off: refused.
    $before = $count();
    check('switch off: 404', postText(text('SMcheck1', '+12025550142', 'Tuesday works'))->getStatusCode() === 404);
    config(['automation.sms_intake.enabled' => true]);

    // 2. Signature.
    check('no signature: 403', postText(text('SMcheck1', '+12025550142', 'Tuesday works'), false)->getStatusCode() === 403);
    check('wrong signature: 403', postText(text('SMcheck1', '+12025550142', 'Tuesday works'), 'bm90LXRoZS1yaWdodC1vbmU=')->getStatusCode() === 403);
    $tampered = text('SMcheck1', '+12025550142', 'Tuesday works');
    $sig = SmsInboundController::signature(PUBLIC_URL, $tampered, TOKEN);
    $tampered['Body'] = 'Changed on the way';
    check('changed text with old signature: 403', postText($tampered, $sig)->getStatusCode() === 403);
    check('nothing saved from refused requests', $count() === $before);

    // 3. A real-looking text: saved, matched, empty TwiML, no reply.
    $response = postText(text('SMcheck1', '+12025550142', 'Tuesday at 11 works'));
    $row = DB::table('txn_sms_messages')->where('message_sid', 'SMcheck1')->first();
    check('signed text: 200 with empty TwiML', $response->getStatusCode() === 200 && str_contains((string) $response->getContent(), '<Response></Response>'), (string) $response->getContent());
    check('saved as incoming', $row && $row->direction === 'in' && $row->body === 'Tuesday at 11 works' && $row->customer_number === '+12025550142');
    check('matched to the contact and open lead', $row && (int) $row->person_id === (int) $person->id && (int) $row->lead_id === (int) $lead->id);
    check('event on the lead', DB::table('txn_events')->where('event', 'sms_received')->where('lead_id', $lead->id)->exists());

    // 4. Twilio retries: kept once.
    postText(text('SMcheck1', '+12025550142', 'Tuesday at 11 works'));
    check('same MessageSid again: one row', DB::table('txn_sms_messages')->where('message_sid', 'SMcheck1')->count() === 1);

    // 5. Unknown number, picture.
    postText(text('SMcheck2', '+12025550199', 'Do you do roofs?', ['NumMedia' => '1', 'MediaUrl0' => 'https://api.twilio.example/media/1', 'MediaContentType0' => 'image/jpeg']));
    $unknown = DB::table('txn_sms_messages')->where('message_sid', 'SMcheck2')->first();
    check('unknown number kept without a contact', $unknown && $unknown->person_id === null && ! $unknown->needs_staff);
    check('picture kept', $unknown && SmsInbox::media($unknown)[0]['type'] === 'image/jpeg');

    // 6. Number on two contacts: needs staff, no guess.
    foreach (['SMS Check Shared A' => '202-555-0177', 'SMS Check Shared B' => '+1 202 555 0177'] as $name => $number) {
        app(PersonRepository::class)->create(['entity_type' => 'persons', 'name' => $name, 'emails' => [['label' => 'work', 'value' => strtolower(str_replace(' ', '', $name)).'@test.invalid']], 'contact_numbers' => [['label' => 'mobile', 'value' => $number]], 'user_id' => $adminId]);
    }
    postText(text('SMcheck3', '+12025550177', 'I need to move my visit'));
    $shared = DB::table('txn_sms_messages')->where('message_sid', 'SMcheck3')->first();
    check('shared number: needs staff, no contact picked', $shared && $shared->needs_staff && $shared->person_id === null);

    // 7. Texts Krayin sends are kept in the thread.
    Http::swap(new Factory());
    Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SMcheck-out', 'from' => '+12025550100', 'status' => 'queued'], 201)]);
    $sent = app(SmsSender::class)->send($person->fresh(), 'Hi, see you Tuesday at 11.', $lead);
    $out = DB::table('txn_sms_messages')->where('message_sid', 'SMcheck-out')->first();
    check('send still works', $sent['status'] === 'sent', json_encode($sent));
    check('sent text kept as outgoing on the lead', $out && $out->direction === 'out' && (int) $out->lead_id === (int) $lead->id && $out->customer_number === '+12025550142');

    // 8. Conversations, unread, read.
    $inbox = app(SmsInbox::class);
    $conversation = $inbox->conversations()->firstWhere('customer_number', '+12025550142');
    check('one conversation per number, with the contact', $conversation && $conversation->name === 'SMS Check Customer' && $inbox->thread('+12025550142')->count() === 2);
    check('incoming text counts as unread', $conversation && $conversation->unread === 1);
    $inbox->markRead('+12025550142');
    check('opening marks it read', $inbox->conversations()->firstWhere('customer_number', '+12025550142')->unread === 0);
    check('shared-number conversation flagged', (bool) $inbox->conversations()->firstWhere('customer_number', '+12025550177')?->needs_staff);

    // 9. Demo: creates, matches, removes cleanly.
    DB::table('txn_sms_messages')->whereIn('customer_number', ['+12025550142', '+12025550177', '+12025550199'])->delete();
    DB::table('persons')->where('name', 'like', 'SMS Check%')->delete();
    $demo = app(DemoSms::class);
    $made = $demo->create();
    check('demo: lead, lead, unknown, needs staff', array_values($made['results']) === ['lead', 'lead', 'unknown', 'needs_staff'], json_encode($made['results']));
    check('demo: booking text in the thread', DB::table('txn_sms_messages')->where('message_sid', 'SMdemo-booking-link')->where('lead_id', $made['lead_id'])->exists());
    $demo->remove();
    check('demo: removed completely', ! DB::table('txn_sms_messages')->where('message_sid', 'like', 'SMdemo-%')->exists() && ! DB::table('persons')->where('name', 'like', 'Demo SMS %')->exists() && ! DB::table('leads')->where('id', $made['lead_id'])->exists());
} catch (Throwable $exception) {
    check('no unexpected error', false, get_class($exception).': '.$exception->getMessage().' @ '.basename($exception->getFile()).':'.$exception->getLine());
} finally {
    DB::rollBack();
    Http::swap(new Factory());
}

echo $failures === 0 ? 'All checks passed.'.PHP_EOL : "{$failures} check(s) failed.".PHP_EOL;
exit($failures);
