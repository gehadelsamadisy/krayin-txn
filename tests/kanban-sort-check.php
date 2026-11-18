<?php

/*
 * Kanban sort check: the per-stage sort on the leads board
 * (LeadController::get(), parameter stage_sorts = "stageId:field:direction,...").
 *
 * Run inside the Krayin container, after the migrations:
 *   php tests/kanban-sort-check.php
 *
 * Everything runs in one database transaction that is rolled back at the end:
 * a throw-away pipeline with one stage and 13 test leads, so real leads never
 * affect the expected order. Mail goes to the array mailer and outgoing HTTP is
 * faked, so nothing is kept or sent. Exit code = number of failed checks.
 */

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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
]);

Http::fake(['*' => Http::response([], 500)]);

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
    // Setup: a throw-away pipeline + stage holding only the test leads
    // =====================================================================
    $section = 'Setup';

    $adminId = DB::table('users')->join('roles', 'roles.id', '=', 'users.role_id')
        ->where('roles.permission_type', 'all')->where('users.status', 1)->value('users.id');
    auth()->guard('user')->setUser(Webkul\User\Models\User::find($adminId));

    $now = now();
    $pipelineId = DB::table('lead_pipelines')->insertGetId([
        'name' => 'Kanban Sort Check', 'is_default' => 0, 'rotten_days' => 30,
        'created_at' => $now, 'updated_at' => $now,
    ]);
    $stageId = DB::table('lead_pipeline_stages')->insertGetId([
        'code' => 'kanban-sort-check', 'name' => 'Sort Check', 'probability' => 50,
        'sort_order' => 1, 'lead_pipeline_id' => $pipelineId,
    ]);
    $otherStageId = DB::table('lead_pipeline_stages')->insertGetId([
        'code' => 'kanban-sort-check-2', 'name' => 'Sort Check 2', 'probability' => 50,
        'sort_order' => 2, 'lead_pipeline_id' => $pipelineId,
    ]);

    $settings = config('lead_triage.import');

    // Two leads share a contact name (title decides), two share a creation
    // time (id decides): the tie-breakers are exercised.
    $plan = [
        ['Echo',    '2026-01-05 10:00:00', 120],
        ['Alpha',   '2026-01-03 10:00:00', 50],
        ['Charlie', '2026-01-09 10:00:00', 0],
        ['Bravo',   '2026-01-01 10:00:00', 75],
        ['Alpha',   '2026-01-07 10:00:00', 10],
        ['Golf',    '2026-01-02 10:00:00', 0],
        ['Delta',   '2026-01-11 10:00:00', 300],
        ['Hotel',   '2026-01-04 10:00:00', 5],
        ['India',   '2026-01-04 10:00:00', 5],
        ['Foxtrot', '2026-01-10 10:00:00', 0],
        ['Juliet',  '2026-01-06 10:00:00', 40],
        ['Kilo',    '2026-01-08 10:00:00', 0],
        ['Lima',    '2026-01-12 10:00:00', 1],
    ];

    $rows = [];
    foreach ($plan as $i => [$name, $createdAt, $value]) {
        $person = app(PersonRepository::class)->create([
            'entity_type' => 'persons',
            'name' => "{$name} Sortcheck",
            'emails' => [['label' => 'work', 'value' => "kanban.sort{$i}@txn-check.example"]],
            'contact_numbers' => [['label' => 'work', 'value' => '713-555-02'.str_pad((string) $i, 2, '0', STR_PAD_LEFT)]],
            'user_id' => $adminId,
        ]);

        $lead = app(LeadRepository::class)->create([
            'entity_type' => 'leads',
            'title' => sprintf('Roof %02d', $i),
            'lead_value' => $value,
            'status' => 1,
            'lead_source_id' => $settings['source_id'],
            'lead_type_id' => $settings['type_id'],
            'user_id' => $adminId,
            'lead_pipeline_id' => $pipelineId,
            'lead_pipeline_stage_id' => $stageId,
            'person' => ['id' => $person->id],
        ]);

        DB::table('leads')->where('id', $lead->id)->update([
            'created_at' => $createdAt,
            'lead_pipeline_id' => $pipelineId,
            'lead_pipeline_stage_id' => $stageId,
        ]);

        $rows[] = [
            'id' => (int) $lead->id,
            'name' => "{$name} Sortcheck",
            'title' => sprintf('Roof %02d', $i),
            'created_at' => $createdAt,
        ];
    }

    $count = DB::table('leads')->where('lead_pipeline_stage_id', $stageId)->count();
    $check('13 test leads in the throw-away stage', $count === 13, "found {$count}");

    // A lead in the second stage: a sort there must not change the first stage.
    DB::table('leads')->where('id', $rows[12]['id'])->update(['lead_pipeline_stage_id' => $otherStageId]);
    $stageRows = array_slice($rows, 0, 12);
    $total = (float) DB::table('leads')->where('lead_pipeline_stage_id', $stageId)->sum('lead_value');

    // =====================================================================
    // Helpers
    // =====================================================================
    $fetch = function (string $sorts = '', int $page = 1, bool $oneStage = false) use ($call, $pipelineId, $stageId) {
        $params = ['pipeline_id' => $pipelineId, 'stage_sorts' => $sorts, 'page' => $page];
        if ($oneStage) {
            $params['pipeline_stage_id'] = $stageId;
        }

        $response = $call('GET', route('admin.leads.get', [], false), $params);
        $json = json_decode((string) $response->getContent(), true);

        return [
            'status' => $response->getStatusCode(),
            'ids' => array_map(fn ($lead) => (int) $lead['id'], $json[1]['leads']['data'] ?? []),
            'meta' => $json[1]['leads']['meta'] ?? [],
            'value' => (float) ($json[1]['lead_value'] ?? -1),
        ];
    };

    // Both pages of the stage (12 leads, 10 per page).
    $all = function (string $sorts = '') use ($fetch) {
        $one = $fetch($sorts, 1);
        $two = $fetch($sorts, 2);

        return ['ids' => array_merge($one['ids'], $two['ids']), 'one' => $one, 'two' => $two];
    };

    $expected = function (string $field, string $direction) use ($stageRows) {
        $list = $stageRows;
        usort($list, function ($a, $b) use ($field, $direction) {
            $cmp = $field === 'created_at'
                ? [$a['created_at'], $a['id']] <=> [$b['created_at'], $b['id']]
                : [$a['name'], $a['title'], $a['id']] <=> [$b['name'], $b['title'], $b['id']];

            return $direction === 'asc' ? $cmp : -$cmp;
        });

        return array_column($list, 'id');
    };

    // =====================================================================
    // The four sorts
    // =====================================================================
    $section = 'Sorts';

    foreach ([
        ['created_at', 'desc', 'Newest first'],
        ['created_at', 'asc', 'Oldest first'],
        ['name', 'asc', 'Name A -> Z'],
        ['name', 'desc', 'Name Z -> A'],
    ] as [$field, $direction, $label]) {
        $got = $all("{$stageId}:{$field}:{$direction}");
        $want = $expected($field, $direction);

        $check("{$label}: both pages in the right order", $got['ids'] === $want, 'got '.implode(',', $got['ids']).' want '.implode(',', $want));
        $check("{$label}: page 2 continues page 1 (no duplicates, none missing)", count($got['one']['ids']) === 10 && count($got['two']['ids']) === 2 && count(array_unique($got['ids'])) === 12);
        $check("{$label}: stage total unchanged", abs($got['one']['value'] - $total) < 0.001, "{$got['one']['value']} vs {$total}");
    }

    $single = $fetch("{$stageId}:name:asc", 1, true);
    $check('Reloading one column (pipeline_stage_id) uses that column\'s sort', $single['ids'] === array_slice($expected('name', 'asc'), 0, 10));

    // =====================================================================
    // Default order and bad values
    // =====================================================================
    $section = 'Default and bad values';

    $default = $all('');
    $check('No sort: 12 leads, request OK', $default['one']['status'] === 200 && count(array_unique($default['ids'])) === 12);

    foreach ([
        "x:name:asc" => 'stage id not a number',
        "{$stageId}:password:desc" => 'field not allowed',
        "{$stageId}:name:sideways" => 'direction not allowed',
        "{$stageId}:name" => 'missing direction',
        "{$stageId}:name:asc;drop table leads" => 'SQL in the direction',
        "{$otherStageId}:name:desc" => 'sort for another stage only',
    ] as $sorts => $label) {
        $got = $all($sorts);
        $check("Bad value ({$label}) -> default order, no error", $got['one']['status'] === 200 && $got['ids'] === $default['ids']);
    }

    $mixed = $all("{$otherStageId}:created_at:asc,junk,{$stageId}:created_at:desc");
    $check('Several stages in one request: each stage gets its own sort', $mixed['ids'] === $expected('created_at', 'desc'));

    // =====================================================================
    // A lead whose contact is gone sorts last (only if the schema allows it)
    // =====================================================================
    $section = 'Lead without a contact';

    $orphan = $stageRows[0]['id'];
    try {
        DB::table('leads')->where('id', $orphan)->update(['person_id' => null]);
        $possible = true;
    } catch (Throwable $e) {
        $possible = false;
    }

    if ($possible) {
        foreach (['asc', 'desc'] as $direction) {
            $got = $all("{$stageId}:name:{$direction}");
            $check("Name {$direction}: the lead without a contact is last", end($got['ids']) === $orphan, 'got '.implode(',', $got['ids']));
        }
    } else {
        $check('Skipped: leads.person_id cannot be empty in this database (every lead has a contact)', true);
    }

    $check('No call left this machine', Http::recorded()->count() === 0, Http::recorded()->count().' requests');
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
