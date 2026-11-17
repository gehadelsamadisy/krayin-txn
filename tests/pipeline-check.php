<?php

/*
 * Pipeline check (TXN pipeline v2): the stages in both pipelines and every
 * automatic stage move (lead check, booking link, Cal.com events, follow-ups).
 *
 * Run inside the Krayin container, after the migrations:
 *   php tests/pipeline-check.php
 *
 * Everything runs in one database transaction that is rolled back at the end,
 * outgoing HTTP is faked (Geocodio answers with a Houston address), mail goes
 * to the array mailer and the cache is in memory only, so nothing is kept,
 * sent or cached. Exit code = number of failed checks.
 */

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Webkul\Admin\Support\Contact\LeadStageService;
use Webkul\Admin\Support\Contact\LeadTriageService;
use Webkul\Admin\Support\Contact\StageRoles;
use Webkul\Contact\Repositories\PersonRepository;
use Webkul\Lead\Models\Lead;
use Webkul\Lead\Repositories\LeadRepository;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->bootstrap();
$app->instance('request', Request::create('/admin'));

config([
    'mail.default' => 'array',
    'queue.default' => 'sync',
    'cache.default' => 'array',           // fake map answers never reach the real cache
    'slot_finder.geocodio_api_key' => 'check-key',
]);

// Every address is downtown Houston (in the service area).
Http::fake([
    'api.geocod.io/*' => Http::response(['results' => [[
        'location' => ['lat' => 29.7604, 'lng' => -95.3698],
        'accuracy_type' => 'rooftop',
    ]]]),
    '*' => Http::response([], 500),
]);

// The admin forms post a CSRF token; these checks call the routes directly.
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

DB::beginTransaction();

try {
    // =====================================================================
    // Setup
    // =====================================================================
    $adminId = DB::table('users')->join('roles', 'roles.id', '=', 'users.role_id')
        ->where('roles.permission_type', 'all')->where('users.status', 1)->value('users.id');
    auth()->guard('user')->setUser(Webkul\User\Models\User::find($adminId));

    $defaultId = (int) DB::table('lead_pipelines')->where('is_default', 1)->value('id');
    $roofId = (int) DB::table('lead_pipeline_stages')->where('code', 'waiting-roof-report')->value('lead_pipeline_id');
    $codes = fn (int $pipelineId) => DB::table('lead_pipeline_stages')->where('lead_pipeline_id', $pipelineId)->orderBy('sort_order')->pluck('code')->all();
    $names = fn (int $pipelineId) => DB::table('lead_pipeline_stages')->where('lead_pipeline_id', $pipelineId)->pluck('name', 'code')->all();
    $stageId = fn (int $pipelineId, string $code) => (int) DB::table('lead_pipeline_stages')->where('lead_pipeline_id', $pipelineId)->where('code', $code)->value('id');

    $triage = app(LeadTriageService::class);
    $stages = app(LeadStageService::class);
    $rolesSvc = app(StageRoles::class);
    $leads = app(LeadRepository::class);
    $settings = config('lead_triage.import');
    $tagName = config('lead_triage.review_tag.name');

    $n = 0;
    $makePerson = function (array $extra = []) use ($adminId, &$n) {
        $n++;

        return app(PersonRepository::class)->create(array_merge([
            'entity_type' => 'persons',
            'name' => "Pipeline Check {$n}",
            'emails' => [['label' => 'work', 'value' => "pipeline.check{$n}@txn-check.example"]],
            'contact_numbers' => [['label' => 'work', 'value' => '713-555-01'.str_pad((string) $n, 2, '0', STR_PAD_LEFT)]],
            'user_id' => $adminId,
            'csv_address' => "{$n}00 Main St",
            'csv_city' => 'Houston',
            'csv_state_province' => 'TX',
            'csv_postal_code' => '77002',
        ], $extra));
    };

    // A lead the way the import makes one: default pipeline, Raw Lead. The
    // service is read from the title ("<service> - <name>").
    $makeLead = function (string $service, $person, string $code = 'new', ?int $pipelineId = null) use ($leads, $settings, $defaultId, $stageId) {
        $pipelineId ??= $defaultId;

        return $leads->create([
            'entity_type' => 'leads',
            'title' => "{$service} - {$person->name}",
            'lead_value' => 0,
            'status' => 1,
            'lead_source_id' => $settings['source_id'],
            'lead_type_id' => $settings['type_id'],
            'user_id' => $settings['owner_id'],
            'lead_pipeline_id' => $pipelineId,
            'lead_pipeline_stage_id' => $stageId($pipelineId, $code),
            'person' => ['id' => $person->id],
        ]);
    };

    $at = function ($lead) {
        $row = DB::table('leads')->find($lead->id);
        $stage = DB::table('lead_pipeline_stages')->find($row->lead_pipeline_stage_id);

        return [(int) $row->lead_pipeline_id, $stage->code ?? null];
    };
    $where = fn ($lead) => json_encode($at($lead));
    $fresh = fn ($lead) => Lead::query()->find($lead->id);
    $tagged = fn ($lead) => DB::table('lead_tags')->join('tags', 'tags.id', '=', 'lead_tags.tag_id')
        ->where('lead_tags.lead_id', $lead->id)->where('tags.name', $tagName)->exists();
    $setTitle = function ($lead, string $service) {
        DB::table('leads')->where('id', $lead->id)->update(['title' => "{$service} - Pipeline Check"]);
    };

    // =====================================================================
    $section = 'Stages';
    // =====================================================================
    $mainOrder = ['new', 'needs-review', 'contacted', 'appointment-set', 'appointment-canceled', 'prospect', 'negotiation', 'won', 'lost'];
    $roofOrder = ['new', 'needs-review', 'waiting-roof-report', 'roof-report-received', 'contacted', 'appointment-set', 'appointment-canceled', 'prospect', 'negotiation', 'won', 'lost'];
    $check('Main pipeline: 9 stages in order', array_slice($codes($defaultId), 0, 9) === $mainOrder, json_encode($codes($defaultId)));
    $check('Roof pipeline exists with its 11 stages in order', $roofId > 0 && array_slice($codes($roofId), 0, 11) === $roofOrder, json_encode($roofId ? $codes($roofId) : []));

    $expectedNames = [
        'new' => 'Raw Lead', 'needs-review' => 'Needs Review', 'contacted' => 'Contacted',
        'appointment-set' => 'Appointment Scheduled', 'appointment-canceled' => 'Canceled',
        'prospect' => 'Visit Done', 'negotiation' => 'Estimate Sent', 'won' => 'Won - Job Sold', 'lost' => 'Lost',
    ];
    foreach ([$defaultId => 'Main', $roofId => 'Roof'] as $pipelineId => $label) {
        $actual = array_intersect_key($names($pipelineId), $expectedNames);
        ksort($actual);
        $wanted = $expectedNames;
        ksort($wanted);
        $check("{$label} pipeline: stage names", $actual === $wanted, json_encode($actual));
    }

    // Krayin's board keys its columns by position: a repeated number hides a column.
    $repeats = DB::table('lead_pipeline_stages')->select('lead_pipeline_id', 'sort_order')
        ->groupBy('lead_pipeline_id', 'sort_order')->havingRaw('COUNT(*) > 1')->get();
    $check('Every stage position is unique within its pipeline (no hidden board columns)', $repeats->isEmpty(), $repeats->toJson());

    $removed = DB::table('lead_pipeline_stages')->whereIn('code', ['pre-qualified', 'follow-up', 'appointment-rescheduled', 'appointment-confirmed'])->count();
    $check('Removed stages are gone from every pipeline', $removed === 0, "{$removed} left");
    $orphans = DB::table('leads')->leftJoin('lead_pipeline_stages as s', 's.id', '=', 'leads.lead_pipeline_stage_id')
        ->where(fn ($q) => $q->whereNull('s.id')->orWhereColumn('s.lead_pipeline_id', '!=', 'leads.lead_pipeline_id'))->count();
    $check('Every existing lead sits in a stage of its own pipeline', $orphans === 0, "{$orphans} leads without a valid stage");

    // =====================================================================
    $section = 'Lead check';
    // =====================================================================
    $ok = $makeLead('Gutter repair', $makePerson());
    $result = $triage->check($ok);
    $check('Good gutter lead stays in Raw Lead, "Send booking link"', $at($ok) === [$defaultId, 'new'] && ! $result['review'] && str_starts_with($result['next_action'], 'Send booking link'), $where($ok).' '.$result['next_action']);

    $odd = $makeLead('Pool cleaning', $makePerson());
    $result = $triage->check($odd);
    $check('Unknown service -> Needs Review, tagged', $at($odd) === [$defaultId, 'needs-review'] && $result['review'] && $tagged($odd), $where($odd).' '.$result['next_action']);
    $check('The move is in the lead\'s history', DB::table('lead_activities')->join('activities', 'activities.id', '=', 'lead_activities.activity_id')
        ->where('lead_activities.lead_id', $odd->id)->where('activities.type', 'system')->where('activities.additional', 'like', '%Needs Review%')->exists());

    $setTitle($odd, 'Gutter repair');
    $result = $triage->check($fresh($odd));
    $check('Service fixed + re-check -> back to Raw Lead, tag removed', $at($odd) === [$defaultId, 'new'] && ! $result['review'] && ! $tagged($odd), $where($odd).' '.$result['next_action']);

    $noEmail = $makeLead('Gutter repair', $makePerson(['emails' => []]));
    $triage->check($noEmail);
    $check('No email -> Needs Review', $at($noEmail) === [$defaultId, 'needs-review'], $where($noEmail));

    $roof = $makeLead('Roof replacement', $makePerson());
    $triage->check($roof);
    $check('Roof replacement -> Roof pipeline, Waiting for Roof Report', $at($roof) === [$roofId, 'waiting-roof-report'], $where($roof));

    $setTitle($roof, 'Pool cleaning');
    $triage->check($fresh($roof));
    $check('Roof lead with a problem -> Needs Review inside the roof pipeline', $at($roof) === [$roofId, 'needs-review'], $where($roof));

    $setTitle($roof, 'Roof replacement');
    $triage->check($fresh($roof));
    $check('Fixed again (still roof) -> Waiting for Roof Report', $at($roof) === [$roofId, 'waiting-roof-report'], $where($roof));

    $setTitle($roof, 'Siding repair');
    $triage->check($fresh($roof));
    $check('Service changed away from roof -> main pipeline, Raw Lead', $at($roof) === [$defaultId, 'new'], $where($roof));

    $received = $makeLead('Roof replacement', $makePerson(), 'roof-report-received', $roofId);
    $setTitle($received, 'Pool cleaning');
    $result = $triage->check($fresh($received));
    $check('Problem on a Roof Report Received lead -> stays there, only tagged', $at($received) === [$roofId, 'roof-report-received'] && $result['review'] && $tagged($received), $where($received));

    $roofRaw = $makeLead('Siding repair', $makePerson(), 'new', $roofId);
    $triage->check($roofRaw);
    $check('Raw Lead in the roof pipeline, not roof work -> main pipeline Raw Lead', $at($roofRaw) === [$defaultId, 'new'], $where($roofRaw));

    // =====================================================================
    $section = '"Check new leads" (txn:triage-leads --new, same stages as the button)';
    // =====================================================================
    // Limited to this lead with --lead, so the real leads aren't all re-checked.
    $parked = $makeLead('Gutter repair', $makePerson(), 'needs-review');
    $later = $makeLead('Gutter repair', $makePerson(), 'contacted');
    Illuminate\Support\Facades\Artisan::call('txn:triage-leads', ['--new' => true, '--lead' => [$parked->id, $later->id]]);
    $output = Illuminate\Support\Facades\Artisan::output();
    $check('It re-checks Needs Review too (fixed lead -> Raw Lead)', $at($parked) === [$defaultId, 'new'], $where($parked));
    $check('It skips leads past those stages', str_contains($output, '1 leads') && $at($later) === [$defaultId, 'contacted'], trim(substr($output, -160)));

    // =====================================================================
    $section = 'Booking link, Cal.com events, follow-ups';
    // =====================================================================
    $person = $makePerson();
    $lead = $makeLead('Gutter repair', $person);

    $check('Link sent from Raw Lead -> Contacted', $stages->linkSent($person, $fresh($lead)) === 1 && $at($lead) === [$defaultId, 'contacted'], $where($lead));
    $check('Booked -> Appointment Scheduled', $stages->booked($person, $fresh($lead)) === 1 && $at($lead) === [$defaultId, 'appointment-set'], $where($lead));
    $check('Rescheduled -> stays in Appointment Scheduled (no move)', $stages->rescheduled($person, $fresh($lead)) === 0 && $at($lead) === [$defaultId, 'appointment-set'], $where($lead));
    $check('Cancelled -> Canceled', $stages->canceled($person, $fresh($lead)) === 1 && $at($lead) === [$defaultId, 'appointment-canceled'], $where($lead));
    $check('New link after a cancellation -> Contacted', $stages->linkSent($person, $fresh($lead)) === 1 && $at($lead) === [$defaultId, 'contacted'], $where($lead));
    $check('Follow-ups ran out -> Lost, with the reason', $stages->lost($fresh($lead), 'No reply to follow-ups') && $at($lead) === [$defaultId, 'lost'] && $fresh($lead)->lost_reason === 'No reply to follow-ups', $where($lead));
    $check('A lost lead is never moved by a booking', $stages->booked($person, $fresh($lead)) === 0 && $at($lead) === [$defaultId, 'lost'], $where($lead));

    $review = $makeLead('Gutter repair', $makePerson(), 'needs-review');
    $check('Staff can still send a link to a Needs Review lead -> Contacted', $stages->linkSent($review->person, $fresh($review)) === 1 && $at($review) === [$defaultId, 'contacted'], $where($review));

    $visited = $makeLead('Gutter repair', $makePerson(), 'prospect');
    $check('Link sent to a Visit Done lead -> not moved', $stages->linkSent($visited->person, $fresh($visited)) === 0 && $at($visited) === [$defaultId, 'prospect'], $where($visited));
    $check('Follow-ups never mark a lead Lost unless it is in Contacted', ! $stages->lost($fresh($visited), 'x') && $at($visited) === [$defaultId, 'prospect'], $where($visited));
    $check('Lead check leaves a Visit Done lead where it is', ($triage->check($fresh($visited))['moved'] ?? true) === false && $at($visited) === [$defaultId, 'prospect'], $where($visited));

    // =====================================================================
    $section = 'Stage roles (automation follows roles, not stage names or codes)';
    // =====================================================================
    $roleCount = fn (int $pipelineId, string $role) => DB::table('txn_stage_roles as r')
        ->join('lead_pipeline_stages as s', 's.id', '=', 'r.lead_pipeline_stage_id')
        ->where('s.lead_pipeline_id', $pipelineId)->where('r.role', $role)->count();
    $base = [StageRoles::NEW, StageRoles::NEEDS_REVIEW, StageRoles::CONTACTED, StageRoles::SCHEDULED, StageRoles::CANCELED];
    $check('Main pipeline: each automation role is on exactly one stage', collect($base)->every(fn ($role) => $roleCount($defaultId, $role) === 1));
    $check('Roof pipeline: those roles plus the two roof roles, once each', collect([...$base, StageRoles::WAITING_ROOF_REPORT, StageRoles::ROOF_REPORT_RECEIVED])->every(fn ($role) => $roleCount($roofId, $role) === 1));

    // What Krayin's pipeline editor may do on a rename: new name and new code.
    $contactedId = $stageId($defaultId, 'contacted');
    DB::table('lead_pipeline_stages')->where('id', $contactedId)->update(['code' => 'called-customer', 'name' => 'Called customer']);
    $p1 = $makePerson();
    $l1 = $makeLead('Gutter repair', $p1);
    $check('Renamed and re-coded Contacted stage still receives "link sent"', $stages->linkSent($p1, $fresh($l1)) === 1 && $at($l1) === [$defaultId, 'called-customer'], $where($l1));

    DB::table('txn_stage_roles')->where('lead_pipeline_stage_id', $contactedId)->delete();
    $p2 = $makePerson();
    $l2 = $makeLead('Gutter repair', $p2);
    $check('No stage with the Contacted role -> "link sent" moves nothing', $stages->linkSent($p2, $fresh($l2)) === 0 && $at($l2) === [$defaultId, 'new'], $where($l2));

    $customId = DB::table('lead_pipeline_stages')->insertGetId(['code' => 'called-once', 'name' => 'Called once', 'probability' => 100, 'sort_order' => 98, 'lead_pipeline_id' => $defaultId]);
    DB::table('txn_stage_roles')->insert(['lead_pipeline_stage_id' => $customId, 'role' => StageRoles::CONTACTED, 'created_at' => now(), 'updated_at' => now()]);
    $check('A new stage given the Contacted role takes over "link sent"', $stages->linkSent($p2, $fresh($l2)) === 1 && $at($l2) === [$defaultId, 'called-once'], $where($l2));

    $plainId = DB::table('lead_pipeline_stages')->insertGetId(['code' => 'waiting-on-hoa', 'name' => 'Waiting on HOA', 'probability' => 100, 'sort_order' => 99, 'lead_pipeline_id' => $defaultId]);
    $hoa = $makeLead('Pool cleaning', $makePerson(), 'waiting-on-hoa');
    $result = $triage->check($fresh($hoa));
    $check('A stage without a role is left alone by the lead check', ! $result['moved'] && $at($hoa) === [$defaultId, 'waiting-on-hoa'], $where($hoa));
    $check('... and by booking-link and follow-up moves', $stages->linkSent($hoa->person, $fresh($hoa)) === 0 && ! $stages->lost($fresh($hoa), 'x'), $where($hoa));

    // =====================================================================
    $section = 'Stage rules screen (Settings > TXN Settings)';
    // =====================================================================
    $url = route('admin.settings.txn.stage_rules', [], false);
    $page = $call('GET', $url);
    $html = (string) $page->getContent();
    $check('Screen opens and lists the stages', $page->getStatusCode() === 200 && str_contains($html, 'Stage rules') && str_contains($html, 'Called once'), 'HTTP '.$page->getStatusCode());
    $check('Every role is held ("Called once" has Contacted) -> no missing-role warning', ! str_contains($html, 'no stage has the role'));

    $logBefore = DB::table('txn_settings_log')->count();
    $call('PUT', $url, ['roles' => [$customId => StageRoles::CONTACTED, $contactedId => StageRoles::CONTACTED]]);
    $check('Same role on two stages of one pipeline -> refused, nothing saved', $rolesSvc->roleOf($contactedId) === null && $rolesSvc->roleOf($customId) === StageRoles::CONTACTED && DB::table('txn_settings_log')->count() === $logBefore);

    $wonId = $stageId($defaultId, 'won');
    $call('PUT', $url, ['roles' => [$wonId => StageRoles::NEW]]);
    $check('A role on Won (Krayin closing stage) -> refused', $rolesSvc->roleOf($wonId) === null);

    $call('PUT', $url, ['roles' => [$customId => StageRoles::WAITING_ROOF_REPORT]]);
    $check('Waiting for roof report in a second pipeline -> refused', $rolesSvc->roleOf($customId) === StageRoles::CONTACTED);

    $call('PUT', $url, ['roles' => [$customId => '', $contactedId => StageRoles::CONTACTED]]);
    $logged = DB::table('txn_settings_log')->where('area', 'stage_roles')->orderByDesc('id')->limit(2)->get();
    $check('Valid change -> saved', $rolesSvc->roleOf($contactedId) === StageRoles::CONTACTED && $rolesSvc->roleOf($customId) === null);
    $check('... and both changes logged with who and old -> new', DB::table('txn_settings_log')->count() === $logBefore + 2
        && $logged->every(fn ($row) => (int) $row->user_id === (int) $adminId)
        && $logged->contains(fn ($row) => $row->old_value === 'Contacted' && $row->new_value === 'None'), $logged->toJson());

    DB::table('lead_pipeline_stages')->whereIn('id', [$customId, $plainId])->update(['sort_order' => 1]);
    $html = (string) $call('GET', $url)->getContent();
    $check('Two stages on the same position -> the screen warns (hidden board column)', str_contains($html, 'share position 1'));

    $check('No call left this machine except the faked map lookups', Http::recorded()->every(fn ($pair) => str_contains($pair[0]->url(), 'geocod.io')), Http::recorded()->count().' requests');
} catch (Throwable $exception) {
    $check('Check script crashed', false, get_class($exception).': '.$exception->getMessage().' @ '.basename($exception->getFile()).':'.$exception->getLine());
} finally {
    DB::rollBack();
}

// =========================================================================
// Report
// =========================================================================
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
