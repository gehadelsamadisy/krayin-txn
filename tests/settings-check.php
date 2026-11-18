<?php

/*
 * TXN Settings check (Settings > TXN Settings): the five screens open, valid
 * changes reach the code that uses them, bad input is refused, every change
 * is logged, reset works, and with nothing saved the emails read exactly as
 * before the settings existed.
 *
 * Run inside the Krayin container, after the migrations:
 *   php tests/settings-check.php
 *
 * One database transaction, rolled back at the end; outgoing HTTP faked
 * (Geocodio answers with a Houston address); mail to the array mailer; cache
 * in memory only. Exit code = number of failed checks.
 */

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Webkul\Admin\Support\Contact\BookingEmail;
use Webkul\Admin\Support\Contact\LeadTriageService;
use Webkul\Admin\Support\Contact\TxnSettings;
use Webkul\Contact\Repositories\PersonRepository;
use Webkul\Lead\Repositories\LeadRepository;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->bootstrap();
$app->instance('request', Request::create('/admin'));

config([
    'mail.default' => 'array',
    'queue.default' => 'sync',
    'cache.default' => 'array',
    'slot_finder.geocodio_api_key' => 'check-key',
]);

Http::fake([
    'api.geocod.io/*' => Http::response(['results' => [[
        'location' => ['lat' => 29.7604, 'lng' => -95.3698],
        'accuracy_type' => 'rooftop',
    ]]]),
    // Connections > Test Twilio: a fake account and Messaging Service (nothing is sent).
    'api.twilio.com/*' => Http::response(['friendly_name' => 'Check Account', 'type' => 'Full', 'status' => 'active']),
    'messaging.twilio.com/*' => Http::response(['friendly_name' => 'Check Service']),
    '*' => Http::response([], 500),
]);

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

// Visible text of an HTML email, whitespace collapsed.
$visible = fn (string $html) => trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags(preg_replace('/<br\s*\/?>/i', "\n", $html)), ENT_QUOTES)));
$squash = fn (string $text) => trim(preg_replace('/\s+/', ' ', $text));

DB::beginTransaction();

try {
    $adminId = DB::table('users')->join('roles', 'roles.id', '=', 'users.role_id')
        ->where('roles.permission_type', 'all')->where('users.status', 1)->value('users.id');
    auth()->guard('user')->setUser(Webkul\User\Models\User::find($adminId));

    // Nothing saved: every check starts from the defaults.
    DB::table('txn_settings')->delete();
    TxnSettings::apply();

    // A fixed sender, so the expected emails are known.
    config([
        'lead_messages.company' => 'TXN Home Services',
        'lead_messages.rep_name' => 'Sam Rep',
        'lead_messages.rep_cell' => '(713) 555-0100',
        'lead_messages.office_phone' => '(713) 555-0199',
    ]);

    $mail = app(BookingEmail::class);
    $sample = function (string $kind, array $times, ?string $url) use ($mail) {
        $data = [
            'kind' => $kind, 'firstName' => 'Jane', 'service' => 'gutter', 'times' => $times,
            'timezoneLabel' => 'Central Time', 'url' => $url, 'sender' => config('lead_messages'),
        ];

        return $data + $mail->texts($kind, $data) + ['parts' => $mail->sharedParts($data)];
    };
    $render = fn (array $data) => [
        view('admin::emails.booking-link-text', $data)->render(),
        view('admin::emails.booking-link', $data)->render(),
    ];
    $times = ['Tuesday, Mar 2 at 9:00 AM', 'Tuesday, Mar 2 at 1:00 PM'];
    $url = 'https://cal.com/example';

    // =====================================================================
    $section = 'With nothing saved, the emails read exactly as before';
    // =====================================================================
    // Today's wording, as BookingEmail wrote it before the templates.
    $me = 'This is Sam Rep with TXN Home Services';
    $callText = 'call or text us at (713) 555-0100';
    $closing = "Times are in Central Time. The booking page also asks for any details we still need. The link can be used once; after you book you'll get a confirmation email with options to reschedule or cancel.";
    $signature = "Sam Rep\nTXN Home Services\nCell: (713) 555-0100\nOffice: (713) 555-0199";
    $expected = [
        'link' => ['Choose a time for your free estimate | TXN Home Services', "{$me}. Thank you for reaching out about your gutter project. We have these times open for your free, no-obligation estimate:", $closing, $times, $url],
        'reminder' => ['Still looking for a time for your free estimate? | TXN Home Services', "{$me}, following up on your gutter request. We'd still be happy to schedule your free, no-obligation estimate. These times are open:", $closing, $times, $url],
        'reminder (no times)' => ['Still looking for a time for your free estimate? | TXN Home Services', "{$me}, following up on your gutter request. We'd still be happy to schedule your free, no-obligation estimate; just {$callText} and we'll find a time that works for you.", '', [], null],
        'final' => ['Checking in about your estimate | TXN Home Services', "{$me}, checking in one last time about your gutter request. If you still need help, just {$callText} and we'll get your free, no-obligation estimate scheduled.", '', [], null],
    ];

    foreach ($expected as $label => [$subject, $intro, $close, $slots, $link]) {
        $kind = explode(' ', $label)[0];
        $data = $sample($kind, $slots, $link);
        [$text, $html] = $render($data);

        $wantText = "Hi Jane,\n\n{$intro}"
            .($slots ? "\n\n".implode("\n", array_map(fn ($t) => "- {$t}", $slots))."\n\nChoose your time here:\n{$link}" : '')
            .($close !== '' ? "\n\n{$close}" : '')
            ."\n\n{$signature}";

        $check("{$label}: subject unchanged", BookingEmail::plain($data['subject']) === $subject, BookingEmail::plain($data['subject']));
        $check("{$label}: plain-text email unchanged", $squash($text) === $squash($wantText), "got: ".mb_substr($squash($text), 0, 300));
        $check("{$label}: HTML email shows the same words", str_contains($visible($html), $squash("Hi Jane, {$intro}")) && str_contains($visible($html), $squash(str_replace("\n", ' ', $signature))) && ($close === '' || str_contains($visible($html), $squash($close))), mb_substr($visible($html), 0, 300));
        $check("{$label}: \"free, no-obligation estimate\" in bold", str_contains($html, '<strong>free, no-obligation estimate</strong>'));
        $check("{$label}: phone numbers tap-to-call", str_contains($html, 'href="tel:7135550100"') && str_contains($html, 'href="tel:7135550199"'));

        if ($slots) {
            $check("{$label}: button \"Choose your time\" and the fallback link", str_contains($html, '>Choose your time</a>') && str_contains($visible($html), "If the button doesn't work, copy this link into your browser: {$link}"));
        }
    }

    // =====================================================================
    $section = 'Screens';
    // =====================================================================
    foreach (TxnSettings::SCREENS as $screen => [$title]) {
        $page = $call('GET', route('admin.settings.txn.'.str_replace('-', '_', $screen), [], false));
        $check("{$title} opens", $page->getStatusCode() === 200 && str_contains((string) $page->getContent(), e($title)), 'HTTP '.$page->getStatusCode());
    }

    $logCount = fn () => DB::table('txn_settings_log')->where('area', 'like', 'settings:%')->count();
    $companyForm = fn (array $change = []) => array_merge([
        'company' => config('lead_messages.company'), 'rep_name' => config('lead_messages.rep_name'),
        'rep_cell' => config('lead_messages.rep_cell'), 'office_phone' => config('lead_messages.office_phone'),
        'accent' => config('lead_messages.accent'),
    ], $change);

    // =====================================================================
    $section = 'Company & sender';
    // =====================================================================
    $before = $logCount();
    $call('PUT', route('admin.settings.txn.company.update', [], false), $companyForm(['rep_name' => 'Pat Smith']));
    $check('Rep name saved and used at once', config('lead_messages.rep_name') === 'Pat Smith' && TxnSettings::isSaved('lead_messages.rep_name'));
    $logged = DB::table('txn_settings_log')->where('area', 'settings:company')->where('item', 'Rep name')->orderByDesc('id')->first();
    $check('... logged with the user and old -> new', $logCount() > $before && $logged && (int) $logged->user_id === (int) $adminId && $logged->old_value === 'Sam Rep' && $logged->new_value === 'Pat Smith', json_encode($logged));
    $check('... and it reaches the emails ("This is Pat Smith ...")', str_contains($sample('link', $times, $url)['intro'], 'This is Pat Smith with'));

    $call('PUT', route('admin.settings.txn.company.update', [], false), $companyForm(['company' => '', 'accent' => 'blue']));
    $check('Empty company name and a bad colour -> refused, nothing saved', config('lead_messages.company') === 'TXN Home Services' && ! TxnSettings::isSaved('lead_messages.company') && ! TxnSettings::isSaved('lead_messages.accent'));

    $call('PUT', route('admin.settings.txn.company.update', [], false), ['reset' => 'lead_messages.rep_name']);
    $check('Reset to default removes the saved value', ! TxnSettings::isSaved('lead_messages.rep_name') && config('lead_messages.rep_name') === TxnSettings::default('lead_messages.rep_name'));
    config(['lead_messages.rep_name' => 'Sam Rep']); // the sample sender again, for the email checks below

    // =====================================================================
    $section = 'Services';
    // =====================================================================
    $rows = collect(config('lead_triage.services'))->values()->map(fn ($rule, $i) => [
        'order' => $i + 2, 'keywords' => implode(', ', (array) $rule['match']), 'category' => $rule['category'], 'flow' => $rule['flow'], 'line' => '',
    ])->all();
    $rows[] = ['order' => 1, 'keywords' => 'pool, spa', 'category' => 'Pool', 'flow' => 'standard', 'line' => 'We will check the pool.'];
    $call('PUT', route('admin.settings.txn.services.update', [], false), ['rows' => $rows, 'default_line' => 'Default line.']);
    $services = config('lead_triage.services');
    $check('New service saved first in the list (order 1)', ($services[0]['category'] ?? null) === 'Pool' && $services[0]['match'] === ['pool', 'spa'], json_encode($services[0] ?? null));
    $check('Its confirmation line saved', (config('lead_messages.service_lines')['Pool'] ?? null) === 'We will check the pool.');

    $person = app(PersonRepository::class)->create([
        'entity_type' => 'persons', 'name' => 'Settings Check', 'user_id' => $adminId,
        'emails' => [['label' => 'work', 'value' => 'settings.check@txn-check.example']],
        'contact_numbers' => [['label' => 'work', 'value' => '713-555-0142']],
        'csv_address' => '100 Main St', 'csv_city' => 'Houston', 'csv_state_province' => 'TX', 'csv_postal_code' => '77002',
    ]);
    $pipeline = DB::table('lead_pipelines')->where('is_default', 1)->value('id');
    $lead = app(LeadRepository::class)->create([
        'entity_type' => 'leads', 'title' => 'Pool cleaning - Settings Check', 'lead_value' => 0, 'status' => 1,
        'lead_source_id' => config('lead_triage.import.source_id'), 'lead_type_id' => config('lead_triage.import.type_id'),
        'user_id' => $adminId, 'lead_pipeline_id' => $pipeline,
        'lead_pipeline_stage_id' => DB::table('lead_pipeline_stages')->where('lead_pipeline_id', $pipeline)->orderBy('sort_order')->value('id'),
        'person' => ['id' => $person->id],
    ]);
    $result = app(LeadTriageService::class)->check($lead);
    $check('The lead check uses the new keyword ("Pool cleaning" -> Pool)', $result['category'] === 'Pool', $result['category']);

    $call('PUT', route('admin.settings.txn.services.update', [], false), ['rows' => [['order' => 1, 'keywords' => '', 'category' => 'Nothing', 'flow' => 'standard']], 'default_line' => 'x']);
    $check('A service without keywords -> refused', (config('lead_triage.services')[0]['category'] ?? null) === 'Pool');

    // =====================================================================
    $section = 'Service area & scheduling';
    // =====================================================================
    $area = config('slot_finder.service_area');
    $areaForm = fn (array $change = []) => array_merge([
        'area_address' => $area['address'] ?? '', 'area_lat' => $area['center_lat'], 'area_lon' => $area['center_lon'], 'area_miles' => $area['miles'],
        'out_of_area_message' => config('lead_triage.out_of_area_message'),
        'slot_times' => implode(', ', config('slot_finder.slot_times')),
        'slots_to_offer' => config('slot_finder.slots_to_offer'), 'search_days' => config('slot_finder.search_days'),
        'min_notice_minutes' => config('slot_finder.min_notice_minutes'), 'visit_minutes' => config('slot_finder.visit_minutes'),
        'max_slots_per_day' => config('slot_finder.max_slots_per_day'), 'max_extra_drive_minutes' => config('slot_finder.max_extra_drive_minutes'),
    ], $change);

    $call('PUT', route('admin.settings.txn.area.update', [], false), $areaForm(['slot_times' => '8:00, 10:30, 14:00', 'area_miles' => 60]));
    $check('Slot times saved, cleaned and sorted', config('slot_finder.slot_times') === ['08:00', '10:30', '14:00'], json_encode(config('slot_finder.slot_times')));
    $check('Radius saved and used by the distance check', (int) config('slot_finder.service_area.miles') === 60);

    $call('PUT', route('admin.settings.txn.area.update', [], false), $areaForm(['slot_times' => '25:00', 'slots_to_offer' => 0]));
    $check('A bad time and 0 times to offer -> refused, nothing changed', config('slot_finder.slot_times') === ['08:00', '10:30', '14:00'] && (int) config('slot_finder.slots_to_offer') === (int) TxnSettings::default('slot_finder.slots_to_offer'));

    $call('PUT', route('admin.settings.txn.area.update', [], false), $areaForm(['area_address' => 'Katy, TX']));
    $check('A new centre address is looked up and stored', config('slot_finder.service_area.address') === 'Katy, TX' && (float) config('slot_finder.service_area.center_lat') === 29.7604);

    // =====================================================================
    $section = 'Follow-ups';
    // =====================================================================
    $followForm = fn (array $change = []) => array_merge([
        'reminder_after_hours' => 24, 'final_after_hours' => 72, 'lost_after_hours' => 96, 'min_hours_before_slot' => 3, 'lost_reason' => 'No reply to follow-ups',
    ], $change);
    $call('PUT', route('admin.settings.txn.follow_ups.update', [], false), $followForm(['reminder_after_hours' => 12]));
    $check('Reminder hours saved', (int) config('follow_ups.reminder_after_hours') === 12);
    $call('PUT', route('admin.settings.txn.follow_ups.update', [], false), $followForm(['reminder_after_hours' => 80]));
    $check('Reminder after the final email -> refused', (int) config('follow_ups.reminder_after_hours') === 12);

    // =====================================================================
    $section = 'Emails';
    // =====================================================================
    $parts = [];
    foreach (TxnSettings::EMAIL_PARTS as $group => [, $groupParts]) {
        foreach (array_keys($groupParts) as $part) {
            $parts[$group][$part] = config("lead_messages.templates.{$group}.{$part}");
        }
    }
    $emailsUrl = route('admin.settings.txn.emails.update', [], false);

    $changed = $parts;
    $changed['shared']['greeting'] = 'Hello {first_name}!';
    $changed['shared']['button'] = 'Book my visit';
    $changed['shared']['signature'] = "**{rep_name}**\nTeam {company}";
    $changed['link']['subject'] = 'Your times, {first_name}';
    $call('PUT', $emailsUrl, ['parts' => $changed]);
    $data = $sample('link', $times, $url);
    [$text, $html] = $render($data);
    $check('New greeting in both versions', str_contains($text, 'Hello Jane!') && str_contains($html, 'Hello Jane!'));
    $check('New button label in the HTML email', str_contains($html, '>Book my visit</a>'));
    $check('New signature, bold name in HTML, plain in text', str_contains($html, '<strong>Sam Rep</strong><br />') && str_contains($text, "Sam Rep\nTeam TXN Home Services") && ! str_contains($text, '**'));
    $check('New subject with placeholder filled', BookingEmail::plain($data['subject']) === 'Your times, Jane');

    $empty = $changed;
    $empty['shared']['greeting'] = '';
    $call('PUT', $emailsUrl, ['parts' => $empty]);
    [$text, $html] = $render($sample('link', $times, $url));
    $check('An emptied greeting is left out of the email', ! str_contains($text, 'Hello') && str_starts_with(trim($text), 'This is Sam Rep'), mb_substr(trim($text), 0, 60));

    $bad = $empty;
    $bad['link']['intro'] = 'Hi {customer_name}';
    $call('PUT', $emailsUrl, ['parts' => $bad]);
    $check('An unknown placeholder -> refused', ! str_contains((string) config('lead_messages.templates.link.intro'), '{customer_name}'));

    $bad = $empty;
    $bad['link']['subject'] = '';
    $call('PUT', $emailsUrl, ['parts' => $bad]);
    $check('An empty subject -> refused', config('lead_messages.templates.link.subject') === 'Your times, {first_name}');

    $call('PUT', $emailsUrl, ['reset' => 'emails:shared']);
    $check('"Reset this email" puts the shared parts back', config('lead_messages.templates.shared.greeting') === 'Hi {first_name},' && config('lead_messages.templates.shared.button') === 'Choose your time');

    $call('PUT', $emailsUrl, ['reset' => 'all']);
    [$text] = $render($sample('final', [], null));
    $check('"Reset all emails" -> today\'s final email again', str_contains($squash($text), $squash($expected['final'][1])));

    // =====================================================================
    $section = 'Stored values are checked again at start-up';
    // =====================================================================
    DB::table('txn_settings')->updateOrInsert(['key' => 'slot_finder.slot_times'], ['value' => json_encode(['99:99']), 'created_at' => now(), 'updated_at' => now()]);
    TxnSettings::apply();
    $check('An invalid stored value is ignored (the default applies)', config('slot_finder.slot_times') === TxnSettings::default('slot_finder.slot_times'), json_encode(config('slot_finder.slot_times')));

    // =====================================================================
    $section = 'Connections (keys, locked until "Edit keys")';
    // =====================================================================
    $connUrl = route('admin.settings.txn.connections.update', [], false);
    $testUrl = route('admin.settings.txn.connections.test', [], false);
    $name = fn (string $key) => str_replace('.', '__', $key);
    $token = 'chk'.bin2hex(random_bytes(16)); // a fake Twilio token, never real
    $sid = 'AC'.str_repeat('a1', 16);
    $service = 'MG'.str_repeat('b2', 16);

    // The form as the page sends it: plain fields as they are, secrets empty (= keep).
    $connForm = function (array $change = [], bool $unlocked = true) use ($name) {
        $form = ['unlocked' => $unlocked ? '1' : '0'];
        foreach (TxnSettings::keys('connections') as $key) {
            $form[$name($key)] = TxnSettings::isSecret($key) ? '' : (string) ($key === 'mail.default' ? TxnSettings::default($key) : config($key));
        }

        return array_merge($form, $change);
    };
    $rawToken = fn () => (string) DB::table('txn_settings')->where('key', 'sms.auth_token')->value('value');
    $envToken = TxnSettings::default('sms.auth_token');

    $page = $call('GET', route('admin.settings.txn.connections', [], false));
    $check('Connections opens, locked ("Edit keys")', $page->getStatusCode() === 200 && str_contains((string) $page->getContent(), 'Edit keys'));

    $call('PUT', $connUrl, $connForm([$name('sms.auth_token') => $token], false));
    $check('Save without unlocking -> refused, nothing saved', ! TxnSettings::isSaved('sms.auth_token'));

    $call('PUT', $connUrl, $connForm([$name('sms.auth_token') => $token, $name('sms.account_sid') => $sid, $name('sms.messaging_service_sid') => $service]));
    $check('Unlocked save: token used at once', config('sms.auth_token') === $token && config('sms.account_sid') === $sid);
    $check('... stored encrypted (not the plain token)', $rawToken() !== '' && ! str_contains($rawToken(), $token));
    $logged = DB::table('txn_settings_log')->where('area', 'settings:connections')->where('item', 'Twilio Auth token')->orderByDesc('id')->first();
    $check('... logged as "ends …" only', $logged && str_contains($logged->new_value, 'ends …'.substr($token, -4)) && ! str_contains($logged->new_value.$logged->old_value, $token), json_encode($logged));

    $html = (string) $call('GET', route('admin.settings.txn.connections', [], false))->getContent();
    $check('The page shows "Saved in Krayin · ends …" and never the token', str_contains($html, 'Saved in Krayin') && str_contains($html, '…'.substr($token, -4)) && ! str_contains($html, $token));
    $check('... nor the .env token', ! filled($envToken) || ! str_contains($html, (string) $envToken));

    $call('PUT', $connUrl, $connForm());
    $check('An empty secret keeps the saved one', config('sms.auth_token') === $token && TxnSettings::isSaved('sms.auth_token'));

    config(['sms.auth_token' => 'something-else']);
    TxnSettings::apply();
    $check('apply() decrypts the saved token again', config('sms.auth_token') === $token);

    $before = $rawToken();
    $call('PUT', $connUrl, $connForm([$name('sms.account_sid') => 'XX123']));
    $check('A bad Account SID -> refused, nothing changed', config('sms.account_sid') === $sid && $rawToken() === $before);
    $check('... and the typed token is not kept in the session', ! str_contains(json_encode(session()->all()), $token));

    // Test buttons
    $result = $call('POST', $testUrl, $connForm(['service' => 'twilio']));
    $json = json_decode((string) $result->getContent(), true);
    $check('Test Twilio (faked): ok, account and service found, no SMS', ($json['ok'] ?? false) === true && str_contains($json['message'] ?? '', 'Check Account') && str_contains($json['message'] ?? '', 'No SMS was sent'), (string) $result->getContent());
    $check('... the test message never contains the token', ! str_contains((string) $result->getContent(), $token));
    $check('... and no message was posted to Twilio', Http::recorded()->every(fn ($pair) => $pair[0]->method() === 'GET' || ! str_contains($pair[0]->url(), 'twilio.com')));
    $locked = $call('POST', $testUrl, $connForm(['service' => 'twilio'], false));
    $check('Test without unlocking -> refused (403)', $locked->getStatusCode() === 403);

    // A saved key that can't be decrypted (APP_KEY changed) -> .env applies.
    DB::table('txn_settings')->where('key', 'sms.auth_token')->update(['value' => json_encode('not-a-valid-encrypted-value')]);
    TxnSettings::apply();
    $check('Unreadable saved token -> the .env value is used, the screen warns', config('sms.auth_token') == $envToken && TxnSettings::secretStatus('sms.auth_token')['unreadable'] === true);

    $call('PUT', $connUrl, $connForm([$name('sms.auth_token') => $token]));
    $call('PUT', $connUrl, $connForm(['reset' => 'sms.auth_token']));
    $check('"Use .env value" deletes the saved token', ! TxnSettings::isSaved('sms.auth_token') && config('sms.auth_token') == $envToken);

    $mailer = config('mail.default');
    $call('PUT', $connUrl, $connForm([$name('mail.default') => 'smtp']));
    $check('A check script\'s array mailer is never replaced by a save', config('mail.default') === $mailer, (string) config('mail.default'));

    if (! filled(config('graph_mail.client_secret'))) {
        $call('PUT', $connUrl, $connForm([$name('mail.default') => 'graph', $name('graph_mail.tenant_id') => '', $name('graph_mail.client_id') => '']));
        $check('Mailer Microsoft 365 without its login -> refused', ! TxnSettings::isSaved('mail.default') || DB::table('txn_settings')->where('key', 'mail.default')->value('value') !== json_encode('graph'));
    }

    $check('No call left this machine except the faked lookups', Http::recorded()->every(fn ($pair) => preg_match('/geocod\.io|twilio\.com/', $pair[0]->url())), Http::recorded()->count().' requests');
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
