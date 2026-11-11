<?php

namespace Webkul\Admin\Support\Contact;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Webkul\Contact\Models\Person;

final class CalcomBookingLinkService
{
    private const API_URL = 'https://api.cal.com/v2';

    private const SCHEDULE_API_VERSION = '2024-06-11';

    private const EVENT_TYPE_API_VERSION = '2026-06-12';

    private const PRIVATE_LINK_API_VERSION = '2024-09-04';

    private const SUPPORTED_FIELD_TYPES = ['text', 'textarea', 'email', 'phone'];

    private const ADDRESS_FIELDS = ['csv_address', 'csv_city', 'csv_state_province', 'csv_postal_code'];

    public function __construct(private SlotSuggestionService $locator) {}

    /**
     * Where the booking page says the visit is, and which address fields it
     * asks for whatever staff tick. With a usable stored street address:
     * that address and no questions. Without one (e.g. only a ZIP, or "319"):
     * all the address fields are asked, even the ones already stored.
     *
     * @return array{location: string, has_street: bool, auto_fields: array}
     */
    public function addressPlan(Person $person): array
    {
        $stored = $this->locator->storedAddress($person);

        // A street the map can't find (e.g. "24320 WhiteOakDr") is asked
        // again too. Lookups are cached; if the lookup itself fails, the
        // stored street is trusted.
        if ($stored['has_street']) {
            try {
                $stored['has_street'] = $this->locator->locatePerson($person)['precision'] === 'address';
            } catch (SlotSuggestionException) {
            }
        }

        $autoFields = $stored['has_street'] ? [] : collect(PersonGridColumnPreferences::customAttributes())
            ->filter(fn ($attribute) => in_array($attribute->code, self::ADDRESS_FIELDS, true))
            ->map(fn ($attribute) => [
                'id'   => (int) $attribute->id,
                'code' => $attribute->code,
                'name' => $attribute->name,
            ])
            ->values()
            ->all();

        return [
            'location' => $stored['address'] ?: "Customer's home",
            'has_street' => $stored['has_street'],
            'auto_fields' => $autoFields,
            // Phone is one of the four details TXN needs; asked whenever no
            // valid number is stored. The answer is saved on the contact
            // when the booking syncs.
            'ask_phone' => ! LeadTriageService::hasPhone($person),
        ];
    }

    /** The "Phone number" choice on the booking-link form (not an attribute code). */
    public const PHONE_CHOICE = '__phone';

    /**
     * Every choice the booking-link form offers for this contact: its empty
     * fields, the address fields when no usable street is stored, and the
     * phone when no number is stored. All start ticked; the admin may untick.
     */
    public function askableCodes(Person $person): array
    {
        $plan = $this->addressPlan($person);

        return array_values(array_unique([
            ...array_column($this->missingFields($person), 'code'),
            ...array_column($plan['auto_fields'], 'code'),
            ...($plan['ask_phone'] ? [self::PHONE_CHOICE] : []),
        ]));
    }

    /**
     * Every contact detail the booking page can ask for, missing or not, so
     * staff can also re-ask a stored value that is wrong. Each row:
     * code, name, current (stored value or null), ticked (missing or
     * unusable, so ticked by default) and why (short reason when ticked).
     *
     * @return array<int, array{code: string, name: string, current: ?string, ticked: bool, why: ?string}>
     */
    public function askChoices(Person $person): array
    {
        $plan = $this->addressPlan($person);
        $ticked = $this->askableCodes($person);
        $phones = collect($person->contact_numbers ?? [])->pluck('value')->filter()->implode(', ');

        $rows = [[
            'code' => self::PHONE_CHOICE,
            'name' => 'Phone number',
            'current' => $phones ?: null,
            'ticked' => in_array(self::PHONE_CHOICE, $ticked, true),
            'why' => $phones === '' ? 'missing' : ($plan['ask_phone'] ? 'not a usable number' : null),
        ]];

        $attributes = collect(PersonGridColumnPreferences::customAttributes())
            ->filter(fn ($attribute) => in_array($attribute->type, self::SUPPORTED_FIELD_TYPES, true))
            // Address fields first, in their usual order.
            ->sortBy(fn ($attribute) => [in_array($attribute->code, self::ADDRESS_FIELDS, true) ? 0 : 1, (int) $attribute->id]);

        foreach ($attributes as $attribute) {
            $current = trim((string) $person->getCustomAttributeValue($attribute));
            $isTicked = in_array($attribute->code, $ticked, true);

            $rows[] = [
                'code' => $attribute->code,
                'name' => $attribute->name,
                'current' => $current === '' ? null : $current,
                'ticked' => $isTicked,
                'why' => ! $isTicked ? null : ($current === '' ? 'missing' : 'the street address can\'t be found on the map'),
            ];
        }

        return $rows;
    }

    /** Every code the booking-link form accepts (ticked by default or not). */
    public function choiceCodes(Person $person): array
    {
        return array_column($this->askChoices($person), 'code');
    }

    public function missingFields(Person $person): array
    {
        return collect(PersonGridColumnPreferences::customAttributes())
            ->filter(fn ($attribute) => in_array($attribute->type, self::SUPPORTED_FIELD_TYPES, true))
            ->filter(fn ($attribute) => blank($person->getCustomAttributeValue($attribute)))
            ->map(fn ($attribute) => [
                'id'   => (int) $attribute->id,
                'code' => $attribute->code,
                'name' => $attribute->name,
            ])
            ->values()
            ->all();
    }

    /**
     * $slotWorkers: local start time ("Y-m-d\TH:i") => worker id. The worker
     * of the time the customer books is assigned when the booking syncs.
     * $leadId: the lead the link is for (sent from the lead page); its
     * booking then goes to that lead only.
     */
    public function createBookingLink(
        Person $person,
        array $slotWorkers,
        string $timezone,
        array $fieldCodes,
        ?int $leadId = null
    ): string {
        $overrides = $this->overrides($slotWorkers, $timezone);

        $scheduleResponse = $this->post(
            '/schedules',
            self::SCHEDULE_API_VERSION,
            [
                'name'         => "CRM home visit for person {$person->id}",
                'timeZone'     => $timezone,
                'isDefault'    => false,
                'availability' => [],
                'overrides'    => $overrides,
            ],
            'schedule'
        );

        $scheduleId = data_get($scheduleResponse->json(), 'data.id');

        if (! is_numeric($scheduleId)) {
            throw new CalcomApiException('Cal.com created a schedule but did not return its ID.');
        }

        $addressPlan = $this->addressPlan($person);

        // Exactly what the admin ticked on the form ($fieldCodes), stored
        // value or not (a wrong value can be asked again).
        $customFields = collect(PersonGridColumnPreferences::customAttributes())
            ->filter(fn ($attribute) => in_array($attribute->type, self::SUPPORTED_FIELD_TYPES, true))
            ->filter(fn ($attribute) => in_array($attribute->code, $fieldCodes, true))
            ->sortBy(fn ($attribute) => [in_array($attribute->code, self::ADDRESS_FIELDS, true) ? 0 : 1, (int) $attribute->id])
            ->map(fn ($attribute) => ['id' => (int) $attribute->id, 'code' => $attribute->code, 'name' => $attribute->name])
            ->map(fn ($field) => [
                'field'       => 'custom',
                'slug'        => "txn_person_{$person->id}_field_{$field['id']}",
                'label'       => $field['name'],
                'type'        => 'shortText',
                'required'    => true,
                'placeholder' => "Enter {$field['name']}",
            ])
            ->values()
            ->all();

        if (in_array(self::PHONE_CHOICE, $fieldCodes, true)) {
            $customFields[] = [
                'field'       => 'custom',
                'slug'        => self::phoneFieldSlug($person),
                'label'       => 'Phone number',
                'type'        => 'phone',
                'required'    => true,
                'placeholder' => 'Mobile number for appointment updates',
            ];
        }

        $slug = 'txn-home-visit-'.$person->id.'-'.Str::lower(Str::random(10));

        // TXN's confirmation (requirements A9) by service type: Cal.com shows
        // the title and description on the booking page and in its own
        // confirmation email, which goes out the moment the customer books.
        $confirmation = app(BookingEmail::class)->confirmation(
            $leadId ? \Webkul\Lead\Models\Lead::find($leadId) : null,
            // When any address field is asked again, the stored address may be
            // wrong, so the confirmation doesn't print it.
            $addressPlan['has_street'] && ! array_intersect(self::ADDRESS_FIELDS, $fieldCodes) ? $addressPlan['location'] : null
        );

        $eventResponse = $this->post(
            '/event-types',
            self::EVENT_TYPE_API_VERSION,
            [
                'title'             => $confirmation['title'],
                'slug'              => $slug,
                'description'       => $confirmation['description'],
                'lengthInMinutes'   => 60,
                'slotInterval'      => 60,
                'minimumBookingNotice' => 0,
                'scheduleId'        => (int) $scheduleId,
                'hidden'            => true,
                // Cal.com needs one location (left out it adds Cal Video; an
                // empty list is refused). It is the contact's stored address,
                // not shown on the booking page; nothing for the customer to
                // type unless the address fields are asked.
                'locations'         => [
                    ['type' => 'address', 'address' => $addressPlan['location'], 'public' => false],
                ],
                'bookingFields'     => array_merge([
                    [
                        'field'   => 'name',
                        'slug'    => 'name',
                        'variant' => 'fullName',
                        'label'   => 'Name',
                    ],
                    [
                        'field'   => 'email',
                        'slug'    => 'email',
                        'label'   => 'Email',
                        'required' => true,
                    ],
                ], $customFields),
            ],
            'event type'
        );

        $eventTypeId = data_get($eventResponse->json(), 'data.id');

        if (! is_numeric($eventTypeId)) {
            throw new CalcomApiException('Cal.com created an event type but did not return its ID.');
        }

        // Never hand out a link whose page has a location Cal.com added on its
        // own (a video link or an address question for the customer).
        $locations = (array) data_get($eventResponse->json(), 'data.locations', []);

        if (array_diff(array_column($locations, 'type'), ['address'])) {
            Log::warning('Cal.com added a location to a booking-link event type; no link was created.', [
                'event_type_id' => $eventTypeId,
                'location_types' => array_column((array) $locations, 'type'),
            ]);

            throw new CalcomApiException('Cal.com added a location to the booking page on its own, so no link was created. Tell the developer.');
        }

        $this->lastLinkId = (int) DB::table('calcom_booking_links')->insertGetId([
            'person_id' => $person->id,
            'lead_id' => $leadId,
            'event_type_slug' => $slug,
            'event_type_id' => (int) $eventTypeId,
            'schedule_id' => (int) $scheduleId,
            'timezone' => $timezone,
            'slot_workers' => json_encode(array_map('intval', $slotWorkers)),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // The customer gets a single-use private link: once one booking is
        // made, the link expires, whatever email is entered. Rescheduling and
        // cancelling stay available from the booking confirmation email.
        $linkResponse = $this->post(
            "/event-types/{$eventTypeId}/private-links",
            self::PRIVATE_LINK_API_VERSION,
            ['maxUsageCount' => 1],
            'private link'
        );

        $bookingUrl = data_get($linkResponse->json(), 'data.bookingUrl');
        $parsedUrl = is_string($bookingUrl) ? parse_url($bookingUrl) : false;

        if (
            ! is_string($bookingUrl)
            || ! filter_var($bookingUrl, FILTER_VALIDATE_URL)
            || ! is_array($parsedUrl)
            || ($parsedUrl['scheme'] ?? null) !== 'https'
        ) {
            throw new CalcomApiException('Cal.com created a private link but did not return a valid HTTPS booking link.');
        }

        return $bookingUrl;
    }

    /** calcom_booking_links id of the link the last createBookingLink() call made. */
    public ?int $lastLinkId = null;

    /**
     * Changes the times of a created link (e.g. a time added on the form
     * after the link was made): the Cal.com schedule gets exactly these
     * times, and each time's worker is stored for the booking sync.
     */
    public function updateSlots(int $linkId, array $slotWorkers, string $timezone): void
    {
        $link = DB::table('calcom_booking_links')->where('id', $linkId)->first();

        if (! $link || ! $link->schedule_id) {
            throw new CalcomApiException('This link can\'t change its times. Create the link again with the new times.');
        }

        $this->send('patch', "/schedules/{$link->schedule_id}", self::SCHEDULE_API_VERSION, [
            'timeZone'  => $timezone,
            'overrides' => $this->overrides($slotWorkers, $timezone),
        ], 'schedule update');

        DB::table('calcom_booking_links')->where('id', $linkId)->update([
            'timezone' => $timezone,
            'slot_workers' => json_encode(array_map('intval', $slotWorkers)),
            'updated_at' => now(),
        ]);
    }

    /** One-hour Cal.com date overrides for the slot start times ("Y-m-d\TH:i" => worker). */
    private function overrides(array $slotWorkers, string $timezone): array
    {
        return collect(array_keys($slotWorkers))
            ->map(function (string $slot) use ($timezone) {
                $start = CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $slot, $timezone);

                return [
                    'date'      => $start->toDateString(),
                    'startTime' => $start->format('H:i'),
                    'endTime'   => $start->addHour()->format('H:i'),
                ];
            })
            ->values()
            ->all();
    }

    /** Booking-page question for the phone number; CalcomBookingSyncService saves the answer. */
    public static function phoneFieldSlug(Person $person): string
    {
        return "txn_person_{$person->id}_phone";
    }

    private function post(string $path, string $apiVersion, array $payload, string $operation): Response
    {
        return $this->send('post', $path, $apiVersion, $payload, $operation);
    }

    private function send(string $method, string $path, string $apiVersion, array $payload, string $operation): Response
    {
        $apiKey = config('calcom.api_key');

        if (! is_string($apiKey) || $apiKey === '') {
            throw new CalcomApiException('Cal.com is not configured. Set CALCOM_API_KEY in the Krayin environment.');
        }

        try {
            $response = Http::withToken($apiKey)
                ->acceptJson()
                ->withHeaders(['cal-api-version' => $apiVersion])
                ->timeout(30)
                ->{$method}(self::API_URL.$path, $payload);
        } catch (ConnectionException $exception) {
            Log::error('Krayin could not connect to Cal.com.', [
                'operation' => $operation,
                'exception_class' => get_class($exception),
            ]);

            throw new CalcomApiException('Krayin could not connect to Cal.com. Check the server connection and try again.', 0, $exception);
        }

        if (! $response->successful()) {
            // Cal.com's own reason, e.g. "'locations' must contain at least 1 location."
            $reason = $response->json('error.message') ?? $response->json('message');
            $reason = is_string($reason) ? mb_substr($reason, 0, 300) : null;

            Log::warning('Cal.com rejected a booking-link API request.', [
                'operation' => $operation,
                'status' => $response->status(),
                'reason' => $reason,
            ]);

            throw new CalcomApiException(
                "Cal.com rejected the {$operation} request (HTTP {$response->status()})"
                .($reason ? ": {$reason}" : '. Check the API key and Cal.com account settings.')
            );
        }

        return $response;
    }
}
