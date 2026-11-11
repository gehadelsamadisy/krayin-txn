<?php

/*
 * Appointment schedule check: what can and cannot be done with customer
 * appointments, worker events and time off, through the same HTTP routes the
 * admin uses, plus the Cal.com sync and the slot finder.
 *
 * Run inside the Krayin container:
 *   php tests/appointment-schedule-check.php
 *
 * Everything runs in one database transaction that is rolled back at the end,
 * outgoing HTTP is faked and mail goes to the array mailer, so nothing is
 * kept, sent to Cal.com or emailed. Test data lives in March 2027 so real
 * appointments do not interfere. Exit code = number of failed checks.
 */

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Webkul\Activity\Models\ActivityProxy;
use Webkul\Admin\Models\Worker;
use Webkul\Admin\Models\WorkerScheduleSetting;
use Webkul\Admin\Support\Contact\CalcomBookingSyncService;
use Webkul\Admin\Support\Contact\SlotSuggestionService;
use Webkul\Admin\Support\Contact\WorkerRouteService;
use Webkul\Contact\Repositories\PersonRepository;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->bootstrap();
$app->instance('request', Request::create('/admin'));

config(['mail.default' => 'array', 'queue.default' => 'sync']);
Http::fake();

// The admin forms post a CSRF token; these checks call the routes directly.
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
$lastResponse = '';

// A failed check also shows the start of the last response, to see why.
$check = function (string $name, bool $ok, string $detail = '') use (&$results, &$section, &$lastResponse) {
    if (! $ok && $lastResponse !== '') {
        $detail .= ' | last response: '.$lastResponse;
    }

    $results[] = compact('section', 'name', 'ok', 'detail');
};

$call = function (string $method, string $uri, array $params = [], bool $json = true) use ($kernel, &$lastResponse) {
    $request = Request::create($uri, $method, $params);

    if ($json) {
        $request->headers->set('X-Requested-With', 'XMLHttpRequest');
        $request->headers->set('Accept', 'application/json');
    }

    app()->instance('request', $request);
    $response = $kernel->handle($request);
    $content = (string) $response->getContent();
    $lastResponse = str_starts_with(ltrim($content), '<')
        ? 'HTML <title>'.trim((string) preg_replace('/\s+/', ' ', strip_tags((string) (preg_match('/<title>(.*?)<\/title>/s', $content, $m) ? $m[1] : '')))).'</title>'
        : mb_substr($content, 0, 220);

    return $response;
};

$as = fn ($user) => auth()->guard('user')->setUser($user);
$fresh = fn (int $id) => DB::table('activities')->find($id);
$pivot = fn (int $id) => DB::table('activity_workers')->where('activity_id', $id)->orderBy('worker_id')->pluck('worker_id')->map(fn ($v) => (int) $v)->all();
$additional = fn (int $id) => json_decode((string) $fresh($id)->additional, true) ?: [];
$body = fn ($response) => (string) $response->getContent();
$status = fn ($response) => $response->getStatusCode();

// Krayin renders its HTML error pages (401, 404, ...) with HTTP 200, so a
// blocked page is recognised by its title as well.
$isError = fn ($response, int $code) => $response->getStatusCode() === $code
    || (bool) preg_match("/<title>\s*{$code}\b/", (string) $response->getContent());

// The activity form always posts empty participants; without them Krayin's
// own meeting-overlap check refuses every meeting (see the INFO line).
$uiParticipants = ['users' => [''], 'persons' => ['']];

// Facts worth knowing that are product decisions, not pass/fail.
$info = function (string $name, string $detail) use (&$results, &$section) {
    $results[] = ['section' => $section, 'name' => $name, 'ok' => null, 'detail' => $detail];
};

DB::beginTransaction();

try {
    // =====================================================================
    // Setup
    // =====================================================================
    $tz = config('slot_finder.timezone');
    $day = '2027-03-02'; // Tuesday, before the DST change (UTC-6)
    $at = fn (string $time, string $date = null) => ($date ?? $day)." {$time}:00";

    WorkerScheduleSetting::query()->updateOrCreate(['id' => 1], [
        'default_work_hours' => array_fill_keys(range(1, 7), ['09:00', '18:00']),
        'holidays' => [],
    ]);

    // Only this check's workers take part in slot suggestions.
    DB::table('workers')->update(['is_active' => false]);

    $makeWorker = fn (string $name, int $priority, bool $active = true) => Worker::query()->create([
        'name' => $name,
        'is_active' => $active,
        'start_address' => '2001 Timberloch Pl, The Woodlands, TX 77380',
        'home_latitude' => 30.157301,
        'home_longitude' => -95.467582,
        'work_hours' => [],
        'priority' => $priority,
        'services' => [],
    ]);
    $workerA = $makeWorker('Check Worker A', 1);
    $workerB = $makeWorker('Check Worker B', 2);
    $workerC = $makeWorker('Check Worker C (inactive)', 3, false);

    $adminId = DB::table('users')->join('roles', 'roles.id', '=', 'users.role_id')
        ->where('roles.permission_type', 'all')->where('users.status', 1)->value('users.id');
    $admin = Webkul\User\Models\User::find($adminId);

    $roleId = DB::table('roles')->insertGetId([
        'name' => 'Schedule check limited',
        'permission_type' => 'custom',
        'permissions' => json_encode(['dashboard', 'activities', 'activities.create', 'activities.edit', 'contacts', 'contacts.workers']),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $limitedId = DB::table('users')->insertGetId([
        'name' => 'Schedule check limited', 'email' => 'schedule.check@txn-check.example',
        'password' => bcrypt(str()->random(32)), 'status' => 1, 'view_permission' => 'global',
        'role_id' => $roleId, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $limited = Webkul\User\Models\User::find($limitedId);

    $person = app(PersonRepository::class)->create([
        'entity_type' => 'persons',
        'name' => 'Check Customer',
        'emails' => [['label' => 'work', 'value' => 'check.customer@txn-check.example']],
        'contact_numbers' => [['label' => 'work', 'value' => '555-0199']],
        'user_id' => $adminId,
    ]);

    $sync = app(CalcomBookingSyncService::class);
    $booking = fn (string $uid, string $start, string $end, array $extra = []) => array_merge([
        'uid' => $uid,
        'id' => crc32($uid),
        'status' => 'accepted',
        'eventTypeId' => 1,
        'eventType' => ['slug' => "txn-home-visit-{$person->id}-worker-{$workerA->id}-check"],
        'start' => $start,
        'end' => $end,
        'updatedAt' => '2027-01-01T00:00:00.000Z',
        'createdAt' => '2027-01-01T00:00:00.000Z',
        'attendees' => [['name' => 'Check Customer', 'email' => 'check.customer@txn-check.example']],
        'location' => '1 Check St, The Woodlands, TX 77380',
    ], $extra);
    $calActivity = fn (string $uid) => ActivityProxy::modelClass()::query()->where('additional->calcom->uid', $uid)->first();

    // Like the demo seeder: worker_id only, no pivot row.
    $makeActivity = function (string $title, string $kind, string $from, string $to, Worker $worker) use ($adminId) {
        $activity = ActivityProxy::modelClass()::create([
            'title' => $title, 'type' => 'meeting', 'is_done' => false, 'user_id' => $adminId,
            'schedule_from' => $from, 'schedule_to' => $to,
            'additional' => json_encode(['txn' => ['kind' => $kind]]),
        ]);
        $activity->forceFill(['worker_id' => $worker->id])->save();

        return $activity;
    };

    // =====================================================================
    $section = 'Cal.com booking arrives';
    // =====================================================================
    $result = $sync->upsert($booking('chk-1', "{$day}T15:00:00Z", "{$day}T16:00:00Z"));
    $cal = $calActivity('chk-1');
    $check('New booking creates one activity', $result === 'created' && $cal !== null, "upsert returned {$result}");
    $check('Stored as Chicago wall time 09:00-10:00', $cal && (string) $fresh($cal->id)->schedule_from === $at('09:00') && (string) $fresh($cal->id)->schedule_to === $at('10:00'), $cal ? $fresh($cal->id)->schedule_from.' - '.$fresh($cal->id)->schedule_to : '');
    $check('Assigned to the worker named in the event slug', $cal && (int) $fresh($cal->id)->worker_id === $workerA->id && $pivot($cal->id) === [$workerA->id], 'worker_id '.($cal ? $fresh($cal->id)->worker_id : '?').', pivot '.json_encode($cal ? $pivot($cal->id) : []));
    $check('Linked to the customer', $cal && DB::table('person_activities')->where('activity_id', $cal->id)->where('person_id', $person->id)->exists());
    $check('Visit location is the contact\'s stored address (none here), not the one sent by Cal.com', $cal && $fresh($cal->id)->location === null, $cal ? (string) $fresh($cal->id)->location : '');
    $check('Same booking again is "unchanged"', $sync->upsert($booking('chk-1', "{$day}T15:00:00Z", "{$day}T16:00:00Z")) === 'unchanged');
    $check('Booking with an unknown slug is skipped', $sync->upsert($booking('chk-x', "{$day}T15:00:00Z", "{$day}T16:00:00Z", ['eventType' => ['slug' => 'something-else']])) === 'skipped');

    $as($admin);

    // =====================================================================
    $section = 'Customer booking: what staff cannot do';
    // =====================================================================
    $id = $cal->id;
    $edit = $call('GET', "/admin/activities/edit/{$id}", [], false);
    $check('Edit page opens', $status($edit) === 200, "HTTP {$status($edit)}");
    $check('Edit page has no date/time inputs', ! str_contains($body($edit), 'name="schedule_from"') && ! str_contains($body($edit), 'name="schedule_to"'));
    $check('Edit page offers a single worker choice (radio)', str_contains($body($edit), 'type="radio"') && str_contains($body($edit), 'Save worker assignment'));
    $check('Edit page has no Delete button', ! str_contains($body($edit), 'onclick="deleteWorkerActivity()"'));

    $before = $fresh($id);
    $r = $call('PUT', "/admin/activities/edit/{$id}", ['schedule_from' => $at('12:00'), 'schedule_to' => $at('13:00')]);
    $check('Change time only -> refused', $status($r) === 422 && $fresh($id)->schedule_from === $before->schedule_from, "HTTP {$status($r)}");
    $r = $call('PUT', "/admin/activities/edit/{$id}", ['worker_assignment_present' => 1, 'worker_ids' => [$workerA->id], 'schedule_from' => $at('12:00'), 'schedule_to' => $at('13:00')]);
    $check('Change time together with worker -> refused', $status($r) === 422 && $fresh($id)->schedule_from === $before->schedule_from, "HTTP {$status($r)}");
    $r = $call('PUT', "/admin/activities/edit/{$id}", ['worker_assignment_present' => 1, 'worker_ids' => [$workerA->id], 'title' => 'Renamed', 'comment' => 'x', 'location' => 'Elsewhere']);
    $check('Change title/comment/location -> refused', $status($r) === 422 && $fresh($id)->title === $before->title && $fresh($id)->location === $before->location, "HTTP {$status($r)}");
    $r = $call('PUT', "/admin/activities/edit/{$id}", ['is_done' => 1]);
    $check('Mark done (activity list tick) -> refused', $status($r) === 422 && (int) $fresh($id)->is_done === 0, "HTTP {$status($r)}");
    $r = $call('POST', '/admin/activities/mass-update', ['indices' => [$id], 'value' => 1]);
    $check('Mass "mark done" -> refused', $status($r) === 422 && (int) $fresh($id)->is_done === 0, "HTTP {$status($r)}");
    $r = $call('DELETE', "/admin/activities/{$id}");
    $check('Delete -> refused', $status($r) === 422 && $fresh($id) !== null, "HTTP {$status($r)}");
    $r = $call('POST', '/admin/activities/mass-destroy', ['indices' => [$id]]);
    $check('Mass delete -> refused', $status($r) === 422 && $fresh($id) !== null, "HTTP {$status($r)}");

    $r = $call('PUT', "/admin/activities/edit/{$id}", ['worker_assignment_present' => 1, 'worker_ids' => [$workerA->id, $workerB->id]]);
    $check('Two workers -> refused (exactly one)', $status($r) === 422 && $pivot($id) === [$workerA->id], "HTTP {$status($r)}");
    $r = $call('PUT', "/admin/activities/edit/{$id}", ['worker_assignment_present' => 1]);
    $check('No worker -> refused', $status($r) === 422 && $pivot($id) === [$workerA->id], "HTTP {$status($r)}");
    $r = $call('PUT', "/admin/activities/edit/{$id}", ['worker_assignment_present' => 1, 'worker_ids' => [$workerC->id]]);
    $check('Inactive worker -> refused', $status($r) === 422 && $pivot($id) === [$workerA->id], "HTTP {$status($r)}");
    $r = $call('PUT', "/admin/activities/edit/{$id}", ['worker_assignment_present' => 1, 'worker_ids' => [999999]]);
    $check('Non-existent worker -> refused', $status($r) === 422, "HTTP {$status($r)}");

    $clash = $makeActivity('Busy: B clash', 'busy', $at('09:30'), $at('10:30'), $workerB);
    $r = $call('PUT', "/admin/activities/edit/{$id}", ['worker_assignment_present' => 1, 'worker_ids' => [$workerB->id]]);
    $check('Worker already busy at that time -> refused', $status($r) === 422 && $pivot($id) === [$workerA->id], "HTTP {$status($r)}");
    $clash->delete();

    // =====================================================================
    $section = 'Customer booking: reassigning the worker';
    // =====================================================================
    $r = $call('PUT', "/admin/activities/edit/{$id}", ['worker_assignment_present' => 1, 'worker_ids' => [$workerB->id]]);
    $after = $fresh($id);
    $check('Reassign to a free active worker -> saved', $status($r) === 200 && (int) $after->worker_id === $workerB->id && $pivot($id) === [$workerB->id], "HTTP {$status($r)}, worker_id {$after->worker_id}, pivot ".json_encode($pivot($id)));
    $check('Reassign keeps time, title and customer untouched', $after->schedule_from === $before->schedule_from && $after->schedule_to === $before->schedule_to && $after->title === $before->title);
    $check('Reassign is remembered as a worker override', data_get($additional($id), 'calcom.worker_override_ids') === [$workerB->id]);
    $check('Reassign makes no call to Cal.com', Http::recorded()->isEmpty(), Http::recorded()->count().' outgoing requests');

    $r = $call('PUT', "/admin/activities/edit/{$id}", ['worker_assignment_present' => 1, 'worker_ids' => [$workerB->id]], false);
    $check('Reassign from the HTML form redirects back to the page', $status($r) === 302 && str_contains((string) $r->headers->get('Location'), "/activities/edit/{$id}"), "HTTP {$status($r)}");

    // =====================================================================
    $section = 'Customer reschedules / cancels on Cal.com';
    // =====================================================================
    $r = $sync->upsert($booking('chk-1', "{$day}T19:00:00Z", "{$day}T20:00:00Z", ['updatedAt' => '2027-01-02T00:00:00.000Z']));
    $check('Same booking moved -> activity moves to 13:00', $r === 'updated' && $fresh($id)->schedule_from === $at('13:00'), "{$r}, ".$fresh($id)->schedule_from);
    $check('Worker override survives the move', (int) $fresh($id)->worker_id === $workerB->id && $pivot($id) === [$workerB->id], 'worker_id '.$fresh($id)->worker_id.', pivot '.json_encode($pivot($id)));

    $r1 = $sync->upsert($booking('chk-1', "{$day}T19:00:00Z", "{$day}T20:00:00Z", ['status' => 'cancelled', 'rescheduledToUid' => 'chk-2', 'updatedAt' => '2027-01-03T00:00:00.000Z']));
    $r2 = $sync->upsert($booking('chk-2', "{$day}T21:00:00Z", "{$day}T22:00:00Z", ['rescheduledFromUid' => 'chk-1', 'updatedAt' => '2027-01-03T00:00:01.000Z']));
    $check('Reschedule (new uid) keeps ONE activity, moved to 15:00', $r1 === 'skipped' && $r2 === 'updated' && $fresh($id)->schedule_from === $at('15:00') && data_get($additional($id), 'calcom.uid') === 'chk-2', "{$r1}/{$r2}, ".$fresh($id)->schedule_from);
    $check('Worker override survives the reschedule', (int) $fresh($id)->worker_id === $workerB->id && $pivot($id) === [$workerB->id]);

    $sync->upsert($booking('chk-3', "{$day}T22:00:00Z", "{$day}T23:00:00Z"));
    $cancelled = $calActivity('chk-3');
    $sync->upsert($booking('chk-3', "{$day}T22:00:00Z", "{$day}T23:00:00Z", ['status' => 'cancelled', 'updatedAt' => '2027-01-04T00:00:00.000Z', 'cancellationReason' => 'Changed mind']));
    $c = $fresh($cancelled->id);
    $check('Cancelled booking: kept, marked done, titled "Cancelled:"', (int) $c->is_done === 1 && str_starts_with($c->title, 'Cancelled: '), "{$c->title}, done={$c->is_done}");
    $edit = $call('GET', "/admin/activities/edit/{$cancelled->id}", [], false);
    $check('Cancelled booking edit page has no Save button', ! str_contains($body($edit), 'Save worker assignment') && ! str_contains($body($edit), 'type="radio"'));
    $r = $call('PUT', "/admin/activities/edit/{$cancelled->id}", ['worker_assignment_present' => 1, 'worker_ids' => [$workerB->id]]);
    $check('Cancelled booking: reassign -> refused', $status($r) === 422, "HTTP {$status($r)}");
    $r = $call('DELETE', "/admin/activities/{$cancelled->id}");
    $check('Cancelled booking: delete -> refused', $status($r) === 422, "HTTP {$status($r)}");

    // =====================================================================
    $section = 'Seeded customer visit (txn.kind = visit)';
    // =====================================================================
    $visit = $makeActivity('Home visit - Check', 'visit', $at('11:00'), $at('12:00'), $workerA);
    $edit = $call('GET', "/admin/activities/edit/{$visit->id}", [], false);
    $check('Edit page has no date/time inputs', ! str_contains($body($edit), 'name="schedule_from"'));
    $r = $call('PUT', "/admin/activities/edit/{$visit->id}", ['schedule_from' => $at('16:00'), 'schedule_to' => $at('17:00')]);
    $check('Change time -> refused', $status($r) === 422 && $fresh($visit->id)->schedule_from === $at('11:00'), "HTTP {$status($r)}");
    $r = $call('DELETE', "/admin/activities/{$visit->id}");
    $check('Delete -> refused', $status($r) === 422, "HTTP {$status($r)}");
    $r = $call('PUT', "/admin/activities/edit/{$visit->id}", ['worker_assignment_present' => 1, 'worker_ids' => [$workerB->id]]);
    $check('Reassign worker -> saved', $status($r) === 200 && (int) $fresh($visit->id)->worker_id === $workerB->id && $pivot($visit->id) === [$workerB->id], "HTTP {$status($r)}");
    $call('PUT', "/admin/activities/edit/{$visit->id}", ['worker_assignment_present' => 1, 'worker_ids' => [$workerA->id]]);

    // =====================================================================
    $section = 'Worker event (busy block): what staff can do';
    // =====================================================================
    $busy = $makeActivity('Busy: Check pickup', 'busy', $at('13:00', '2027-03-03'), $at('14:00', '2027-03-03'), $workerA);
    $edit = $call('GET', "/admin/activities/edit/{$busy->id}", [], false);
    $check('Edit page has date/time inputs', str_contains($body($edit), 'name="schedule_from"') && str_contains($body($edit), 'name="schedule_to"'));
    $check('Edit page has a Delete button', str_contains($body($edit), 'onclick="deleteWorkerActivity()"'));
    $r = $call('PUT', "/admin/activities/edit/{$busy->id}", ['schedule_from' => $at('14:00', '2027-03-03'), 'schedule_to' => $at('15:30', '2027-03-03')]);
    $check('Change time -> saved', $status($r) === 200 && $fresh($busy->id)->schedule_from === $at('14:00', '2027-03-03') && $fresh($busy->id)->schedule_to === $at('15:30', '2027-03-03'), "HTTP {$status($r)}");
    $r = $call('PUT', "/admin/activities/edit/{$busy->id}", ['title' => 'Busy: Dentist', 'comment' => 'Moved']);
    $check('Change title/comment -> saved', $status($r) === 200 && $fresh($busy->id)->title === 'Busy: Dentist', "HTTP {$status($r)}");
    $r = $call('PUT', "/admin/activities/edit/{$busy->id}", ['worker_assignment_present' => 1, 'worker_ids' => [$workerA->id, $workerB->id]]);
    $check('Assign two workers -> saved', $status($r) === 200 && $pivot($busy->id) === [$workerA->id, $workerB->id], "HTTP {$status($r)}");
    $call('PUT', "/admin/activities/edit/{$busy->id}", ['worker_assignment_present' => 1, 'worker_ids' => [$workerA->id]]);

    $other = $makeActivity('Busy: other', 'busy', $at('09:00', '2027-03-03'), $at('10:00', '2027-03-03'), $workerA);
    $r = $call('PUT', "/admin/activities/edit/{$busy->id}", ['schedule_from' => $at('09:30', '2027-03-03'), 'schedule_to' => $at('10:30', '2027-03-03')]);
    $check('Move onto another event of the same worker -> refused', $status($r) === 422 && $fresh($busy->id)->schedule_from === $at('14:00', '2027-03-03'), "HTTP {$status($r)}");
    $r = $call('PUT', "/admin/activities/edit/{$busy->id}", ['schedule_from' => $at('15:00', '2027-03-03'), 'schedule_to' => $at('14:00', '2027-03-03')]);
    $check('End before start -> refused', $status($r) === 422 && $fresh($busy->id)->schedule_from === $at('14:00', '2027-03-03'), "HTTP {$status($r)}, now ".$fresh($busy->id)->schedule_from.' - '.$fresh($busy->id)->schedule_to);
    $call('PUT', "/admin/activities/edit/{$busy->id}", ['schedule_from' => $at('14:00', '2027-03-03'), 'schedule_to' => $at('15:30', '2027-03-03')]);

    $r = $call('PUT', "/admin/activities/edit/{$busy->id}", ['is_done' => 1]);
    $check('Mark done -> saved', $status($r) === 200 && (int) $fresh($busy->id)->is_done === 1, "HTTP {$status($r)}");
    $call('PUT', "/admin/activities/edit/{$busy->id}", ['is_done' => 0]);

    $r = $call('POST', '/admin/activities/create', ['type' => 'meeting', 'title' => 'Busy: new', 'schedule_from' => $at('09:30', '2027-03-03'), 'schedule_to' => $at('10:30', '2027-03-03'), 'worker_ids' => [$workerA->id], 'participants' => $uiParticipants]);
    $check('Create overlapping event for same worker -> refused', $status($r) === 422, "HTTP {$status($r)}");
    $r = $call('POST', '/admin/activities/create', ['type' => 'meeting', 'title' => 'Busy: new', 'schedule_from' => $at('16:00', '2027-03-03'), 'schedule_to' => $at('17:00', '2027-03-03'), 'worker_ids' => [$workerA->id], 'participants' => $uiParticipants]);
    $newId = (int) data_get(json_decode($body($r), true), 'data.id');
    $check('Create free event for a worker -> saved with worker', $status($r) === 200 && $newId && (int) $fresh($newId)->worker_id === $workerA->id && $pivot($newId) === [$workerA->id], "HTTP {$status($r)}");
    $r = $call('POST', '/admin/activities/create', ['type' => 'meeting', 'title' => 'Busy: on A booking', 'schedule_from' => $at('11:00'), 'schedule_to' => $at('12:00'), 'worker_ids' => [$workerA->id], 'participants' => $uiParticipants]);
    $check('Create event on top of a customer visit of that worker -> refused', $status($r) === 422, "HTTP {$status($r)}");
    $r = $call('POST', '/admin/activities/create', ['type' => 'meeting', 'title' => 'Busy: B same time', 'schedule_from' => $at('11:00'), 'schedule_to' => $at('12:00'), 'worker_ids' => [$workerB->id], 'participants' => $uiParticipants]);
    $sameTimeId = (int) data_get(json_decode($body($r), true), 'data.id');
    $check("Create event for another worker at the same time -> saved", $status($r) === 200 && $sameTimeId > 0, "HTTP {$status($r)}");
    if ($sameTimeId) {
        DB::table('activities')->where('id', $sameTimeId)->delete();
    }
    $r = $call('POST', '/admin/activities/create', ['type' => 'meeting', 'title' => 'Busy: backwards', 'schedule_from' => $at('18:00', '2027-03-03'), 'schedule_to' => $at('17:00', '2027-03-03'), 'worker_ids' => [$workerA->id], 'participants' => $uiParticipants]);
    $check('Create with end before start -> refused', $status($r) === 422, "HTTP {$status($r)}");
    $r = $call('POST', '/admin/activities/create', ['type' => 'meeting', 'title' => 'Busy: api', 'schedule_from' => $at('09:00', '2027-03-09'), 'schedule_to' => $at('10:00', '2027-03-09'), 'worker_ids' => [$workerA->id]]);
    $info('Create WITHOUT a participants field (API style)', "HTTP {$status($r)}: ".mb_substr($body($r), 0, 90).' - Krayin core refuses any meeting without participants; always send participants[users][]="" like the form does.');

    $r = $call('DELETE', "/admin/activities/{$other->id}");
    $check('Delete -> removed', $status($r) === 200 && $fresh($other->id) === null, "HTTP {$status($r)}");
    $r = $call('POST', '/admin/activities/mass-destroy', ['indices' => [$newId]]);
    $check('Mass delete of worker events -> removed', $status($r) === 200 && $fresh($newId) === null, "HTTP {$status($r)}");
    $r = $call('POST', '/admin/activities/mass-destroy', ['indices' => [$busy->id, $id]]);
    $check('Mass delete mixing a customer booking -> refused as a whole', $status($r) === 422 && $fresh($busy->id) !== null && $fresh($id) !== null, "HTTP {$status($r)}");

    // =====================================================================
    $section = 'Activities table (datagrid)';
    // =====================================================================
    $r = $call('GET', '/admin/activities/get', ['sort' => ['column' => 'id', 'order' => 'desc'], 'pagination' => ['per_page' => 50, 'page' => 1]]);
    $records = collect(data_get(json_decode($body($r), true), 'records', []))->keyBy('id');
    $actions = fn ($activityId) => collect(data_get($records->get($activityId), 'actions', []))->pluck('index')->all();
    $check('Table lists the test activities', $records->has($id) && $records->has($busy->id), "HTTP {$status($r)}, ".$records->count().' records');
    $check('Customer booking row: Edit, no Delete', $actions($id) === ['edit'], json_encode($actions($id)));
    $check('Seeded visit row: Edit, no Delete', $actions($visit->id) === ['edit'], json_encode($actions($visit->id)));
    $check('Worker event row: Edit and Delete', $actions($busy->id) === ['edit', 'delete'], json_encode($actions($busy->id)));

    // =====================================================================
    $section = 'Time off';
    // =====================================================================
    $off = $workerA->timeOffs()->create(['start_date' => $day, 'end_date' => $day, 'start_time' => '15:00', 'end_time' => '16:00', 'type' => 'personal', 'note' => 'Check']);
    $offB = $workerB->timeOffs()->create(['start_date' => $day, 'end_date' => $day, 'type' => 'sick']);
    $url = "/admin/contacts/workers/{$workerA->id}/time-off/{$off->id}";
    $valid = ['start_date' => $day, 'end_date' => $day, 'start_time' => '15:00', 'end_time' => '16:00', 'type' => 'personal', 'note' => 'Check'];

    $page = $call('GET', $url, [], false);
    $check('Edit page opens', $status($page) === 200, "HTTP {$status($page)}");
    $sched = $call('GET', "/admin/contacts/workers/{$workerA->id}/schedule", ['date' => $day], false);
    $check('Schedule links the block to its edit page', str_contains($body($sched), "/time-off/{$off->id}"));
    $r = $call('GET', "/admin/contacts/workers/{$workerA->id}/time-off/{$offB->id}", [], false);
    $check("Another worker's time off via this worker's URL -> 404", $isError($r, 404), "HTTP {$status($r)}");

    $r = $call('PUT', $url, ['time_off' => [array_merge($valid, ['start_time' => '16:00', 'end_time' => '17:30', 'note' => 'Moved'])]], false);
    $row = DB::table('worker_time_offs')->find($off->id);
    $check('Move to 16:00-17:30 -> saved', $status($r) === 302 && substr($row->start_time, 0, 5) === '16:00' && substr($row->end_time, 0, 5) === '17:30' && $row->note === 'Moved', "HTTP {$status($r)}");
    $check('Save returns to the schedule on that week', str_contains((string) $r->headers->get('Location'), "/workers/{$workerA->id}/schedule?date={$day}"), (string) $r->headers->get('Location'));
    $r = $call('PUT', $url, ['time_off' => [array_merge($valid, ['start_date' => '2027-03-02', 'end_date' => '2027-03-04', 'start_time' => '', 'end_time' => '', 'type' => 'annual_leave'])]], false);
    $row = DB::table('worker_time_offs')->find($off->id);
    $check('Change to whole days over a range -> saved', $status($r) === 302 && $row->start_time === null && (string) $row->end_date === '2027-03-04', "HTTP {$status($r)}");

    foreach ([
        'End date before start date' => ['start_date' => '2027-03-04', 'end_date' => '2027-03-02'],
        'Only a start time' => ['start_time' => '10:00', 'end_time' => ''],
        'End time before start time' => ['start_time' => '12:00', 'end_time' => '10:00'],
        'Part-day times over several dates' => ['end_date' => '2027-03-03'],
        'Unknown type' => ['type' => 'holiday'],
        'Missing type' => ['type' => ''],
        'All fields blank' => ['start_date' => '', 'end_date' => '', 'start_time' => '', 'end_time' => '', 'type' => '', 'note' => ''],
        'Bad date format' => ['start_date' => '03/02/2027'],
    ] as $label => $change) {
        $before = (array) DB::table('worker_time_offs')->find($off->id);
        $r = $call('PUT', $url, ['time_off' => [array_merge($valid, $change)]]);
        $check("{$label} -> refused", $status($r) === 422 && (array) DB::table('worker_time_offs')->find($off->id) == $before, "HTTP {$status($r)}");
    }

    $r = $call('DELETE', $url, [], false);
    $check('Delete -> removed, back to schedule', $status($r) === 302 && ! DB::table('worker_time_offs')->where('id', $off->id)->exists(), "HTTP {$status($r)}");
    $r = $call('GET', $url, [], false);
    $check('Deleted time off -> 404', $isError($r, 404), "HTTP {$status($r)}");

    // =====================================================================
    $section = 'Permissions (role without delete / worker edit)';
    // =====================================================================
    $off = $workerA->timeOffs()->create(['start_date' => $day, 'end_date' => $day, 'start_time' => '15:00', 'end_time' => '16:00', 'type' => 'personal']);
    $as($limited);
    $edit = $call('GET', "/admin/activities/edit/{$busy->id}", [], false);
    $check('Can open a worker event', $status($edit) === 200, "HTTP {$status($edit)}");
    $check('No Delete button without delete permission', ! str_contains($body($edit), 'onclick="deleteWorkerActivity()"'));
    $r = $call('DELETE', "/admin/activities/{$busy->id}");
    $check('Delete without permission -> blocked', in_array($status($r), [401, 403], true) && $fresh($busy->id) !== null, "HTTP {$status($r)}");
    $r = $call('PUT', "/admin/activities/edit/{$busy->id}", ['title' => 'Busy: by limited user']);
    $check('Edit with edit permission -> saved', $status($r) === 200 && $fresh($busy->id)->title === 'Busy: by limited user', "HTTP {$status($r)}");
    $sched = $call('GET', "/admin/contacts/workers/{$workerA->id}/schedule", ['date' => $day], false);
    $check('Schedule opens, time off is not a link', $status($sched) === 200 && ! str_contains($body($sched), "/time-off/{$off->id}"), "HTTP {$status($sched)}");
    $r = $call('GET', "/admin/contacts/workers/{$workerA->id}/time-off/{$off->id}", [], false);
    $check('Time-off page without worker edit permission -> blocked', $isError($r, 401), "HTTP {$status($r)}");
    $r = $call('DELETE', "/admin/contacts/workers/{$workerA->id}/time-off/{$off->id}", [], false);
    $check('Time-off delete without permission -> blocked', $isError($r, 401) && DB::table('worker_time_offs')->where('id', $off->id)->exists(), "HTTP {$status($r)}");
    $as($admin);

    // =====================================================================
    $section = 'Worker schedule page';
    // =====================================================================
    // Worker A on Tue: visit 11-12, time off 15-16. Booking chk-2 is B's at 15:00.
    $sched = $call('GET', "/admin/contacts/workers/{$workerA->id}/schedule", ['date' => $day], false);
    $days = collect($sched->original->getData()['days'])->keyBy(fn ($d) => $d['date']->toDateString());
    $tue = $days[$day];
    $open = array_map(fn ($slot) => $slot->format('H:i'), $tue['openSlots']);
    $check('Shows the visit on Tuesday', collect($tue['events'])->contains(fn ($e) => $e['activity']->id === $visit->id));
    $check('Reassigned booking is NOT on the old worker', ! collect($tue['events'])->contains(fn ($e) => $e['activity']->id === $id));
    $check('Open times skip the visit and time off (expect 09,13,17)', $open === ['09:00', '13:00', '17:00'], json_encode($open));
    $schedB = $call('GET', "/admin/contacts/workers/{$workerB->id}/schedule", ['date' => $day], false);
    $tueB = collect($schedB->original->getData()['days'])->keyBy(fn ($d) => $d['date']->toDateString())[$day];
    $check('Reassigned booking shows on the new worker', collect($tueB['events'])->contains(fn ($e) => $e['activity']->id === $id));
    $check('Full-day sick day: whole day off, no open times', $tueB['fullDayOff'] !== null && $tueB['openSlots'] === []);
    $check('Cancelled booking shows as cancelled, not as a visit', collect($days['2027-03-02']['events'])->contains(fn ($e) => $e['activity']->id === $cancelled->id && $e['cancelled']));
    $wed = $days['2027-03-03'];
    $info('Busy blocks count as "visits" in the schedule day header', "Wednesday has only a busy block and shows visits = {$wed['visits']}");

    // =====================================================================
    $section = 'Manual internal worker booking';
    // =====================================================================
    $bookingPage = $call('GET', "/admin/contacts/workers/{$workerA->id}/bookings/create", ['date' => '2027-03-04'], false);
    $check('Book worker form offers work and other booking types without a customer', $status($bookingPage) === 200 && str_contains($body($bookingPage), 'Work-related visit (include in route)') && str_contains($body($bookingPage), 'Other / personal booking'));
    $r = $call('POST', "/admin/contacts/workers/{$workerA->id}/bookings", [
        'title' => 'Check: pick up supplies',
        'booking_type' => 'work',
        'schedule_from' => '2027-03-04T11:00',
        'schedule_to' => '2027-03-04T12:30',
        'location' => '2001 Timberloch Pl Ste 500, The Woodlands, TX 77380',
        'comment' => 'Collect materials',
    ], false);
    $task = DB::table('activities')->where('title', 'Check: pick up supplies')->first();
    $taskId = (int) ($task->id ?? 0);
    $check('Manual booking saves as an internal task for the worker', $status($r) === 302 && $taskId && $task->type === 'task' && data_get($additional($taskId), 'txn.kind') === 'worker_task' && $pivot($taskId) === [$workerA->id], "HTTP {$status($r)}");
    $check('Manual booking returns to the selected schedule date', str_contains((string) $r->headers->get('Location'), "/workers/{$workerA->id}/schedule?date=2027-03-04"), (string) $r->headers->get('Location'));

    $thursdayPage = $call('GET', "/admin/contacts/workers/{$workerA->id}/schedule", ['date' => '2027-03-04'], false);
    $thursday = collect($thursdayPage->original->getData()['days'])->keyBy(fn ($d) => $d['date']->toDateString())['2027-03-04'];
    $check('Internal task appears in the calendar and blocks offered time', $thursday['workTasks'] === 1 && collect($thursday['events'])->contains(fn ($event) => $event['activity']->id === $taskId && $event['worker_task']) && ! in_array('11:00', array_map(fn ($slot) => $slot->format('H:i'), $thursday['openSlots']), true));
    $r = $call('GET', "/admin/activities/edit/{$taskId}", [], false);
    $check('Internal task can be edited without losing its type', $status($r) === 200 && str_contains($body($r), '<option value="task">Worker booking</option>'));

    $r = $call('POST', "/admin/contacts/workers/{$workerA->id}/bookings", [
        'title' => 'Check: overlapping task',
        'booking_type' => 'work',
        'location' => '2001 Timberloch Pl Ste 500, The Woodlands, TX 77380',
        'schedule_from' => '2027-03-04T11:30',
        'schedule_to' => '2027-03-04T12:00',
    ]);
    $check('Overlapping manual booking is refused', $status($r) === 422);

    $r = $call('POST', "/admin/contacts/workers/{$workerA->id}/bookings", [
        'title' => 'Check: time off task',
        'booking_type' => 'work',
        'location' => '2001 Timberloch Pl Ste 500, The Woodlands, TX 77380',
        'schedule_from' => '2027-03-02T15:00',
        'schedule_to' => '2027-03-02T15:30',
    ]);
    $check('Manual booking during worker time off is refused', $status($r) === 422);

    DB::table('activities')->where('id', $taskId)->update([
        'latitude' => $workerA->home_latitude,
        'longitude' => $workerA->home_longitude,
    ]);
    $routeWorker = clone $workerA;
    $routeWorker->home_latitude = null;
    $routeWorker->home_longitude = null;
    $route = app(WorkerRouteService::class)->dayRoute(
        $routeWorker,
        CarbonImmutable::createFromFormat('!Y-m-d', '2027-03-04', $tz)
    );
    $check('Internal work appears as a mapped route stop', collect($route['stops'])->contains(fn ($stop) => $stop['activity_id'] === $taskId) && $route['off_map'] === []);

    $r = $call('POST', "/admin/contacts/workers/{$workerA->id}/bookings", [
        'title' => 'Check: personal booking',
        'booking_type' => 'other',
        'schedule_from' => '2027-03-04T13:00',
        'schedule_to' => '2027-03-04T14:00',
        'comment' => 'Personal errand',
    ], false);
    $personalTask = DB::table('activities')->where('title', 'Check: personal booking')->first();
    $personalTaskId = (int) ($personalTask->id ?? 0);
    $check('Other booking saves without a work location', $status($r) === 302 && $personalTaskId && $personalTask->location === null && data_get($additional($personalTaskId), 'txn.worker_task_type') === 'other', "HTTP {$status($r)}");
    $thursdayPage = $call('GET', "/admin/contacts/workers/{$workerA->id}/schedule", ['date' => '2027-03-04'], false);
    $thursday = collect($thursdayPage->original->getData()['days'])->keyBy(fn ($d) => $d['date']->toDateString())['2027-03-04'];
    $check('Other booking appears separately and still reserves the worker', $thursday['workTasks'] === 1 && $thursday['otherBookings'] === 1 && ! in_array('13:00', array_map(fn ($slot) => $slot->format('H:i'), $thursday['openSlots']), true));

    $route = app(WorkerRouteService::class)->dayRoute(
        $routeWorker,
        CarbonImmutable::createFromFormat('!Y-m-d', '2027-03-04', $tz)
    );
    $check('Other booking reserves time but is not a route stop', ! collect($route['stops'])->contains(fn ($stop) => $stop['activity_id'] === $personalTaskId) && ! collect($route['off_map'])->contains(fn ($stop) => $stop['activity_id'] === $personalTaskId));

    $r = $call('POST', "/admin/contacts/workers/{$workerA->id}/bookings", [
        'title' => 'Check: missing location',
        'booking_type' => 'work',
        'schedule_from' => '2027-03-04T13:00',
        'schedule_to' => '2027-03-04T14:00',
    ]);
    $check('Manual work visit requires a route location', $status($r) === 422);

    $r = $call('PUT', "/admin/activities/edit/{$taskId}", ['is_done' => 1]);
    $thursdayPage = $call('GET', "/admin/contacts/workers/{$workerA->id}/schedule", ['date' => '2027-03-04'], false);
    $thursday = collect($thursdayPage->original->getData()['days'])->keyBy(fn ($d) => $d['date']->toDateString())['2027-03-04'];
    $check('Marking the task done frees its time again', $status($r) === 200 && (int) $fresh($taskId)->is_done === 1 && in_array('11:00', array_map(fn ($slot) => $slot->format('H:i'), $thursday['openSlots']), true));

    WorkerScheduleSetting::query()->whereKey(1)->update(['holidays' => [['date' => '2027-03-05', 'name' => 'Check holiday']]]);
    $sched = $call('GET', "/admin/contacts/workers/{$workerA->id}/schedule", ['date' => $day], false);
    $fri = collect($sched->original->getData()['days'])->keyBy(fn ($d) => $d['date']->toDateString())['2027-03-05'];
    $check('Company holiday: no open times', $fri['holiday'] !== null && $fri['openSlots'] === []);
    WorkerScheduleSetting::query()->whereKey(1)->update(['holidays' => []]);

    // =====================================================================
    $section = 'Slot suggestions (Suggest times)';
    // =====================================================================
    // Offline: rank with the straight-line estimate, not OSRM.
    config(['slot_finder.search_days' => 1, 'slot_finder.slots_to_offer' => 50, 'slot_finder.max_slots_per_day' => 50, 'slot_finder.ranking.road_times' => false]);
    $finder = app(SlotSuggestionService::class);
    $near = ['lat' => 30.17, 'lon' => -95.46, 'precision' => 'address'];
    $now = CarbonImmutable::create(2027, 3, 2, 6, 0, 0, $tz);
    $times = function (?Worker $only = null) use ($finder, $near, $now, $workerA, $workerB) {
        foreach ([$workerA, $workerB] as $w) {
            $w->refresh()->update(['is_active' => $only === null || $only->id === $w->id]);
        }
        $result = $finder->suggest($near, $now);

        return [$result['status'], array_map(fn ($o) => substr($o['value'], 11), $result['options']), array_unique(array_column($result['options'], 'worker_id'))];
    };

    [$st, $t] = $times($workerA);
    $check('Worker A offered only free times (09,13,17)', $t === ['09:00', '13:00', '17:00'], "{$st} ".json_encode($t));
    $busyTue = $makeActivity('Busy: Tue', 'busy', $at('13:00'), $at('14:00'), $workerA);
    [, $t] = $times($workerA);
    $check('A busy block removes its slot (13 gone)', $t === ['09:00', '17:00'], json_encode($t));
    $call('DELETE', "/admin/activities/{$busyTue->id}");
    [, $t] = $times($workerA);
    $check('Deleting the busy block frees the slot again', $t === ['09:00', '13:00', '17:00'], json_encode($t));
    $call('PUT', "/admin/activities/edit/{$busy->id}", ['schedule_from' => $at('17:00'), 'schedule_to' => $at('18:00')]);
    [, $t] = $times($workerA);
    $check('Moving a busy block onto 17:00 blocks 17:00', $t === ['09:00', '13:00'], json_encode($t));
    $call('PUT', "/admin/activities/edit/{$busy->id}", ['is_done' => 1]);
    [, $t] = $times($workerA);
    $check('A done busy block no longer blocks', $t === ['09:00', '13:00', '17:00'], json_encode($t));
    $call('PUT', "/admin/activities/edit/{$busy->id}", ['is_done' => 0, 'schedule_from' => $at('14:00', '2027-03-03'), 'schedule_to' => $at('15:30', '2027-03-03')]);

    [, $t] = $times($workerB);
    $check('Worker B: reassigned booking (15:00) blocks B; sick day blocks all', $t === [], json_encode($t));
    DB::table('worker_time_offs')->where('id', $offB->id)->delete();
    [, $t] = $times($workerB);
    $check('Worker B after sick day removed: 15:00 still taken by the booking', $t === ['09:00', '11:00', '13:00', '17:00'], json_encode($t));
    // A has personal time off 15:00-16:00 on Tuesday; the booking is at 15:00.
    // $times() leaves only one worker active; reassigning needs both.
    $workerA->update(['is_active' => true]);
    $workerB->update(['is_active' => true]);
    $r = $call('PUT', "/admin/activities/edit/{$id}", ['worker_assignment_present' => 1, 'worker_ids' => [$workerA->id]]);
    $check('Reassign booking onto a worker on time off at that hour -> refused', $status($r) === 422 && $pivot($id) === [$workerB->id], "HTTP {$status($r)}, now assigned to ".json_encode($pivot($id)));
    DB::table('worker_time_offs')->where('id', $off->id)->delete();
    $call('PUT', "/admin/activities/edit/{$id}", ['worker_assignment_present' => 1, 'worker_ids' => [$workerA->id]]);
    [, $tA] = $times($workerA);
    [, $tB] = $times($workerB);
    $check('Reassign B -> A: frees B at 15:00 ...', in_array('15:00', $tB, true), json_encode($tB));
    $check('... and blocks A at 15:00', ! in_array('15:00', $tA, true), json_encode($tA));

    $workerA->update(['is_active' => false]);
    $workerB->update(['is_active' => false]);
    $result = $finder->suggest($near, $now);
    $check('No active worker -> NO_STAFF, nothing offered', $result['options'] === [], $result['status']);
    $workerC->refresh();
    $check('Inactive worker never offered', ! in_array($workerC->id, array_column($result['options'], 'worker_id'), true));

    WorkerScheduleSetting::query()->whereKey(1)->update(['holidays' => [['date' => $day, 'name' => 'Check holiday']]]);
    [$st, $t] = $times(null);
    $check('Company holiday -> no times that day', $t === [], "{$st} ".json_encode($t));
    WorkerScheduleSetting::query()->whereKey(1)->update(['holidays' => []]);

    $workerA->update(['max_visits_per_day' => 2]);
    [, $t] = $times($workerA);
    $check('Max visits per day: A has 2 bookings -> no more times that day', $t === [], json_encode($t));
    $workerA->update(['max_visits_per_day' => 3]);
    $tmp = $makeActivity('Busy: counts?', 'busy', $at('09:00'), $at('10:00'), $workerA);
    [, $t] = $times($workerA);
    $info('Busy blocks count toward "max visits per day"', 'A has 2 customer visits + 1 busy block with a cap of 3; offered '.json_encode($t).($t === [] ? ' (the busy block used up the cap)' : ''));
    $tmp->delete();
    $workerA->update(['max_visits_per_day' => null]);

    // =====================================================================
    $section = 'Worker choice by driving';
    // =====================================================================
    // Tue 2027-03-09, both workers start at the office in The Woodlands.
    // A works in Baytown (east) that day, B in Katy (west) at 11 and 15.
    $dayC = '2027-03-09';
    $nowC = CarbonImmutable::create(2027, 3, 9, 6, 0, 0, $tz);
    $workerA->update(['is_active' => true]);
    $workerB->update(['is_active' => true]);
    $visitAt = function (Worker $worker, string $time, float $lat, float $lon) use ($makeActivity, $at, $dayC) {
        $activity = $makeActivity("Visit {$worker->name} {$time}", 'visit', $at($time, $dayC), $at(sprintf('%02d:00', (int) $time + 1), $dayC), $worker);
        $activity->forceFill(['location' => "{$lat},{$lon}", 'latitude' => $lat, 'longitude' => $lon])->save();

        return $activity;
    };
    $visitAt($workerA, '11:00', 29.7355, -94.9774);
    $visitAt($workerB, '11:00', 29.7858, -95.8245);
    $visitAt($workerB, '15:00', 29.7858, -95.8245);

    // Customer in Katy, a mile from B's jobs.
    $katy = ['lat' => 29.7900, 'lon' => -95.8100, 'precision' => 'address'];
    $result = $finder->suggest($katy, $nowC);
    $offered = fn ($result) => json_encode(array_map(fn ($o) => substr($o['value'], 11).' '.$o['worker'].' +'.$o['extra_drive_minutes'], $result['options']));
    $workerIds = array_column($result['options'], 'worker_id');
    $bTimes = array_keys($workerIds, $workerB->id);
    $check('Customer on B\'s route that day -> B\'s times come first', $bTimes && $bTimes === range(0, count($bTimes) - 1), $offered($result));
    $check('B\'s times all fit the Katy trip (each adds <= 5 min)', max(array_intersect_key(array_column($result['options'], 'extra_drive_minutes'), array_flip($bTimes))) <= 5, $offered($result));
    $values = array_column($result['options'], 'value');
    $check('Each time offered once, with the worker it suits best', count($values) === count(array_unique($values)), $offered($result));

    // B fully booked in Katy except 09:00: the best 3 mix B's 09:00 with A's times.
    config(['slot_finder.slots_to_offer' => 3]);
    $extraVisits = [$visitAt($workerB, '13:00', 29.7858, -95.8245), $visitAt($workerB, '17:00', 29.7858, -95.8245)];
    $mixed = $finder->suggest($katy, $nowC);
    $check('Best 3 from different workers: B at 09:00 first, then A', count($mixed['options']) === 3 && ($mixed['options'][0]['worker_id'] ?? null) === $workerB->id && substr($mixed['options'][0]['value'], 11) === '09:00' && in_array($workerA->id, array_column($mixed['options'], 'worker_id'), true), $offered($mixed));
    foreach ($extraVisits as $extraVisit) {
        $extraVisit->delete();
    }
    config(['slot_finder.slots_to_offer' => 50]);

    // Sugar Land: some of B's times are cheaper than others. With the cap at
    // the cheapest one's detour, those come first and the rest top up the offer.
    $sugarLand = ['lat' => 29.6197, 'lon' => -95.6349, 'precision' => 'address'];
    $open = array_column($finder->suggest($sugarLand, $nowC)['options'], 'extra_drive_minutes');
    config(['slot_finder.max_extra_drive_minutes' => min($open)]);
    $extras = array_column($finder->suggest($sugarLand, $nowC)['options'], 'extra_drive_minutes');
    $withinCap = array_map(fn ($extra) => $extra <= min($open), $extras);
    $capFirst = $withinCap;
    rsort($capFirst);
    $check('Detour cap: times within the cap first, then topped up with the rest', count($extras) === count($open) && $withinCap === $capFirst && in_array(false, $withinCap, true), 'uncapped '.json_encode($open).', capped '.json_encode($extras));
    config(['slot_finder.max_extra_drive_minutes' => 5]);

    // Customer in Galveston: every time is a long detour, so the cap doesn't
    // leave the customer without times; the cheapest are offered.
    $far = $finder->suggest(['lat' => 29.3013, 'lon' => -94.7977, 'precision' => 'address'], $nowC);
    $check('Far customer: still offered times (cheapest first)', $far['status'] === SlotSuggestionService::OK && count($far['options']) > 0 && min(array_column($far['options'], 'extra_drive_minutes')) > 5, $far['status'].' '.json_encode(array_column($far['options'], 'extra_drive_minutes')));
    $check('Far customer: best time is A\'s, who is already working east (Baytown)', ($far['options'][0]['worker_id'] ?? null) === $workerA->id, $offered($far));
    config(['slot_finder.max_extra_drive_minutes' => 45]);

    $check('No outgoing HTTP during the whole check', Http::recorded()->isEmpty(), Http::recorded()->count().' requests');

    // =====================================================================
    $section = 'Cal.com booking page: address';
    // =====================================================================
    // A fake Cal.com that answers the three link requests; nothing real is called.
    config(['calcom.api_key' => 'check-key']);
    $fakeCalcom = function (array $eventTypeLocations) {
        Http::swap(new Illuminate\Http\Client\Factory);
        Http::fake([
            '*/v2/schedules' => Http::response(['data' => ['id' => 11]]),
            '*/v2/event-types' => Http::response(['data' => ['id' => 22, 'locations' => $eventTypeLocations]]),
            '*/v2/event-types/*/private-links' => Http::response(['data' => ['bookingUrl' => 'https://cal.com/d/check/home-visit']]),
            '*' => Http::response([], 500),
        ]);
    };
    $linkService = app(Webkul\Admin\Support\Contact\CalcomBookingLinkService::class);
    $sentTo = fn (string $suffix) => Http::recorded()->first(fn ($pair) => str_ends_with($pair[0]->url(), $suffix));

    // The check contact has no address stored.
    $fakeCalcom([['type' => 'address', 'address' => "Customer's home", 'public' => false]]);
    $url = $linkService->createBookingLink($person, ['2027-03-10T09:00' => $workerA->id, '2027-03-10T13:00' => $workerB->id], $tz, []);
    $eventType = $sentTo('/v2/event-types');
    $sent = $eventType ? $eventType[0]->data() : [];

    // The customer books 13:00 (B's time), then moves to 09:00 (A's time).
    $link = DB::table('calcom_booking_links')->where('event_type_slug', $sent['slug'] ?? '')->first();
    $check('Link saves which worker goes with each time', $link && json_decode($link->slot_workers, true) === ['2027-03-10T09:00' => $workerA->id, '2027-03-10T13:00' => $workerB->id], json_encode($link));
    $sync->upsert($booking('chk-w1', '2027-03-10T19:00:00Z', '2027-03-10T20:00:00Z', ['eventType' => ['slug' => $sent['slug'] ?? '']]));
    $booked = DB::table('activities')->where('additional->calcom->uid', 'chk-w1')->value('id');
    $check('Customer books 13:00 -> B (that time\'s worker) is assigned', $booked && $pivot($booked) === [$workerB->id], json_encode($booked ? $pivot($booked) : null));
    $sync->upsert($booking('chk-w1', '2027-03-10T15:00:00Z', '2027-03-10T16:00:00Z', ['eventType' => ['slug' => $sent['slug'] ?? ''], 'updatedAt' => '2027-01-05T00:00:00.000Z']));
    $check('Customer moves to 09:00 -> A replaces B', $booked && $pivot($booked) === [$workerA->id], json_encode($booked ? $pivot($booked) : null));
    $check('Location is the stored address, hidden (none stored: "Customer\'s home"); no video link', ($sent['locations'] ?? null) === [['type' => 'address', 'address' => "Customer's home", 'public' => false]], json_encode($sent['locations'] ?? null));
    $askedLabels = array_column(array_filter($sent['bookingFields'] ?? [], fn ($f) => ($f['field'] ?? '') === 'custom'), 'label');
    $check('No street address stored -> the address is asked without ticking it', in_array('Address', $askedLabels, true), json_encode($askedLabels));
    $check('Link is created', $url === 'https://cal.com/d/check/home-visit', (string) $url);

    // A contact with a street address: that address, and no address questions.
    $withStreet = app(PersonRepository::class)->create([
        'entity_type' => 'persons', 'name' => 'Check Street Customer',
        'emails' => [['label' => 'work', 'value' => 'street.customer@txn-check.example']],
        'contact_numbers' => [['label' => 'work', 'value' => '555-0198']], 'user_id' => $adminId,
        'csv_address' => '301 Main St', 'csv_city' => 'Katy', 'csv_state_province' => 'TX', 'csv_postal_code' => '77494',
    ]);
    $fakeCalcom([['type' => 'address', 'address' => '301 Main St, Katy, TX 77494', 'public' => false]]);
    $linkService->createBookingLink($withStreet, ['2027-03-10T09:00' => $workerA->id], $tz, []);
    $sent = ($sentTo('/v2/event-types') ?? [null])[0]?->data() ?? [];
    $askedLabels = array_column(array_filter($sent['bookingFields'] ?? [], fn ($f) => ($f['field'] ?? '') === 'custom'), 'label');
    $check('Street address stored -> it is the location; nothing about the address is asked', ($sent['locations'][0]['address'] ?? null) === '301 Main St, Katy, TX 77494' && $askedLabels === [], json_encode([$sent['locations'] ?? null, $askedLabels]));

    // Only a ZIP stored: every address field is asked, the stored ZIP too.
    $zipOnly = app(PersonRepository::class)->create([
        'entity_type' => 'persons', 'name' => 'Check Zip Customer',
        'emails' => [['label' => 'work', 'value' => 'zip.customer@txn-check.example']],
        'contact_numbers' => [['label' => 'work', 'value' => '555-0197']], 'user_id' => $adminId,
        'csv_postal_code' => '77591',
    ]);
    $askedCodes = array_column($linkService->addressPlan($zipOnly)['auto_fields'], 'code');
    sort($askedCodes);
    $check('Only a ZIP stored -> Address, City, State and Postal Code are all asked', $askedCodes === ['csv_address', 'csv_city', 'csv_postal_code', 'csv_state_province'], json_encode($askedCodes));

    $plan = $linkService->addressPlan(app(PersonRepository::class)->create([
        'entity_type' => 'persons', 'name' => 'Check Eagle Customer',
        'emails' => [['label' => 'work', 'value' => 'eagle.customer@txn-check.example']],
        'contact_numbers' => [['label' => 'work', 'value' => '555-0196']], 'user_id' => $adminId,
        'csv_address' => '75051 150 Eagle Road', 'csv_city' => 'Houston', 'csv_state_province' => 'Texas', 'csv_postal_code' => '77022',
    ]));
    $check('"75051 150 Eagle Road" counts as a street address -> nothing asked', $plan['has_street'] && $plan['auto_fields'] === [], json_encode($plan));

    $fakeCalcom([['type' => 'integration', 'integration' => 'cal-video']]);
    try {
        $linkService->createBookingLink($person, ['2027-03-10T09:00' => $workerA->id], $tz, []);
        $refused = false;
    } catch (Webkul\Admin\Support\Contact\CalcomApiException) {
        $refused = true;
    }
    $check('Cal.com adds a location on its own -> no link is created', $refused && ! $sentTo('/private-links'), $refused ? 'refused' : 'link created');
} catch (Throwable $exception) {
    $check('Check script crashed', false, get_class($exception).': '.$exception->getMessage().' @ '.basename($exception->getFile()).':'.$exception->getLine());
} finally {
    DB::rollBack();
}

// =========================================================================
// Report
// =========================================================================
$failed = 0;
$checks = 0;
$current = null;

foreach ($results as $result) {
    if ($result['section'] !== $current) {
        $current = $result['section'];
        echo "\n== {$current}\n";
    }

    if ($result['ok'] === null) {
        echo "  INFO  {$result['name']}  [{$result['detail']}]\n";

        continue;
    }

    $checks++;
    $failed += $result['ok'] ? 0 : 1;
    echo ($result['ok'] ? '  PASS  ' : '  FAIL  ').$result['name'].($result['detail'] !== '' && ! $result['ok'] ? "  [{$result['detail']}]" : '')."\n";
}

echo "\n{$checks} checks, ".($checks - $failed)." passed, {$failed} failed. Database rolled back.\n";

exit(min($failed, 255));
