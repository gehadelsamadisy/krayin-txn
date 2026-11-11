<?php

namespace Webkul\Admin\Support\Contact;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Webkul\Admin\Models\Worker;
use Throwable;
use Webkul\Activity\Models\ActivityProxy;
use Webkul\Attribute\Repositories\AttributeValueRepository;
use Webkul\Contact\Models\Person;

/**
 * Copies Cal.com bookings into Krayin as "meeting" activities, so each
 * appointment shows on the person, on the person's leads and on the
 * Activities calendar.
 *
 * Only bookings of the private event types made by "Send booking link" are
 * copied; their slug "txn-home-visit-{person id}-..." names the person.
 * One activity per appointment: a reschedule moves it, a cancellation keeps
 * it, marked "Cancelled" and done. Times are stored as America/Chicago wall
 * time, the way Krayin's activity form stores what staff type.
 */
final class CalcomBookingSyncService
{
    private const API_URL = 'https://api.cal.com/v2';

    private const BOOKINGS_API_VERSION = '2024-08-13';

    private const SLUG_PATTERN = '/^txn-home-visit-(\d+)(?:-worker-(\d+))?-/';

    private const FIELD_PATTERN = '/^txn_person_\d+_field_(\d+)$/';

    /** Answer types copied into the person's fields (the link asks text questions). */
    private const ANSWER_TYPES = ['text', 'textarea'];

    public function __construct(
        private SlotSuggestionService $locator,
        private LeadStageService $stages,
        private WorkerVisitNotifier $notifier,
        private AttributeValueRepository $attributeValues,
        private LeadTriageService $triage
    ) {}

    private const STATUS_TITLES = [
        'cancelled' => 'Cancelled: ',
        'rejected'  => 'Rejected: ',
        'pending'   => 'Unconfirmed: ',
    ];

    /** @return array{created: int, updated: int, unchanged: int, skipped: int} */
    public function sync(): array
    {
        $since = CarbonImmutable::now()->subDays(config('calcom.sync_lookback_days'));
        $counts = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0];

        // Oldest first, so a rescheduled booking is seen before its new one.
        $bookings = collect($this->fetchBookings($since))->sortBy('createdAt')->values();

        foreach ($bookings as $booking) {
            $counts[$this->upsert($booking)]++;
        }

        return $counts;
    }

    /** Creates or updates the booking's activity; returns the $counts key. */
    public function upsert(array $booking): string
    {
        $uid = $booking['uid'] ?? null;
        $status = strtolower((string) ($booking['status'] ?? ''));

        if (! $uid || ! preg_match(self::SLUG_PATTERN, (string) data_get($booking, 'eventType.slug'), $match)) {
            return 'skipped';
        }

        // Moved to another time: the new booking carries the appointment.
        if ($status === 'cancelled' && filled($booking['rescheduledToUid'] ?? null)) {
            return 'skipped';
        }

        $person = Person::find((int) $match[1]);

        if (! $person) {
            Log::warning('Cal.com booking names a Krayin person that does not exist.', [
                'booking_uid' => $uid,
                'person_id'   => (int) $match[1],
            ]);

            return 'skipped';
        }

        $activity = $this->findActivity($uid)
            ?? (filled($booking['rescheduledFromUid'] ?? null) ? $this->findActivity($booking['rescheduledFromUid']) : null);
        $existingCalcomData = $activity ? $this->calcomData($activity) : [];
        $hasWorkerOverride = is_array($existingCalcomData['worker_override_ids'] ?? null);
        $workerOverrideIds = $hasWorkerOverride
            ? array_values(array_filter(array_map('intval', $existingCalcomData['worker_override_ids']), fn ($id) => $id > 0))
            : [];

        if ($hasWorkerOverride) {
            $existingWorkerIds = Worker::query()->whereIn('id', $workerOverrideIds)->pluck('id')->map(fn ($id) => (int) $id)->all();
            $workerOverrideIds = array_values(array_filter(
                $workerOverrideIds,
                fn ($workerId) => in_array($workerId, $existingWorkerIds, true)
            ));
        }

        if ($activity && data_get($this->calcomData($activity), 'updated_at') === ($booking['updatedAt'] ?? null)
            && data_get($this->calcomData($activity), 'uid') === $uid) {
            return 'unchanged';
        }

        $timezone = config('slot_finder.timezone');
        $closed = in_array($status, ['cancelled', 'rejected'], true);

        // The state before this sync, to tell what happened: booked,
        // rescheduled or cancelled.
        $isNew = ! $activity;
        $wasClosed = in_array($existingCalcomData['status'] ?? null, ['cancelled', 'rejected'], true);
        $oldFrom = $activity?->schedule_from ? CarbonImmutable::parse($activity->schedule_from) : null;
        $oldWorkerIds = $activity ? $activity->workers()->pluck('workers.id')->map(fn ($id) => (int) $id)->all() : [];

        // The answers to the requested fields go into the person's fields
        // once, when the booking first arrives, before the visit is located,
        // so a newly given address is the one used.
        $answersSaved = $isNew && $this->saveAnswers($person, $booking);

        $calcomData = [
            'uid'           => $uid,
            'booking_id'    => $booking['id'] ?? null,
            'status'        => $status,
            'event_type_id' => $booking['eventTypeId'] ?? null,
            // The name only: customer email addresses are never stored here.
            'attendee'      => data_get($booking, 'attendees.0.name'),
            'updated_at'    => $booking['updatedAt'] ?? null,
        ];

        if ($hasWorkerOverride) {
            $calcomData['worker_override_ids'] = $workerOverrideIds;
        }

        // The visit is at the address stored on the contact in Krayin, never
        // one typed on Cal.com (the booking page doesn't ask for it).
        try {
            $place = $this->locator->locatePerson($person);
        } catch (SlotSuggestionException) {
            $place = ['address' => null, 'lat' => null, 'lon' => null];
        }

        $attributes = [
            'title'         => (self::STATUS_TITLES[$status] ?? '')."Home visit - {$person->name}",
            'type'          => 'meeting',
            'location'      => $place['address'],
            'latitude'      => $place['lat'],
            'longitude'     => $place['lon'],
            'comment'       => $this->comment($booking),
            'schedule_from' => CarbonImmutable::parse($booking['start'])->setTimezone($timezone)->format('Y-m-d H:i:s'),
            'schedule_to'   => CarbonImmutable::parse($booking['end'])->setTimezone($timezone)->format('Y-m-d H:i:s'),
            'additional'    => json_encode(['calcom' => $calcomData]),
        ];

        // Staff may have ticked an appointment done; only a cancellation
        // changes that.
        if ($closed || ! $activity) {
            $attributes['is_done'] = $closed;
        }

        $workerId = $this->bookedWorkerId((string) data_get($booking, 'eventType.slug'), (string) $booking['start'])
            ?? (isset($match[2]) ? (int) $match[2] : null);

        if ($hasWorkerOverride) {
            $attributes['worker_id'] = $workerOverrideIds[0] ?? null;
        } elseif ($workerId !== null) {
            $attributes['worker_id'] = $workerId;
        }

        // A link sent from a lead page names its lead: only that lead gets the
        // visit and changes stage. Otherwise all the person's leads, as before.
        $linkLeadId = DB::table('calcom_booking_links')->where('event_type_slug', (string) data_get($booking, 'eventType.slug'))->value('lead_id');
        $linkLead = $linkLeadId ? $person->leads()->whereKey($linkLeadId)->first() : null;

        $newFrom = CarbonImmutable::parse($attributes['schedule_from']);
        $event = match (true) {
            $closed && ! $wasClosed => 'cancelled',
            $closed => null,
            $isNew || $wasClosed => 'booked',
            $oldFrom && ! $oldFrom->equalTo($newFrom) => 'rescheduled',
            default => null,
        };

        $activity = DB::transaction(function () use ($activity, $attributes, $person, $workerId, $workerOverrideIds, $hasWorkerOverride, $uid, $event, $linkLead) {
            if (! $activity) {
                $activity = ActivityProxy::modelClass()::create($attributes + [
                    'user_id' => $person->user_id ?: config('calcom.activity_user_id'),
                ]);
            } else {
                $activity->update($attributes);
            }

            $activity->persons()->syncWithoutDetaching([$person->id]);
            $activity->leads()->syncWithoutDetaching($linkLead ? [$linkLead->id] : $person->leads()->pluck('leads.id')->all());

            if ($hasWorkerOverride) {
                $activity->workers()->sync($workerOverrideIds);
            } elseif ($workerId !== null) {
                if (Worker::query()->whereKey($workerId)->exists()) {
                    // A reschedule to another worker's time moves the visit.
                    $activity->workers()->sync([$workerId]);
                } else {
                    Log::warning('Cal.com booking names a worker that does not exist.', [
                        'booking_uid' => $uid,
                        'worker_id' => $workerId,
                    ]);
                }
            }

            if (! $activity->participants()->where('person_id', $person->id)->exists()) {
                $activity->participants()->create(['person_id' => $person->id]);
            }

            // In the same transaction: a stage is never left behind while
            // the next sync sees the booking as unchanged.
            match ($event) {
                'booked' => $this->stages->booked($person, $linkLead),
                'rescheduled' => $this->stages->rescheduled($person, $linkLead),
                'cancelled' => $this->stages->canceled($person, $linkLead),
                default => null,
            };

            return $activity;
        });

        // Emails only after the change is saved. A booking already cancelled
        // when first seen was never on anyone's schedule: no email.
        $newWorkerIds = $activity->workers()->pluck('workers.id')->map(fn ($id) => (int) $id)->all();

        match (true) {
            $event === 'booked' => $this->notifier->booked($activity, $person, $newWorkerIds),
            $event === 'rescheduled' => $this->notifier->rescheduled($activity, $person, $oldWorkerIds, $newWorkerIds, $oldFrom),
            $event === 'cancelled' && ! $isNew => $this->notifier->cancelled($activity, $person, $newWorkerIds),
            default => null,
        };

        // A phone or address from the booking form changes the lead's
        // "Missing Info" and location; re-check now, after the stage moved,
        // so the check sees the lead as booked.
        if ($answersSaved) {
            $this->triage->recheckAfterEdit($person->fresh());
        }

        return $isNew ? 'created' : 'updated';
    }

    /**
     * Copies the customer's answers ("txn_person_{id}_field_{attribute id}")
     * into the person's custom text fields. The link only asks for fields
     * that are blank or, for the address, not usable, so an answer replaces
     * what is stored; Krayin keeps the old value in the person's history.
     * Returns whether anything was saved (phone or a field).
     */
    private function saveAnswers(Person $person, array $booking): bool
    {
        $phoneSaved = false;
        $attributes = collect(PersonGridColumnPreferences::customAttributes())
            ->filter(fn ($attribute) => in_array($attribute->type, self::ANSWER_TYPES, true))
            ->keyBy(fn ($attribute) => (int) $attribute->id);
        $saved = [];

        // The phone question (asked when missing, or when staff re-ask a
        // wrong one): the answer becomes the contact's first number. Stored
        // entries that aren't usable numbers are dropped; other numbers stay
        // below it.
        $phone = ContactDataNormalizer::phone($booking['bookingFieldsResponses'][CalcomBookingLinkService::phoneFieldSlug($person)] ?? null);
        $firstPhone = ContactDataNormalizer::phone(data_get($person->contact_numbers, '0.value'));

        if ($phone !== null && $phone !== $firstPhone) {
            $others = collect(ContactDataNormalizer::valueList($person->contact_numbers, [ContactDataNormalizer::class, 'phone']))
                ->filter(fn ($row) => ContactDataNormalizer::phone($row['value']) !== null && $row['value'] !== $phone)
                ->values()
                ->all();
            $person->contact_numbers = [['label' => 'Mobile', 'value' => $phone], ...$others];
            $person->save();
            $phoneSaved = true;
        }

        foreach ($booking['bookingFieldsResponses'] ?? [] as $slug => $answer) {
            if (! preg_match('/^txn_person_(\d+)_field_(\d+)$/', (string) $slug, $match)
                || (int) $match[1] !== (int) $person->id
                || ! $attributes->has((int) $match[2])
                || ! is_scalar($answer)) {
                continue;
            }

            $value = mb_substr(trim(preg_replace('/\s+/', ' ', (string) $answer)), 0, 255);

            if ($value === '') {
                continue;
            }

            $attribute = $attributes[(int) $match[2]];

            if ((string) $person->getCustomAttributeValue($attribute) === $value) {
                continue;
            }

            try {
                $this->attributeValues->save([
                    'entity_type' => 'persons',
                    'entity_id' => $person->id,
                    $attribute->code => $value,
                ], [$attribute]);
                $saved[] = $attribute->code;
            } catch (Throwable $exception) {
                Log::warning('Could not save a Cal.com booking answer on the person.', [
                    'booking_uid' => $booking['uid'] ?? null,
                    'person_id' => $person->id,
                    'attribute' => $attribute->code,
                    'exception_class' => get_class($exception),
                ]);
            }
        }

        if ($saved) {
            $person->unsetRelation('attribute_values');
        }

        return $phoneSaved || $saved !== [];
    }

    /**
     * The worker that "Send booking link" paired with the booked time, or
     * null for links made before times had their own worker (the worker is
     * then in the slug).
     */
    private function bookedWorkerId(string $slug, string $start): ?int
    {
        $link = DB::table('calcom_booking_links')->where('event_type_slug', $slug)->first();

        if (! $link) {
            return null;
        }

        $time = CarbonImmutable::parse($start)->setTimezone($link->timezone)->format('Y-m-d\TH:i');
        $workerId = json_decode($link->slot_workers, true)[$time] ?? null;

        if ($workerId === null) {
            Log::warning('Cal.com booking is at a time the booking link did not offer; no worker assigned.', [
                'event_type_slug' => $slug,
                'time' => $time,
            ]);
        }

        return $workerId === null ? null : (int) $workerId;
    }

    private function fetchBookings(CarbonImmutable $since): array
    {
        $apiKey = config('calcom.api_key');

        if (! is_string($apiKey) || $apiKey === '') {
            throw new CalcomApiException('Cal.com is not configured. Set CALCOM_API_KEY in the Krayin environment.');
        }

        $bookings = [];
        $skip = 0;

        do {
            try {
                $response = Http::withToken($apiKey)
                    ->acceptJson()
                    ->withHeaders(['cal-api-version' => self::BOOKINGS_API_VERSION])
                    ->timeout(30)
                    ->get(self::API_URL.'/bookings', [
                        'afterStart' => $since->toIso8601ZuluString(),
                        'take'       => 100,
                        'skip'       => $skip,
                    ]);
            } catch (ConnectionException $exception) {
                throw new CalcomApiException('Krayin could not connect to Cal.com to read bookings.', 0, $exception);
            }

            if (! $response->successful()) {
                throw new CalcomApiException("Cal.com rejected the bookings request (HTTP {$response->status()}).");
            }

            $page = $response->json('data') ?? [];
            $bookings = array_merge($bookings, $page);
            $skip += count($page);
        } while ($page && $response->json('pagination.hasNextPage'));

        return $bookings;
    }

    private function findActivity(string $uid)
    {
        return ActivityProxy::modelClass()::query()
            ->where('type', 'meeting')
            ->where('additional->calcom->uid', $uid)
            ->first();
    }

    private function calcomData($activity): array
    {
        $additional = is_string($activity->additional)
            ? json_decode($activity->additional, true)
            : $activity->additional;

        return $additional['calcom'] ?? [];
    }

    private function comment(array $booking): string
    {
        $attendee = data_get($booking, 'attendees.0', []);
        $lines = [
            'Booked on Cal.com by '.(trim($attendee['name'] ?? '') ?: 'the customer'),
            'Status: '.($booking['status'] ?? 'unknown'),
        ];

        $names = collect(PersonGridColumnPreferences::customAttributes())->pluck('name', 'id');

        foreach ($booking['bookingFieldsResponses'] ?? [] as $slug => $answer) {
            if (preg_match('/^txn_person_\d+_phone$/', $slug) && is_scalar($answer) && filled($answer)) {
                $lines[] = 'Phone: '.(ContactDataNormalizer::phone($answer) ?? $answer);
            }

            if (preg_match(self::FIELD_PATTERN, $slug, $match) && is_scalar($answer) && filled($answer)) {
                $lines[] = ($names[(int) $match[1]] ?? $slug).': '.$answer;
            }
        }

        if (filled($booking['cancellationReason'] ?? null)) {
            $lines[] = 'Cancellation reason: '.$booking['cancellationReason'];
        }

        return implode("\n", $lines);
    }
}
