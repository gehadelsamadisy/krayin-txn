<?php

namespace Webkul\Admin\Support\Contact;

use Illuminate\Support\Facades\DB;
use Webkul\Attribute\Repositories\AttributeValueRepository;
use Webkul\Contact\Models\Person;
use Webkul\Lead\Models\Lead;
use Webkul\Tag\Models\TagProxy;

/**
 * The lead check: normalize the contact, classify the requested service,
 * check the location, then decide the next action (config/lead_triage.php).
 *
 * Results go into the lead's "Service Category", "Flow", "Location Check",
 * "Missing Info", "Next Action" and "Checked At" fields. A Raw Lead that is
 * ready for a booking link moves to Pre-Qualified; a roof replacement moves
 * into the Roof Replacement pipeline at "Waiting for Roof Report" (staff move
 * it to "Roof Report Received" once the report is in); one that can't go on
 * gets the "Needs review" tag with
 * the reason in Next Action. Leads already contacted or further along are
 * checked but not moved or tagged.
 */
final class LeadTriageService
{
    /** Stages where the check decides what happens next. */
    private const OPEN_STAGES = [
        'new',
        LeadStageService::PRE_QUALIFIED,
        LeadStageService::WAITING_ROOF_REPORT,
        LeadStageService::ROOF_REPORT_RECEIVED,
    ];

    public function __construct(
        private SlotSuggestionService $locator,
        private LeadStageService $stages,
        private AttributeValueRepository $attributeValues
    ) {}

    /**
     * @return array{lead_id: int, title: string, stage: ?string, service: ?string, category: string,
     *               flow: string, location: string, missing: array, next_action: string, review: bool,
     *               changes: array, moved: bool}
     */
    public function check(Lead $lead): array
    {
        return DB::transaction(fn () => $this->run($lead));
    }

    /** True while a check started from an edit is running (see recheckAfterEdit). */
    private static bool $rechecking = false;

    /**
     * Re-runs the check after a contact or lead is edited, so "Missing Info",
     * "Location Check" and "Next Action" follow the new phone, address, etc.
     * The check itself can fire lead.update.after (moving to Pre-Qualified),
     * so nested calls are ignored. A failure is logged, never shown: the
     * user's edit is already saved and must not turn into an error page.
     */
    public function recheckAfterEdit(Person|Lead $entity): void
    {
        if (self::$rechecking) {
            return;
        }

        self::$rechecking = true;

        try {
            $leads = $entity instanceof Lead
                ? collect([$entity->fresh()])->filter()
                : Lead::query()->where('person_id', $entity->id)->get();

            foreach ($leads as $lead) {
                $this->check($lead);
            }
        } catch (\Throwable $exception) {
            \Illuminate\Support\Facades\Log::warning('Lead re-check after edit failed.', [
                'entity' => $entity::class,
                'id' => $entity->id,
                'error' => $exception->getMessage(),
            ]);
        } finally {
            self::$rechecking = false;
        }
    }

    private function run(Lead $lead): array
    {
        $lead->loadMissing('stage', 'person');
        $person = $lead->person;
        $changes = $person ? $this->normalizePerson($person) : [];

        // 1. Service → category and flow.
        $service = $this->serviceName($lead, $changes);
        [$category, $flow] = $this->classify($service);

        // 2. Location.
        $location = $person ? $this->checkLocation($person) : ['status' => 'unknown', 'text' => 'No contact on this lead', 'error' => null, 'precision' => null];

        // 3. What's missing out of Moe's four: name, address, phone, job type.
        // A street counts only if the map finds it (same rule as the booking link).
        $stored = $person ? $this->locator->storedAddress($person) : ['has_street' => false];
        $hasStreet = $stored['has_street'] && in_array($location['precision'], ['address', null], true) && $location['status'] !== 'unknown';
        $missing = array_keys(array_filter([
            'Name' => ! $person || ContactDataNormalizer::text($person->name) === null,
            'Street address' => ! $hasStreet,
            'Phone' => ! $person || ! self::hasPhone($person),
            'Service' => $flow === 'review',
        ]));
        $hasEmail = $person && collect($person->emails ?? [])->contains(fn ($row) => ContactDataNormalizer::email($row['value'] ?? null));

        // 4. Next action.
        $reasons = array_values(array_filter([
            ! $person ? 'No contact on this lead' : null,
            $flow === 'review' ? ($service ? "Unrecognised service \"{$service}\": set the service, then re-check" : 'No service given: set the service, then re-check') : null,
            $location['status'] === 'out' ? "{$location['text']}: send the out-of-area message" : null,
            $location['status'] === 'unknown' && $person ? "{$location['text']}: no times can be picked; ask the customer for the address" : null,
            $location['status'] === 'error' ? "Location not checked ({$location['error']}); re-check later" : null,
            $person && ! $hasEmail ? 'No email address to send the booking link to' : null,
        ]));

        $stageCode = $lead->stage?->code;
        $open = in_array($stageCode, self::OPEN_STAGES, true);
        $asks = array_values(array_intersect($missing, ['Street address', 'Phone']));
        // Roof replacement: no booking link until the roof report is in
        // (staff set "Roof Report Received").
        $needsRoofReport = $flow === 'roof_report' && $stageCode !== LeadStageService::ROOF_REPORT_RECEIVED;

        if (! $open) {
            $review = false;
            $next = 'None: the lead is '.($lead->stage?->name ?? 'past this step');
        } elseif ($reasons) {
            $review = true;
            $next = 'Review: '.implode('; ', $reasons);
        } elseif ($needsRoofReport) {
            $review = false;
            $next = 'Get the roof report'.(in_array('Street address', $missing, true) ? ' (ask the customer for the property address first)' : '')
                .', attach it to the lead, then move the lead to Roof Report Received';
        } else {
            $review = false;
            $next = 'Send booking link'.($asks ? ' (it asks for: '.strtolower(implode(', ', $asks)).')' : '');
        }

        $this->saveResults($lead, [
            'txn_service_category' => $category,
            'txn_flow' => config("lead_triage.flow_labels.{$flow}", $flow),
            'txn_location' => $location['text'],
            'txn_missing' => $missing ? implode(', ', $missing) : 'None',
            'txn_next_action' => $next,
            // Central wall time, like the visit times staff see.
            'txn_checked_at' => now(config('slot_finder.timezone'))->format('Y-m-d H:i:s'),
        ]);

        if ($open) {
            $this->setReviewTag($lead, $review);
        }

        $inRoofStage = in_array($stageCode, [LeadStageService::WAITING_ROOF_REPORT, LeadStageService::ROOF_REPORT_RECEIVED], true);
        $moved = $open && ! $review && match (true) {
            // Into the Roof Replacement pipeline (only roof leads have those stages).
            $needsRoofReport => $this->stages->waitingForRoofReport($lead),
            // Service corrected away from roof replacement: back to the main pipeline.
            $inRoofStage && $flow !== 'roof_report' => $this->stages->backToStandard($lead),
            default => $this->stages->preQualified($lead),
        };

        return [
            'lead_id' => $lead->id,
            'title' => $lead->title,
            'stage' => $lead->stage?->name,
            'service' => $service,
            'category' => $category,
            'flow' => $flow,
            'location' => $location['text'],
            'missing' => $missing,
            'next_action' => $next,
            'review' => $review,
            'changes' => $changes,
            'moved' => $moved,
        ];
    }

    /** A usable phone number (US or not) among the person's contact numbers. */
    public static function hasPhone(Person $person): bool
    {
        return collect($person->contact_numbers ?? [])->contains(fn ($row) => ContactDataNormalizer::phone($row['value'] ?? null));
    }

    // ---------------------------------------------------------------------
    // Normalize
    // ---------------------------------------------------------------------

    /** Cleans the person's name, emails, phones and address fields in place; returns what changed. */
    private function normalizePerson(Person $person): array
    {
        $changes = [];
        $updates = [];

        $name = ContactDataNormalizer::text($person->name);

        if ($name !== null && $name !== $person->name) {
            $updates['name'] = $name;
            $changes[] = 'name';
        }

        $emails = ContactDataNormalizer::valueList($person->emails, [ContactDataNormalizer::class, 'email']);
        $phones = ContactDataNormalizer::valueList($person->contact_numbers, [ContactDataNormalizer::class, 'phone']);

        if ($emails !== array_values($person->emails ?? [])) {
            $updates['emails'] = $emails;
            $changes[] = 'emails';
        }

        if ($phones !== array_values($person->contact_numbers ?? [])) {
            $updates['contact_numbers'] = $phones;
            $changes[] = 'phone';
        }

        if ($updates) {
            $person->fill($updates)->save();
        }

        // Address fields (custom attributes).
        $attributes = collect(PersonGridColumnPreferences::customAttributes())->keyBy('code');
        $current = $attributes->map(fn ($attribute) => $person->getCustomAttributeValue($attribute));

        $address = ContactDataNormalizer::text($current['csv_address'] ?? null);
        $zip = ContactDataNormalizer::postalCode($current['csv_postal_code'] ?? null);

        if ($zip === null) {
            [$address, $zip] = ContactDataNormalizer::splitTrailingZip($address);
        }

        $clean = [
            'csv_address' => $address,
            'csv_city' => ContactDataNormalizer::city($current['csv_city'] ?? null),
            'csv_state_province' => ContactDataNormalizer::state($current['csv_state_province'] ?? null),
            'csv_postal_code' => $zip,
        ];

        foreach ($clean as $code => $value) {
            $attribute = $attributes[$code] ?? null;
            $old = $current[$code] ?? null;

            if (! $attribute || $value === $old || ($value === null && ($old === null || $old === ''))) {
                continue;
            }

            $this->attributeValues->save(['entity_type' => 'persons', 'entity_id' => $person->id, $code => $value], [$attribute]);
            $changes[] = strtolower($attribute->name);
        }

        if ($changes) {
            $person->unsetRelation('attribute_values');
        }

        return $changes;
    }

    // ---------------------------------------------------------------------
    // Classify
    // ---------------------------------------------------------------------

    /** The lead's service name (cleaned and saved back), else the title before " - ". */
    private function serviceName(Lead $lead, array &$changes): ?string
    {
        $attribute = $this->leadAttribute('csv_service_name');
        $raw = $attribute ? $lead->getCustomAttributeValue($attribute) : null;
        $service = ContactDataNormalizer::text($raw);

        if ($attribute && $service !== null && $service !== $raw) {
            $this->attributeValues->save(['entity_type' => 'leads', 'entity_id' => $lead->id, 'csv_service_name' => $service], [$attribute]);
            $changes[] = 'service name';
        }

        return $service ?? ContactDataNormalizer::text(explode(' - ', (string) $lead->title)[0] ?: null);
    }

    /** @return array{0: string, 1: string} [category, flow] */
    private function classify(?string $service): array
    {
        $text = mb_strtolower((string) $service);

        if ($text !== '') {
            foreach (config('lead_triage.services', []) as $rule) {
                foreach ((array) $rule['match'] as $phrase) {
                    if (str_contains($text, mb_strtolower($phrase))) {
                        return [$rule['category'], $rule['flow']];
                    }
                }
            }
        }

        return ['Unknown', 'review'];
    }

    // ---------------------------------------------------------------------
    // Location
    // ---------------------------------------------------------------------

    /** @return array{status: string, text: string, error: ?string, precision: ?string} status: in, out, unknown, error */
    private function checkLocation(Person $person): array
    {
        try {
            $place = $this->locator->locatePerson($person);
        } catch (SlotSuggestionException $exception) {
            return ['status' => 'error', 'text' => 'Not checked', 'error' => $exception->getMessage(), 'precision' => null];
        }

        if (! $place['precision']) {
            return ['status' => 'unknown', 'text' => 'Unknown: no usable address or ZIP', 'error' => null, 'precision' => null];
        }

        $miles = (int) round($this->locator->milesFromServiceCentre($place['lat'], $place['lon']));
        $radius = (int) config('slot_finder.service_area.miles', 120);
        $basis = match (true) {
            $place['precision'] === 'address' => 'street address',
            $this->locator->storedAddress($person)['has_street'] => 'street not found on the map, ZIP centre',
            default => 'ZIP centre',
        };
        $status = $miles <= $radius ? 'in' : 'out';

        return [
            'status' => $status,
            'text' => ($status === 'in' ? 'In area' : 'Out of area').": {$miles} mi from Houston ({$basis})",
            'error' => null,
            'precision' => $place['precision'],
        ];
    }

    // ---------------------------------------------------------------------
    // Saving
    // ---------------------------------------------------------------------

    /**
     * Writes the computed fields straight to attribute_values: they are
     * recalculated on every check, so they don't go into the lead's history.
     */
    private function saveResults(Lead $lead, array $values): void
    {
        foreach ($values as $code => $value) {
            $attribute = $this->leadAttribute($code);

            if (! $attribute) {
                continue;
            }

            $column = $attribute->type === 'datetime' ? 'datetime_value' : 'text_value';

            DB::table('attribute_values')->updateOrInsert(
                ['entity_type' => 'leads', 'entity_id' => $lead->id, 'attribute_id' => $attribute->id],
                [$column => $value]
            );
        }

        $lead->unsetRelation('attribute_values');
    }

    private function setReviewTag(Lead $lead, bool $review): void
    {
        $settings = config('lead_triage.review_tag');
        $tagClass = TagProxy::modelClass();
        $tag = $tagClass::query()->where('name', $settings['name'])->first();

        if ($review) {
            $tag ??= $tagClass::create([
                'name' => $settings['name'],
                'color' => $settings['color'],
                'user_id' => auth()->id() ?: config('calcom.activity_user_id', 1),
            ]);
            $lead->tags()->syncWithoutDetaching([$tag->id]);
        } elseif ($tag) {
            $lead->tags()->detach($tag->id);
        }
    }

    private function leadAttribute(string $code)
    {
        static $cache = [];

        return $cache[$code] ??= DB::table('attributes')->where('entity_type', 'leads')->where('code', $code)->first();
    }
}
