<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Console Routes
|--------------------------------------------------------------------------
|
| This file is where you may define all of your Closure based console
| commands. Each Closure is bound to a command instance allowing a
| simple approach to interacting with each command's IO methods.
|
*/

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Same as the "Sync Cal.com bookings" button (nothing syncs in the background).
Artisan::command('calcom:sync-bookings', function (Webkul\Admin\Support\Contact\CalcomBookingSyncService $sync) {
    // Shares the button's lock: two syncs at once could copy a booking twice.
    $lock = Illuminate\Support\Facades\Cache::lock('calcom-booking-sync', 120);

    if (! $lock->get()) {
        $this->warn('A Cal.com sync is already running.');

        return 1;
    }

    try {
        $counts = $sync->sync();
    } catch (Webkul\Admin\Support\Contact\CalcomApiException $exception) {
        $this->error($exception->getMessage());

        return 1;
    } finally {
        $lock->release();
    }

    $this->info(collect($counts)->map(fn ($count, $key) => "{$key}: {$count}")->implode(', '));
})->purpose('Copy Cal.com bookings into Krayin as appointment activities');

Artisan::command('txn:demo-appointments {--remove}', function (Webkul\Admin\Support\Contact\DemoAppointmentSeeder $seeder) {
    if ($this->option('remove')) {
        $counts = $seeder->remove();
        $this->info("Removed {$counts['customers']} demo customers and {$counts['appointments']} demo appointments.");

        return;
    }

    $result = $seeder->create();
    $this->info("Booked {$result['appointments']} demo visits for real leads ({$result['done']} already done) and {$result['busy']} busy blocks.");

    foreach ($result['skipped'] as $status => $count) {
        $this->warn("{$count} ".Illuminate\Support\Str::plural('lead', $count)." not booked: {$status}");
    }
})->purpose('Book (or --remove) demo visits for the real leads, through the slot finder');

Artisan::command('txn:check-schedules {--from= : first day, YYYY-MM-DD (default 7 days ago)} {--days=45}', function (Webkul\Admin\Support\Contact\WorkerScheduleChecker $checker) {
    $timezone = config('slot_finder.timezone');
    $from = $this->option('from')
        ? Carbon\CarbonImmutable::createFromFormat('!Y-m-d', $this->option('from'), $timezone)
        : Carbon\CarbonImmutable::now($timezone)->startOfDay()->subDays(7);
    $to = $from->addDays((int) $this->option('days'));

    $problems = $checker->check($from, $to);
    $this->info("Worker schedules {$from->toDateString()} to {$to->subDay()->toDateString()}: ".count($problems).' '.Illuminate\Support\Str::plural('problem', count($problems)).'.');

    if ($problems) {
        $this->table(['Worker', 'Activity', 'Start', 'Problem'], $problems);
    }

    return $problems ? 1 : 0;
})->purpose('List conflicts in the workers\' schedules (overlaps, bad times, visits outside hours)');

Artisan::command('txn:triage-leads {--lead=* : only these lead ids} {--new : only leads in Raw Lead} {--dry-run : show the results, change nothing}', function (Webkul\Admin\Support\Contact\LeadTriageService $triage) {
    $query = Webkul\Lead\Models\Lead::query()->with('stage')->orderBy('id');

    if ($ids = $this->option('lead')) {
        $query->whereIn('id', $ids);
    }

    if ($this->option('new')) {
        $query->whereHas('stage', fn ($stage) => $stage->where('code', 'new'));
    }

    $dryRun = (bool) $this->option('dry-run');
    $rows = [];
    $counts = ['ready' => 0, 'waiting for roof report' => 0, 'review' => 0, 'later stage' => 0, 'moved stage' => 0, 'contacts cleaned' => 0];

    // A dry run does everything inside a transaction that is rolled back.
    if ($dryRun) {
        Illuminate\Support\Facades\DB::beginTransaction();
    }

    try {
        foreach ($query->get() as $lead) {
            $result = $triage->check($lead);
            $counts[match (true) {
                $result['review'] => 'review',
                str_starts_with($result['next_action'], 'None') => 'later stage',
                str_starts_with($result['next_action'], 'Get the roof report') => 'waiting for roof report',
                default => 'ready',
            }]++;
            $counts['moved stage'] += $result['moved'] ? 1 : 0;
            $counts['contacts cleaned'] += $result['changes'] ? 1 : 0;
            $rows[] = [
                $result['lead_id'],
                Illuminate\Support\Str::limit((string) $result['service'], 28),
                $result['category'],
                $result['location'],
                implode(', ', $result['missing']) ?: '-',
                Illuminate\Support\Str::limit($result['next_action'], 70),
                implode(', ', $result['changes']) ?: '-',
            ];
        }
    } finally {
        if ($dryRun) {
            Illuminate\Support\Facades\DB::rollBack();
        }
    }

    $this->table(['Lead', 'Service', 'Category', 'Location', 'Missing', 'Next action', 'Cleaned'], $rows);
    $this->info(($dryRun ? 'DRY RUN, nothing saved. ' : '').count($rows).' leads: '.collect($counts)->map(fn ($n, $k) => "{$n} {$k}")->implode(', ').'.');
})->purpose('Lead check: clean contact data, classify the service, check the location, set the next action');

// Follow-ups for emailed booking links (Support\Contact\FollowUpService):
// 24h reminder, 72h final, then Lost. Nothing runs it automatically yet.
Artisan::command('txn:follow-ups {--dry-run : show what is due, send and change nothing}', function (Webkul\Admin\Support\Contact\FollowUpService $followUps) {
    $dryRun = (bool) $this->option('dry-run');
    $rows = $followUps->run($dryRun);

    if ($rows) {
        $this->table(['#', 'Lead', 'Contact', 'Action', 'Result'], array_map(fn ($row) => [
            $row['id'], $row['lead'], Illuminate\Support\Str::limit($row['contact'], 28), $row['action'], Illuminate\Support\Str::limit($row['result'], 90),
        ], $rows));
    }

    $counts = collect($rows)->countBy('action');
    $this->info(($dryRun ? 'DRY RUN, nothing sent or changed. ' : '').count($rows).' open follow-up(s)'
        .($counts->isNotEmpty() ? ': '.$counts->map(fn ($n, $action) => "{$n} {$action}")->implode(', ') : '').'.');
})->purpose('Send due booking-link follow-ups (24h reminder, 72h final) and mark unanswered leads Lost');

Schedule::command('inbound-emails:process')->everyFiveMinutes();
