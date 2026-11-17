<?php

namespace Webkul\Admin\Support\Contact;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Webkul\Contact\Models\Person;
use Webkul\Lead\Models\Lead;

/**
 * Imports Yelp/Angi leads from the lead database (MySQL integration.`lead`,
 * filled by TXNHome) with the same rules as the CSV import. Krayin only reads
 * that database: the "lead_db" connection (config/lead_triage.php) uses a user
 * with SELECT on `lead` only.
 *
 * Started by TXNHome's "lead arrived" call (LeadWebhookController), the
 * "Import from lead database" button and `php artisan txn:import-leads`.
 * Leads are matched by their [TXN-…] title tag, so importing again never
 * duplicates; a lead that can't be imported is skipped and logged.
 */
class LeadDbImportService
{
    /** The lead database columns the CSV import reads (same names as the CSV export). */
    private const COLUMNS = [
        'id', 'source', 'source_lead_id', 'first_name', 'last_name', 'email', 'email_is_proxied', 'phone',
        'address', 'city', 'state_province', 'postal_code', 'service_name', 'comments', 'received_at',
    ];

    public function __construct(private LeadCsvImportService $import) {}

    /** One lead, by its source and the source's lead id. */
    public function importOne(string $source, string $sourceLeadId, bool $dryRun = false): array
    {
        return $this->run(fn ($query) => $query
            ->where('source', strtoupper($source))
            ->where('source_lead_id', $sourceLeadId), $dryRun);
    }

    /** Leads received in the last $hours hours; null = every lead. */
    public function importRecent(?int $hours, bool $dryRun = false): array
    {
        return $this->run(fn ($query) => $hours === null
            ? $query
            // MySQL's own clock, so the time zone always matches received_at.
            : $query->whereRaw('received_at >= NOW() - INTERVAL ? HOUR', [$hours]), $dryRun);
    }

    /**
     * One-time fix for contacts imported while testing: puts the customers'
     * real emails and phone numbers back from the lead database.
     *
     * For each contact of a [TXN-…] lead: emails = the real addresses of its
     * leads (newest lead first), then any other real address staff added;
     * test.invalid placeholders are dropped. Phones the same way. If the lead
     * database has no usable email, the current emails are kept (Krayin needs
     * one). Contacts without a [TXN-…] lead (e.g. demo data) are not touched.
     * Saved directly, so no "re-check after edit" or workflow runs; sending is
     * still limited by the mail/SMS guards (TXN_TEST_MODE).
     *
     * @return array{contacts: int, changed: int, unchanged: int, not_found: int, skipped: int,
     *               errors: list<string>, changed_ids: list<int>, dry_run: bool}
     */
    public function restoreContacts(bool $dryRun = false): array
    {
        $result = ['contacts' => 0, 'changed' => 0, 'unchanged' => 0, 'not_found' => 0, 'skipped' => 0,
            'errors' => [], 'changed_ids' => [], 'dry_run' => $dryRun];

        $source = DB::connection('lead_db')->table('lead')
            ->select(['id', 'source', 'source_lead_id', 'email', 'phone'])
            ->get()
            ->keyBy(fn ($row) => strtoupper($row->source).'|'.$row->source_lead_id);

        // contact id => that contact's lead-database rows
        $byPerson = [];

        foreach (Lead::query()->whereNotNull('person_id')->where('title', 'like', '%[TXN-%')->get(['id', 'title', 'person_id']) as $lead) {
            if (! preg_match('/\[TXN-(ANGI|YELP)-([^\]\s]+)\]/', (string) $lead->title, $match)) {
                continue;
            }

            $byPerson[$lead->person_id][] = $source[$match[1].'|'.rawurldecode($match[2])] ?? null;
        }

        foreach ($byPerson as $personId => $rows) {
            $result['contacts']++;
            $rows = collect($rows)->filter()->sortByDesc('id');
            $person = Person::find($personId);

            if (! $person || $rows->isEmpty()) {
                $result['not_found']++;

                continue;
            }

            $current = fn (string $field, callable $clean) => collect($person->{$field} ?? [])
                ->map(fn ($entry) => $clean($entry['value'] ?? null))
                ->filter();

            $emails = $rows->map(fn ($row) => ContactDataNormalizer::email($row->email))->filter()
                ->merge($current('emails', [ContactDataNormalizer::class, 'email'])
                    ->reject(fn ($email) => str_ends_with($email, '@test.invalid')))
                ->unique()->values();

            $phones = $rows->map(fn ($row) => ContactDataNormalizer::phone($row->phone))->filter()
                ->merge($current('contact_numbers', [ContactDataNormalizer::class, 'phone']))
                ->unique()->values();

            $newEmails = $emails->isEmpty()
                ? array_values($person->emails ?? [])
                : $emails->map(fn ($value) => ['label' => 'Work', 'value' => $value])->all();
            $newPhones = $phones->map(fn ($value) => ['label' => 'Work', 'value' => $value])->all();

            // == (not ===): the database's JSON column stores the keys in its
            // own order (label, value), so only the content may be compared.
            if ($newEmails == array_values($person->emails ?? []) && $newPhones == array_values($person->contact_numbers ?? [])) {
                $result['unchanged']++;

                continue;
            }

            $person->emails = $newEmails;
            $person->contact_numbers = $newPhones ?: null;
            $person->unique_id = LeadCsvImportService::uniqueId($person);

            if (Person::query()->where('unique_id', $person->unique_id)->whereKeyNot($person->id)->exists()) {
                $result['skipped']++;
                $result['errors'][] = "contact {$person->id}: another contact already has the same email and phone; merge them in Krayin, then run this again";

                continue;
            }

            if (! $dryRun) {
                try {
                    $person->save();
                } catch (\Throwable $exception) {
                    $result['skipped']++;
                    $result['errors'][] = "contact {$person->id}: ".($exception->errorInfo[2] ?? $exception->getMessage());
                    Log::error("Restore contacts: contact {$person->id} not saved.", ['exception' => $exception]);

                    continue;
                }
            }

            $result['changed']++;
            $result['changed_ids'][] = $person->id;
        }

        return $result;
    }

    private function run(callable $filter, bool $dryRun): array
    {
        // One import at a time (TXNHome call, button, cron); waits up to 60 s
        // for a running one, then throws LockTimeoutException.
        return Cache::lock('txn-lead-db-import', 600)->block(60, function () use ($filter, $dryRun) {
            $rows = $filter(DB::connection('lead_db')->table('lead')->select(self::COLUMNS))
                ->orderBy('id')
                ->get()
                ->map(fn ($row) => (array) $row)
                ->all();

            return $this->import->importRowsSkippingBad($rows, $dryRun);
        });
    }
}
