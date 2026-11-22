<?php

/*
 * Yelp messages check (POST /txn/yelp-message, YelpMessageStore, Leads > Yelp
 * messages, the lead card, the Connections test, the Automation switch).
 *
 * Run inside the Krayin container, after the migrations:
 *   php tests/yelp-message-check.php
 *
 * The "msg_db" connection is pointed at Krayin's own database with a
 * TEMPORARY yelp_message table (same columns as docs/sql/yelp_message.sql),
 * so the real lead database is never touched. Everything else runs in one
 * transaction, rolled back at the end. Exit code = number of failed checks.
 */

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Webkul\Admin\Support\Contact\TxnConnectionTester;
use Webkul\Admin\Support\Contact\YelpMessageStore;
use Webkul\Contact\Repositories\PersonRepository;
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

$token = 'yelp-check-token-'.bin2hex(random_bytes(6));

// POST /txn/yelp-message as Zapier sends it (JSON + X-TXN-Token). Returns [status, json].
$post = function (array $payload, ?string $withToken = null) use ($kernel, &$token) {
    $request = Request::create('/txn/yelp-message', 'POST', [], [], [], array_filter([
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_TXN_TOKEN' => $withToken ?? $token,
    ]), json_encode($payload));
    app()->instance('request', $request);
    $response = $kernel->handle($request);
    $kernel->terminate($request, $response);

    return [$response->getStatusCode(), json_decode((string) $response->getContent(), true)];
};
$page = function (string $route, array $params = []) use ($kernel) {
    $request = Request::create(route($route, $params, false), 'GET');
    app()->instance('request', $request);

    return (string) $kernel->handle($request)->getContent();
};
$msgs = fn () => DB::connection('msg_db')->table('yelp_message');
$events = fn (string $event) => DB::table('txn_events')->where('event', $event)->orderByDesc('id')->get();

// msg_db = Krayin's own database (same login), with a temporary table that
// exists only on that connection and disappears when the script ends. Set up
// after the test lead is created, so nothing run by that (queued listeners
// re-reading the settings) resets the connection and loses the table.
$main = config('database.connections.'.config('database.default'));
$useTestTable = function () use ($main) {
    config([
        'lead_triage.lead_db.host' => $main['host'], 'lead_triage.lead_db.port' => (string) $main['port'],
        'lead_triage.lead_db.database' => $main['database'],
        'lead_triage.lead_db.username' => '', 'lead_triage.lead_db.password' => '', // no lead database: no "Reply in Yelp" lookup
        'lead_triage.msg_db.username' => $main['username'], 'lead_triage.msg_db.password' => $main['password'],
    ]);
    config(['database.connections.msg_db' => YelpMessageStore::connectionConfig()]);
    DB::purge('msg_db');
    DB::connection('msg_db')->statement('DROP TEMPORARY TABLE IF EXISTS yelp_message');
    DB::connection('msg_db')->statement(<<<'SQL'
        CREATE TEMPORARY TABLE yelp_message (
          id BIGINT AUTO_INCREMENT PRIMARY KEY,
          source_lead_id VARCHAR(255) NOT NULL,
          event_id VARCHAR(255) NOT NULL UNIQUE,
          user_type VARCHAR(32), user_name VARCHAR(255), text TEXT,
          attachment_url VARCHAR(1024), attachment_text TEXT,
          sent_at DATETIME, read_at DATETIME NULL,
          raw_payload JSON NOT NULL,
          received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          KEY (source_lead_id)
        )
        SQL);
};

DB::beginTransaction();

try {
    config(['queue.default' => 'sync', 'cache.default' => 'array', 'automation.yelp.enabled' => false, 'automation.yelp.token' => $token]);

    $adminId = DB::table('users')->join('roles', 'roles.id', '=', 'users.role_id')
        ->where('roles.permission_type', 'all')->where('users.status', 1)->value('users.id');
    auth()->guard('user')->setUser(Webkul\User\Models\User::find($adminId));

    $person = app(PersonRepository::class)->create([
        'entity_type' => 'persons', 'name' => 'Yelp Check Customer',
        'emails' => [['label' => 'work', 'value' => 'yelp.check@txn-check.example']],
        'contact_numbers' => [['label' => 'work', 'value' => '713-555-0198']],
        'user_id' => $adminId,
    ]);
    $import = config('lead_triage.import');
    $pipeline = (int) DB::table('lead_pipelines')->where('is_default', 1)->value('id');
    $lead = app(LeadRepository::class)->create([
        'entity_type' => 'leads', 'title' => 'Roof leak - Yelp Check Customer [TXN-YELP-ychk-1]', 'lead_value' => 0, 'status' => 1,
        'lead_source_id' => $import['source_id'], 'lead_type_id' => $import['type_id'], 'user_id' => $import['owner_id'],
        'lead_pipeline_id' => $pipeline,
        'lead_pipeline_stage_id' => (int) DB::table('lead_pipeline_stages')->where('lead_pipeline_id', $pipeline)->orderBy('sort_order')->value('id'),
        'person' => ['id' => $person->id],
    ]);

    $useTestTable();

    $customer = [
        'lead_id' => 'ychk-1', 'event_id' => 'ev-1', 'user_type' => 'CONSUMER', 'user' => 'Jane C.',
        'text' => 'Hi, is Tuesday ok? <script>alert(1)</script> {{ 7*7 }}', 'time' => '2026-11-22T10:04:37+00:00',
        'attachment_url' => '', 'attachment_text' => '',
    ];

    // =====================================================================
    $section = 'Webhook: switch and token';
    // =====================================================================
    [$status] = $post($customer);
    $check('Switch off (as shipped) -> 404, nothing saved', $status === 404 && $msgs()->count() === 0, (string) $status);

    config(['automation.yelp.enabled' => true]);
    [$status] = $post($customer, 'wrong-token-wrong-token');
    $check('Wrong X-TXN-Token -> 401', $status === 401, (string) $status);
    config(['automation.yelp.token' => '']);
    [$status] = $post($customer, '');
    $check('No token set in Krayin -> every request refused (401)', $status === 401, (string) $status);
    config(['automation.yelp.token' => $token]);

    [$status, $body] = $post(['lead_id' => 'ychk-1', 'event_id' => 'ev-x']);
    $check('No text and no attachment -> 422', $status === 422 && isset($body['errors']['text']), json_encode($body));
    [$status, $body] = $post(['text' => 'hello']);
    $check('No lead id / event id -> 422 naming both', $status === 422 && isset($body['errors']['lead_id'], $body['errors']['event_id']), json_encode($body));

    // =====================================================================
    $section = 'Webhook: saving messages';
    // =====================================================================
    [$status, $body] = $post($customer);
    $row = $msgs()->where('event_id', 'ev-1')->first();
    $check('Customer message -> 200 "stored"', $status === 200 && ($body['message'] ?? '') === 'stored', json_encode($body));
    $check('... every field saved (lead id, sender, name, text, time in UTC, raw payload)', $row
        && $row->source_lead_id === 'ychk-1' && $row->user_type === 'CONSUMER' && $row->user_name === 'Jane C.'
        && str_starts_with($row->text, 'Hi, is Tuesday ok?') && $row->sent_at === '2026-11-22 10:04:37'
        && $row->attachment_url === null && $row->read_at === null && json_decode($row->raw_payload, true)['event_id'] === 'ev-1', json_encode($row));
    $event = $events('yelp_message_received')->first();
    $check('... "Yelp message received" in the event log, on the matching Krayin lead', $event && (int) $event->lead_id === (int) $lead->id, json_encode($event));

    [$status, $body] = $post($customer);
    $check('Same event_id again -> 200 "duplicate", still one row', $status === 200 && ($body['message'] ?? '') === 'duplicate' && $msgs()->where('event_id', 'ev-1')->count() === 1, json_encode($body));

    [$status] = $post(['lead_id' => 'ychk-1', 'event_id' => 'ev-2', 'user_type' => 'BIZ', 'user' => 'TXN Roofing', 'text' => 'Yes, Tuesday 11 AM works.', 'time' => '2026-11-22T10:10:00Z']);
    $check('Our own message (business user_type) -> saved, logged as "sent by us"', $status === 200
        && YelpMessageStore::fromBusiness($msgs()->where('event_id', 'ev-2')->value('user_type'))
        && $events('yelp_message_sent')->isNotEmpty());

    [$status] = $post(['lead_id' => 'ychk-1', 'event_id' => 'ev-3', 'user_type' => 'CONSUMER', 'time' => '1795342500',
        'attachement_url' => 'https://s3-media0.fl.yelpcdn.com/photo/roof.jpg', 'attachemetn_text' => 'roof.jpg']);
    $photo = $msgs()->where('event_id', 'ev-3')->first();
    $check('Photo only, misspelled attachement_* keys, unix time -> saved with the attachment', $status === 200 && $photo
        && $photo->attachment_url === 'https://s3-media0.fl.yelpcdn.com/photo/roof.jpg' && $photo->attachment_text === 'roof.jpg' && $photo->text === null && $photo->sent_at !== null, json_encode($photo));

    [$status] = $post(['lead_id' => 'ychk-unknown', 'event_id' => 'ev-4', 'user_type' => 'CONSUMER', 'user' => 'Mark S.', 'text' => 'Do you do gutters?']);
    $unknownEvent = $events('yelp_message_received')->first();
    $check('Message for a Yelp lead not in Krayin -> still saved, logged without a lead', $status === 200
        && $msgs()->where('source_lead_id', 'ychk-unknown')->exists() && $unknownEvent && $unknownEvent->lead_id === null);

    // =====================================================================
    $section = 'Reading';
    // =====================================================================
    $store = app(YelpMessageStore::class);
    $conversations = $store->conversations();
    $mine = $conversations->firstWhere('source_lead_id', 'ychk-1');
    $check('Conversations: one row per Yelp lead, newest first, with the unread customer messages', $conversations->count() === 2
        && $conversations->first()->source_lead_id === 'ychk-unknown' && $mine && (int) $mine->messages === 3 && $mine->unread === 2, json_encode($conversations));
    $check('Thread: oldest first', $store->thread('ychk-1')->pluck('event_id')->all() === ['ev-1', 'ev-2', 'ev-3'],
        implode(',', $store->thread('ychk-1')->pluck('event_id')->all()));
    $check('Unread count (badge) = customer messages not opened yet', $store->unreadCount() === 3, (string) $store->unreadCount());
    $check('Krayin lead found by the [TXN-YELP-…] tag', (int) (YelpMessageStore::leadsFor(['ychk-1', 'ychk-unknown'])['ychk-1']->id ?? 0) === (int) $lead->id
        && ! isset(YelpMessageStore::leadsFor(['ychk-unknown'])['ychk-unknown']));

    // =====================================================================
    $section = 'Screens';
    // =====================================================================
    $html = $page('admin.leads.yelp_messages', ['chat' => 'ychk-1']);
    $check('Leads > Yelp messages opens with both conversations', str_contains($html, 'Yelp Check Customer') && str_contains($html, 'Mark S.'));
    $check('... the chosen chat shows the customer\'s and our messages and the photo', str_contains($html, 'Hi, is Tuesday ok?') && str_contains($html, 'Yes, Tuesday 11 AM works.') && str_contains($html, 'roof.jpg'));
    $check('... customer text is escaped and inside v-pre (no HTML, no Vue template)', ! str_contains($html, '<script>alert(1)</script>') && str_contains($html, '&lt;script&gt;') && str_contains($html, 'v-pre'));
    $check('... a Yelp lead not in Krayin is marked', str_contains($html, 'No lead in Krayin'));
    $check('Opening the chat marks it read', $store->conversations()->firstWhere('source_lead_id', 'ychk-1')->unread === 0 && $store->unreadCount() === 1);

    $leadHtml = $page('admin.leads.view', ['id' => $lead->id]);
    $check('Lead page: "Yelp messages" card with the chat', str_contains($leadHtml, 'Yelp messages') && str_contains($leadHtml, 'Yes, Tuesday 11 AM works.'));
    $check('Leads list: "Yelp messages" button with the unread badge', (bool) preg_match('~Yelp messages\s*<span[^>]*>\s*1\s*</span>~', $page('admin.leads.index')));
    $check('Sidebar: "Yelp messages" item linking to the inbox', str_contains($page('admin.dashboard.index'), route('admin.leads.yelp_messages', [], false)));
    $auto = $page('admin.settings.txn.automation');
    $check('Automation screen: Yelp messages card with the webhook address', str_contains($auto, 'Accept Yelp messages from Zapier') && str_contains($auto, '/txn/yelp-message'));
    $check('Connections screen: Yelp messages database card', str_contains($page('admin.settings.txn.connections'), 'Yelp messages database'));

    // =====================================================================
    $section = 'Connections test and failures';
    // =====================================================================
    $values = ['lead_triage.lead_db.host' => $main['host'], 'lead_triage.lead_db.port' => (string) $main['port'], 'lead_triage.lead_db.database' => $main['database'],
        'lead_triage.msg_db.username' => $main['username'], 'lead_triage.msg_db.password' => $main['password']];
    $result = (new TxnConnectionTester($values))->run('msg_db');
    // The temporary table is on msg_db only; the Test opens its own connection, where the real table is missing here.
    $check('Test button connects with the message login (here: reports the missing table clearly)', ! $result['ok'] && str_contains($result['message'], 'yelp_message'), $result['message']);
    $result = (new TxnConnectionTester(array_merge($values, ['lead_triage.msg_db.username' => ''])))->run('msg_db');
    $check('Test without a login -> "Missing: Messages database login"', ! $result['ok'] && str_contains($result['message'], 'Messages database login'), $result['message']);

    config(['lead_triage.msg_db.password' => '']);
    [$status] = $post(['lead_id' => 'ychk-1', 'event_id' => 'ev-5', 'text' => 'no login']);
    $check('No messages-database login -> 503 (Zap run errors, can be resent) + amber alert', $status === 503
        && collect(\Webkul\Admin\Support\Contact\ServiceHealth::problems())->firstWhere('service', 'msg_db')['state'] === 'no_key', (string) $status);
    config(['lead_triage.msg_db.password' => $main['password']]);

    $saved = config('database.connections.msg_db');
    config(['database.connections.msg_db' => array_merge($saved, ['host' => '127.0.0.1', 'port' => 1])]);
    DB::purge('msg_db');
    [$status] = $post(['lead_id' => 'ychk-1', 'event_id' => 'ev-6', 'text' => 'database down']);
    $check('Messages database unreachable -> 503 + red alert', $status === 503
        && collect(\Webkul\Admin\Support\Contact\ServiceHealth::problems())->firstWhere('service', 'msg_db')['state'] === 'failed', (string) $status);
    config(['database.connections.msg_db' => $saved]);
    DB::purge('msg_db'); // (the temporary table went with the old connection; nothing more is read after this)
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
