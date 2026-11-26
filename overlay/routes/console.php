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

// Same as the "Sync Cal.com bookings" button. The Cal.com webhook (Automation) brings bookings in at once when it is on.
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

Artisan::command('txn:triage-leads {--lead=* : only these lead ids} {--new : only leads in Raw Lead or Needs Review} {--dry-run : show the results, change nothing}', function (Webkul\Admin\Support\Contact\LeadTriageService $triage) {
    $query = Webkul\Lead\Models\Lead::query()->with('stage')->orderBy('id');

    if ($ids = $this->option('lead')) {
        $query->whereIn('id', $ids);
    }

    if ($this->option('new')) {
        // By stage role (Stage rules), like the "Check new leads" button.
        $query->whereIn('lead_pipeline_stage_id', app(Webkul\Admin\Support\Contact\StageRoles::class)->stageIds([
            Webkul\Admin\Support\Contact\StageRoles::NEW,
            Webkul\Admin\Support\Contact\StageRoles::NEEDS_REVIEW,
        ]));
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

    if (! $dryRun && $rows) {
        Webkul\Admin\Support\Contact\PipelineEvents::record('leads_checked', 'Checked '.count($rows).' '.(count($rows) === 1 ? 'lead' : 'leads').": {$counts['ready']} ready, {$counts['review']} need review"
            .($counts['waiting for roof report'] ? ", {$counts['waiting for roof report']} waiting for a roof report" : ''), ['checked' => count($rows)] + $counts);
    }

    $this->table(['Lead', 'Service', 'Category', 'Location', 'Missing', 'Next action', 'Cleaned'], $rows);
    $this->info(($dryRun ? 'DRY RUN, nothing saved. ' : '').count($rows).' leads: '.collect($counts)->map(fn ($n, $k) => "{$n} {$k}")->implode(', ').'.');
})->purpose('Lead check: clean contact data, classify the service, check the location, set the next action');

// Follow-ups for emailed booking links (Support\Contact\FollowUpService):
// 24h reminder, 72h final, then Lost, by hand, for follow-ups NOT on the schedule (sent before it, or with the Email engine off). Scheduled ones are sent by MessageScheduler at their times.
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

// Leads from the lead database (MySQL integration, filled by TXNHome). TXNHome
// also calls /txn/lead-arrived for each new lead and retries that call for
// about 12 minutes; this command (and the "Import from lead database" button)
// is the manual catch-up. No cron runs it.
Artisan::command('txn:import-leads {--hours=48 : leads received in the last N hours} {--all : every lead} {--lead= : one lead, SOURCE:ID (e.g. YELP:abc123)} {--dry-run : show the results, save nothing}', function (Webkul\Admin\Support\Contact\LeadDbImportService $import) {
    $dryRun = (bool) $this->option('dry-run');

    if ($lead = $this->option('lead')) {
        [$source, $id] = explode(':', $lead, 2) + [1 => ''];
        $result = $import->importOne($source, $id, $dryRun);
    } else {
        $result = $import->importRecent($this->option('all') ? null : (int) $this->option('hours'), $dryRun);
    }

    foreach ($result['errors'] as $error) {
        $this->warn("Skipped {$error}");
    }

    $this->info(($dryRun ? 'DRY RUN, nothing saved. ' : '').now()->toDateTimeString().": {$result['rows']} leads: {$result['created']} new, "
        ."{$result['updated']} updated, {$result['unchanged']} unchanged, {$result['skipped']} skipped. "
        ."Lead check: {$result['checked']} checked, {$result['review']} need review"
        .($result['check_left'] ? ", {$result['check_left']} left for \"Check new leads\"" : '').'.');

    return $result['skipped'] ? 1 : 0;
})->purpose('Import leads from the lead database (MySQL integration) into Krayin');

// One-time: real emails and phone numbers back on contacts imported while testing.
// Prints contact ids only (no customer details).
Artisan::command('txn:restore-contacts {--dry-run : show what would change, save nothing}', function (Webkul\Admin\Support\Contact\LeadDbImportService $import) {
    $result = $import->restoreContacts((bool) $this->option('dry-run'));

    foreach ($result['errors'] as $error) {
        $this->warn("Skipped {$error}");
    }

    if ($result['changed_ids']) {
        $this->line(($result['dry_run'] ? 'Would change' : 'Changed').' contacts: '.implode(', ', $result['changed_ids']));
    }

    $this->info(($result['dry_run'] ? 'DRY RUN, nothing saved. ' : '')."{$result['contacts']} contacts with Yelp/Angi leads: "
        ."{$result['changed']} ".($result['dry_run'] ? 'to change' : 'changed').", {$result['unchanged']} already correct, "
        ."{$result['not_found']} not in the lead database, {$result['skipped']} skipped.");

    return $result['skipped'] ? 1 : 0;
})->purpose('Put the real emails and phone numbers from the lead database back on Yelp/Angi contacts');


// Krayin's own inbound-emails:process schedule was removed: it fails with the
// SendGrid receiver. Customer replies are read by txn:fetch-replies below
// (Microsoft 365, every mailbox on Settings > TXN Settings > Mailboxes).

// Email replies (Support\Replies\EmailReplyImporter): reads new emails in
// every mailbox and adds them to their leads and Mail > Inbox. Read-only.
// Same as "Check now" on the Mailboxes screen.
Artisan::command('txn:fetch-replies {--mailbox= : only this mailbox (address or ID)}', function (Webkul\Admin\Support\Replies\EmailReplyImporter $importer) {
    $only = $this->option('mailbox');
    $id = null;

    if ($only !== null && $only !== '') {
        $id = (int) (is_numeric($only) ? $only : Illuminate\Support\Facades\DB::table('txn_mailboxes')->where('address', strtolower(trim($only)))->value('id'));

        if (! $id) {
            $this->error("No mailbox {$only} on Settings > TXN Settings > Mailboxes.");

            return 1;
        }
    }

    $results = Webkul\Admin\Support\Contact\PipelineEvents::as('Email check', 'scheduler', fn () => $importer->run($id));

    if (! $results) {
        $this->warn('No mailbox reads replies (Settings > TXN Settings > Mailboxes).');
    }

    foreach ($results as $result) {
        $line = trim(($result['address'] ? "{$result['address']}: " : '').$result['message']);
        $result['ok'] ? $this->info($line) : $this->error($line);
    }

    return collect($results)->every(fn ($result) => $result['ok']) ? 0 : 1;
})->purpose('Read new customer emails from every mailbox and add them to their leads');

// Demo of email replies (Support\Replies\DemoEmailReplies): demo contact and
// lead, a "sent" booking email and made-up replies run through the real
// importer. Nothing is sent, Microsoft 365 is not contacted. --remove deletes it.
Artisan::command('txn:demo-email-replies {--remove : delete the demo contact, lead, emails and events}', function (Webkul\Admin\Support\Replies\DemoEmailReplies $demo) {
    if ($this->option('remove')) {
        $this->info('Demo email replies removed ('.$demo->remove().' rows).');

        return 0;
    }

    $result = $demo->create();
    $labels = ['imported' => 'added to the demo lead', 'no_lead' => 'in Mail > Inbox, no lead', 'skipped' => 'skipped (auto-reply)', 'duplicate' => 'already there'];

    $this->info("Demo lead #{$result['lead_id']} \"".Webkul\Admin\Support\Replies\DemoEmailReplies::LEAD_TITLE."\" and contact #{$result['person_id']} \"Demo Reply Customer\" created.");
    $this->table(['Demo email', 'Result'], collect($result['results'])->map(fn ($status, $name) => [$name, $labels[$status] ?? $status])->values()->all());
    $this->line('See Mail > Inbox and the demo lead. Remove it all with: php artisan txn:demo-email-replies --remove');

    return 0;
})->purpose('Create demo email replies (nothing is sent) to see how replies show in Krayin');

// Runs only while "Email replies" is on (Automation), at its interval.
Schedule::command('txn:fetch-replies')
    ->everyMinute()
    ->withoutOverlapping(15)
    ->runInBackground()
    ->when(fn () => Webkul\Admin\Support\Replies\EmailReplyImporter::due());

// Follow-up schedule (Support\Automation\MessageScheduler): each follow-up is
// sent at its exact time by the krayin-queue worker; nothing polls. This puts
// a job back for every pending entry (after a restore, or if a job was lost).
// deploy.sh runs it after each deploy; running it twice is harmless.
Artisan::command('txn:schedule-repair', function (Webkul\Admin\Support\Automation\MessageScheduler $scheduler) {
    $count = $scheduler->repair();
    $this->info("Follow-up schedule: {$count} pending ".($count === 1 ? 'entry' : 'entries').' queued for their times.');
})->purpose('Re-queue the jobs of every pending scheduled follow-up');
