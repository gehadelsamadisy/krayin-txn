<?php

namespace Webkul\Admin\Support\Contact;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;
use Webkul\Attribute\Repositories\AttributeValueRepository;
use Webkul\Contact\Models\Person;
use Webkul\Contact\Repositories\PersonRepository;
use Webkul\Lead\Models\Lead;
use Webkul\Lead\Repositories\LeadRepository;
use Webkul\Lead\Repositories\PipelineRepository;

/**
 * "Import leads (CSV)" on the Leads list: upserts Yelp/Angi leads from a CSV
 * export (same columns and rules as the old Python importer), until leads are
 * read from the lead database directly. Settings: config/lead_triage.php
 * `import`.
 *
 * - The whole file is checked first; one bad row and nothing is saved.
 * - A lead is found by its "[TXN-ANGI-…]" / "[TXN-YELP-…]" title tag, so
 *   importing the same file again never duplicates leads.
 * - New lead: Raw Lead in the default pipeline. Its contact is the one with
 *   the same phone number if there is one, otherwise a new contact.
 * - Existing lead: the source facts (record id, source, received at, …) are
 *   refreshed; the service name and the contact's name, phone and address are
 *   filled only where empty, so staff corrections and booking-form answers
 *   are kept. Title, stage, pipeline and owner are never changed.
 * - Emails: real customer addresses are never stored while testing. A new
 *   contact gets contact{id}@test.invalid unless the address is one of the
 *   allowed test recipients (MAIL_ALLOWED_RECIPIENTS) or `store_real_emails`
 *   is on. Existing contacts' emails are never changed.
 * - New and changed leads then go through the lead check (LeadTriageService).
 */
class LeadCsvImportService
{
    public const REQUIRED_COLUMNS = [
        'id', 'source', 'source_lead_id', 'first_name', 'email', 'email_is_proxied', 'phone',
        'address', 'city', 'state_province', 'postal_code', 'service_name', 'comments', 'received_at',
    ];

    /** CSV column → contact address field. */
    private const ADDRESS_FIELDS = [
        'address' => 'csv_address',
        'city' => 'csv_city',
        'state_province' => 'csv_state_province',
        'postal_code' => 'csv_postal_code',
    ];

    public function __construct(
        private LeadRepository $leads,
        private PersonRepository $persons,
        private PipelineRepository $pipelines,
        private AttributeValueRepository $attributeValues,
        private LeadTriageService $triage,
    ) {}

    /**
     * @return array{rows: int, created: int, updated: int, unchanged: int, duplicates: int, checked: int,
     *               review: int, check_failed: int, check_left: int, errors: list<string>, stopped: bool, dry_run: bool}
     */
    public function import(string $path, bool $dryRun = false): array
    {
        [$rows, $duplicates, $errors] = $this->read($path);

        $result = [
            'rows' => count($rows) + $duplicates, 'created' => 0, 'updated' => 0, 'unchanged' => 0,
            'duplicates' => $duplicates, 'checked' => 0, 'review' => 0, 'check_failed' => 0, 'check_left' => 0,
            'errors' => $errors, 'stopped' => false, 'dry_run' => $dryRun,
        ];

        if ($errors) {
            return $result;
        }

        $started = microtime(true);
        $touched = [];

        DB::beginTransaction();

        try {
            foreach ($rows as $row) {
                try {
                    [$status, $leadId] = $this->upsert($row);
                } catch (RuntimeException $exception) {
                    throw $exception;
                } catch (Throwable $exception) {
                    Log::error("Lead import stopped at row {$row['line']}.", ['exception' => $exception]);
                    $reason = $exception->errorInfo[2] ?? $exception->getMessage();

                    throw new RuntimeException("Row {$row['line']} ({$row['marker']}): {$reason}", 0, $exception);
                }

                $result[$status]++;

                if ($status !== 'unchanged') {
                    $touched[] = $leadId;
                }
            }
        } catch (Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }

        if ($dryRun) {
            DB::rollBack();

            return $result;
        }

        DB::commit();

        // After the commit: a failed map lookup must not undo the import.
        // Stops at the time budget so the request ends before the server's
        // 300 s limit; the rest stays in Raw Lead for "Check new leads".
        $deadline = $started + config('lead_triage.import.check_seconds', 200);

        foreach ($touched as $leadId) {
            if (microtime(true) >= $deadline) {
                $result['check_left']++;

                continue;
            }

            try {
                $this->triage->check(Lead::findOrFail($leadId))['review'] && $result['review']++;
                $result['checked']++;
            } catch (Throwable $exception) {
                $result['check_failed']++;
                Log::warning("Lead import: lead check failed for lead {$leadId}.", ['error' => $exception->getMessage()]);
            }
        }

        return $result;
    }

    // ---------------------------------------------------------------------
    // Read and validate
    // ---------------------------------------------------------------------

    /** @return array{0: list<array>, 1: int, 2: list<string>} rows, duplicate rows, errors */
    private function read(string $path): array
    {
        $handle = @fopen($path, 'r');

        if (! $handle) {
            return [[], 0, ['The file could not be read.']];
        }

        try {
            $header = fgetcsv($handle, escape: '');

            if (! $header || $header === [null]) {
                return [[], 0, ['The file is empty.']];
            }

            $header = array_map(fn ($name) => strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $name))), $header);

            if ($missing = array_diff(self::REQUIRED_COLUMNS, $header)) {
                return [[], 0, ['This is not a lead export: missing columns '.implode(', ', $missing).'.']];
            }

            $rows = $seen = $errors = [];
            $duplicates = 0;
            $line = 1;

            while (($values = fgetcsv($handle, escape: '')) !== false) {
                $line++;

                if ($values === [null]) {
                    continue;
                }

                $row = ['line' => $line];

                foreach ($header as $index => $name) {
                    $row[$name] = trim((string) ($values[$index] ?? ''));
                }

                if ($error = $this->validate($row)) {
                    $errors[] = "Row {$line}: {$error}";

                    continue;
                }

                if (isset($seen[$row['marker']])) {
                    $duplicates++;

                    continue;
                }

                $seen[$row['marker']] = true;
                $rows[] = $row;
            }
        } finally {
            fclose($handle);
        }

        if (! $rows && ! $errors) {
            $errors[] = 'The file has no lead rows.';
        }

        return [$rows, $duplicates, $errors];
    }

    /** Checks one row and adds the derived values (marker, name, …); returns the problem or null. */
    private function validate(array &$row): ?string
    {
        $row['source'] = strtoupper($row['source']);

        if (! in_array($row['source'], ['ANGI', 'YELP'], true)) {
            return 'source must be ANGI or YELP.';
        }

        if ($row['source_lead_id'] === '') {
            return 'source_lead_id is empty.';
        }

        if ($row['first_name'] === '') {
            return 'first_name is empty.';
        }

        if (! preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $row['email'])) {
            return 'a valid email is required.';
        }

        try {
            $row['received_at'] = CarbonImmutable::parse($row['received_at'])->format('Y-m-d H:i:s');
        } catch (Throwable) {
            return 'received_at is not a date and time.';
        }

        $row['marker'] = '[TXN-'.$row['source'].'-'.rawurlencode($row['source_lead_id']).']';

        if (strlen($row['marker']) > 255) {
            return 'source_lead_id is too long.';
        }

        $row['name'] = trim($row['first_name'].' '.($row['last_name'] ?? ''));
        $row['phone_clean'] = ContactDataNormalizer::phone($row['phone']);
        $row['proxied'] = in_array(strtolower($row['email_is_proxied']), ['1', 'true', 'yes'], true) ? 1 : 0;

        return null;
    }

    // ---------------------------------------------------------------------
    // Upsert
    // ---------------------------------------------------------------------

    /** @return array{0: 'created'|'updated'|'unchanged', 1: int} */
    private function upsert(array $row): array
    {
        $escaped = addcslashes($row['marker'], '%_\\');
        $matches = Lead::query()->with('person')->where('title', 'like', "%{$escaped}%")->get()
            ->filter(fn (Lead $lead) => str_contains((string) $lead->title, $row['marker']));

        if ($matches->count() > 1) {
            throw new RuntimeException("Row {$row['line']}: {$matches->count()} leads already carry {$row['marker']}. Merge them, then import again. Nothing was saved.");
        }

        if ($lead = $matches->first()) {
            return [$this->updateLead($lead, $row) ? 'updated' : 'unchanged', $lead->id];
        }

        return ['created', $this->createLead($row)->id];
    }

    private function createLead(array $row): Lead
    {
        $email = $this->storableEmail($row['email']);
        $person = $this->personWithPhone($row['phone_clean']) ?? $this->personWithEmail($email);

        if ($person) {
            $this->fillPerson($person, $row);
        } else {
            $person = $this->createPerson($row, $email);
        }

        $pipeline = $this->pipelines->getDefaultPipeline();
        $stage = $pipeline->stages()->where('code', 'new')->first() ?? $pipeline->stages()->first();
        $settings = config('lead_triage.import');

        $prefix = implode(' - ', array_filter([$row['service_name'], $row['name']]));
        $title = trim(mb_substr($prefix, 0, 255 - strlen($row['marker']) - 1).' '.$row['marker']);

        return $this->leads->create([
            'entity_type' => 'leads',
            'title' => $title,
            'description' => $this->description($row),
            'lead_value' => 0,
            'status' => 1,
            'lead_source_id' => $settings['source_id'],
            'lead_type_id' => $settings['type_id'],
            'user_id' => $settings['owner_id'],
            'lead_pipeline_id' => $pipeline->id,
            'lead_pipeline_stage_id' => $stage->id,
            'person' => ['id' => $person->id],
            'csv_service_name' => $row['service_name'],
        ] + $this->sourceFacts($row));
    }

    /** Returns whether anything changed. */
    private function updateLead(Lead $lead, array $row): bool
    {
        $changed = $this->saveLeadFields($lead, $this->sourceFacts($row), onlyIfEmpty: false);
        $changed = $this->saveLeadFields($lead, ['csv_service_name' => $row['service_name']], onlyIfEmpty: true) || $changed;

        if ($lead->person) {
            $changed = $this->fillPerson($lead->person, $row) || $changed;
        }

        return $changed;
    }

    /** Facts about where the lead came from: always the CSV's values. */
    private function sourceFacts(array $row): array
    {
        return [
            'csv_record_id' => $row['id'],
            'csv_source' => $row['source'],
            'csv_source_lead_id' => $row['source_lead_id'],
            'csv_received_at' => $row['received_at'],
            'csv_email_is_proxied' => $row['proxied'],
        ];
    }

    private function saveLeadFields(Lead $lead, array $values, bool $onlyIfEmpty): bool
    {
        $changed = false;

        foreach ($values as $code => $value) {
            $attribute = DB::table('attributes')->where('entity_type', 'leads')->where('code', $code)->first();

            if (! $attribute || $value === '' || $value === null || ($onlyIfEmpty && ContactDataNormalizer::text($value) === null)) {
                continue;
            }

            $current = $lead->getCustomAttributeValue($attribute);

            if ($attribute->type === 'boolean') {
                $same = (int) (bool) $current === (int) $value;
            } else {
                $same = trim((string) $current) === (string) $value;
            }

            if ($same || ($onlyIfEmpty && trim((string) $current) !== '')) {
                continue;
            }

            $this->attributeValues->save(['entity_type' => 'leads', 'entity_id' => $lead->id, $code => $value], [$attribute]);
            $changed = true;
        }

        if ($changed) {
            $lead->unsetRelation('attribute_values');
        }

        return $changed;
    }

    // ---------------------------------------------------------------------
    // Contacts
    // ---------------------------------------------------------------------

    private function personWithPhone(?string $phone): ?Person
    {
        if ($phone === null) {
            return null;
        }

        return Person::query()
            ->where('contact_numbers', 'like', '%'.addcslashes($phone, '%_\\').'%')
            ->orderBy('id')
            ->get()
            ->first(fn (Person $person) => collect($person->contact_numbers ?? [])
                ->contains(fn ($entry) => ContactDataNormalizer::phone($entry['value'] ?? null) === $phone));
    }

    /** Only when real addresses are stored: the contact that already has this email. */
    private function personWithEmail(?string $email): ?Person
    {
        if ($email === null) {
            return null;
        }

        return Person::query()
            ->where('emails', 'like', '%'.addcslashes($email, '%_\\').'%')
            ->orderBy('id')
            ->get()
            ->first(fn (Person $person) => collect($person->emails ?? [])
                ->contains(fn ($entry) => strtolower(trim($entry['value'] ?? '')) === $email));
    }

    private function createPerson(array $row, ?string $email): Person
    {
        // Krayin's persons.unique_id (owner|organization|email|phone) must be
        // unique, so the placeholder is unique until the id is known.
        $person = $this->persons->create(array_filter([
            'entity_type' => 'persons',
            'name' => $row['name'],
            'emails' => [['value' => $email ?? 'pending-'.bin2hex(random_bytes(8)).'@test.invalid', 'label' => 'Work']],
            'contact_numbers' => $row['phone'] !== '' ? [['value' => $row['phone_clean'] ?? $row['phone'], 'label' => 'Work']] : null,
            'user_id' => config('lead_triage.import.owner_id'),
        ] + $this->addressValues($row), fn ($value) => $value !== null && $value !== ''));

        if ($email === null) {
            $person->emails = [['value' => "contact{$person->id}@test.invalid", 'label' => 'Work']];
            $person->unique_id = self::uniqueId($person);
            $person->save();
        }

        return $person;
    }

    /** Same rule as Krayin's PersonRepository: owner|organization|first email[|first phone]. */
    private static function uniqueId(Person $person): string
    {
        $id = implode('|', array_filter([$person->user_id, $person->organization_id, $person->emails[0]['value'] ?? null]));
        $phone = collect($person->contact_numbers ?? [])->first()['value'] ?? null;

        return $phone ? "{$id}|{$phone}" : $id;
    }

    /** Fills the contact's empty name, phone and address fields; never touches emails. */
    private function fillPerson(Person $person, array $row): bool
    {
        $updates = [];

        if (ContactDataNormalizer::text($person->name) === null && ContactDataNormalizer::text($row['name']) !== null) {
            $updates['name'] = $row['name'];
        }

        $numbers = $person->contact_numbers ?? [];
        $hasPhone = collect($numbers)->contains(fn ($entry) => ContactDataNormalizer::phone($entry['value'] ?? null));

        if (! $hasPhone && $row['phone_clean']) {
            $updates['contact_numbers'] = [['value' => $row['phone_clean'], 'label' => 'Work'], ...array_values($numbers)];
        }

        if ($updates) {
            $person->fill($updates)->save();
        }

        $changed = (bool) $updates;
        $attributes = collect(PersonGridColumnPreferences::customAttributes())->keyBy('code');

        foreach ($this->addressValues($row) as $code => $value) {
            $attribute = $attributes[$code] ?? null;

            // "N/A"-style values count as empty (the lead check clears them).
            if (! $attribute || ContactDataNormalizer::text($value) === null || trim((string) $person->getCustomAttributeValue($attribute)) !== '') {
                continue;
            }

            $this->attributeValues->save(['entity_type' => 'persons', 'entity_id' => $person->id, $code => $value], [$attribute]);
            $changed = true;
        }

        if ($changed) {
            $person->unsetRelation('attribute_values');
        }

        return $changed;
    }

    private function addressValues(array $row): array
    {
        $values = [];

        foreach (self::ADDRESS_FIELDS as $column => $code) {
            $values[$code] = $row[$column];
        }

        return $values;
    }

    /** The real address only if it may be stored; null → a test.invalid placeholder. */
    private function storableEmail(string $email): ?string
    {
        $email = strtolower($email);
        $allowed = array_map('strtolower', config('mail_guard.allowed_recipients', []));

        return ! config('txn.test_mode', true) || config('lead_triage.import.store_real_emails') || in_array($email, $allowed, true) ? $email : null;
    }

    private function description(array $row): string
    {
        $lines = [
            "Lead source: {$row['source']}",
            "Source lead ID: {$row['source_lead_id']}",
            "CSV record ID: {$row['id']}",
            "Service requested: {$row['service_name']}",
            'Email is proxied: '.($row['proxied'] ? 'yes' : 'no'),
            "Received at: {$row['received_at']}",
            "Address: {$row['address']}",
            "City: {$row['city']}",
            "State/province: {$row['state_province']}",
            "Postal code: {$row['postal_code']}",
        ];

        if ($row['comments'] !== '') {
            array_push($lines, '', 'Original lead message:', $row['comments']);
        }

        return implode("\n", $lines);
    }
}
