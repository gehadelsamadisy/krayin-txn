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
        'prospect' => 'Visit Done', 'negotiation' => 'Estimate Sent', 'won' => 'Won – Job Sold', 'lost' => 'Lost',
    ];
    foreach ([$defaultId => 'Main', $roofId => 'Roof'] as $pipelineId => $label) {
        $actual = array_intersect_key($names($pipelineId), $expectedNames);
        ksort($actual);
        $wanted = $expectedNames;
        ksort($wanted);
        $check("{$label} pipeline: stage names", $actual === $wanted, json_encode($actual));
    }

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
