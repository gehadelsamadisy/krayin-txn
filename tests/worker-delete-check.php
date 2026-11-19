<?php

/*
 * Delete worker check (Contacts > Workers > Edit > Delete this worker): a
 * worker who was never used is deleted with their time off; a worker with an
 * appointment, or offered by a booking link, is refused and stays.
 *
 * Run inside the Krayin container, after the migrations:
 *   php tests/worker-delete-check.php
 *
 * One database transaction, rolled back at the end. Nothing is sent.
 * Exit code = number of failed checks.
 */

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Webkul\Admin\Models\Worker;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->bootstrap();
$app->instance('request', Request::create('/admin'));
config(['mail.default' => 'array', 'queue.default' => 'sync', 'cache.default' => 'array']);

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
$check = function (string $name, bool $ok, string $detail = '') use (&$results) {
    $results[] = compact('name', 'ok', 'detail');
};

DB::beginTransaction();

try {
    $adminId = DB::table('users')->join('roles', 'roles.id', '=', 'users.role_id')
        ->where('roles.permission_type', 'all')->where('users.status', 1)->value('users.id');
    auth()->guard('user')->setUser(Webkul\User\Models\User::find($adminId));

    $make = fn (string $name) => Worker::query()->create([
        'name' => $name, 'is_active' => true, 'start_address' => '1 Check St, Houston, TX',
        'home_latitude' => 29.76, 'home_longitude' => -95.37, 'priority' => 9,
    ]);
    $deleteUrl = fn (Worker $worker) => route('admin.contacts.workers.delete', $worker->id, false);

    // Never used: deleted, with its time off.
    $unused = $make('Delete Check Unused');
    DB::table('worker_time_offs')->insert(['worker_id' => $unused->id, 'start_date' => '2030-01-02', 'end_date' => '2030-01-03', 'type' => 'other', 'created_at' => now(), 'updated_at' => now()]);
    $edit = $call('GET', route('admin.contacts.workers.edit', $unused->id, false));
    $check('Edit page shows "Delete this worker", button enabled', $edit->getStatusCode() === 200 && str_contains($edit->getContent(), 'Delete this worker') && str_contains($edit->getContent(), 'they can be deleted'), 'HTTP '.$edit->getStatusCode());
    $response = $call('DELETE', $deleteUrl($unused));
    $check('Unused worker -> deleted', ! Worker::query()->whereKey($unused->id)->exists() && $response->isRedirect());
    $check('... with their time off', DB::table('worker_time_offs')->where('worker_id', $unused->id)->doesntExist());

    // With an appointment: refused.
    $busy = $make('Delete Check Busy');
    $activityId = DB::table('activities')->insertGetId([
        'title' => 'Delete check visit', 'type' => 'meeting', 'is_done' => 0, 'user_id' => $adminId,
        'schedule_from' => '2030-01-05 09:00:00', 'schedule_to' => '2030-01-05 10:00:00', 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('activity_workers')->insert(['activity_id' => $activityId, 'worker_id' => $busy->id]);
    $edit = $call('GET', route('admin.contacts.workers.edit', $busy->id, false));
    $check('Worker with an appointment: page says why, button disabled', str_contains($edit->getContent(), 'be deleted: 1 appointment use them'));
    $call('DELETE', $deleteUrl($busy));
    $check('Worker with an appointment -> refused, still there', Worker::query()->whereKey($busy->id)->exists() && DB::table('activity_workers')->where('worker_id', $busy->id)->exists());

    // Older single worker_id column counts too.
    $legacy = $make('Delete Check Legacy');
    DB::table('activities')->insert([
        'title' => 'Delete check old visit', 'type' => 'meeting', 'is_done' => 1, 'user_id' => $adminId, 'worker_id' => $legacy->id,
        'schedule_from' => '2020-01-05 09:00:00', 'schedule_to' => '2020-01-05 10:00:00', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $call('DELETE', $deleteUrl($legacy));
    $check('Worker on a past (done) appointment -> refused', Worker::query()->whereKey($legacy->id)->exists());

    // Offered by a booking link: refused.
    $linked = $make('Delete Check Linked');
    $link = DB::table('calcom_booking_links')->first();
    if ($link) {
        DB::table('calcom_booking_links')->where('id', $link->id)->update(['slot_workers' => json_encode(['2030-01-06T09:00' => $linked->id])]);
        $call('DELETE', $deleteUrl($linked));
        $check('Worker offered by a booking link -> refused', Worker::query()->whereKey($linked->id)->exists());
    }

    $check('Worker list still opens', $call('GET', route('admin.contacts.workers.index', [], false))->getStatusCode() === 200);
} catch (Throwable $exception) {
    $check('Check script crashed', false, get_class($exception).': '.$exception->getMessage().' @ '.basename($exception->getFile()).':'.$exception->getLine());
} finally {
    DB::rollBack();
}

$failed = 0;
echo "\n== Delete worker\n";
foreach ($results as $result) {
    $failed += $result['ok'] ? 0 : 1;
    echo ($result['ok'] ? '  PASS  ' : '  FAIL  ').$result['name'].($result['detail'] !== '' && ! $result['ok'] ? "  [{$result['detail']}]" : '')."\n";
}
echo "\n".count($results).' checks, '.(count($results) - $failed)." passed, {$failed} failed. Database rolled back.\n";

exit(min($failed, 255));
