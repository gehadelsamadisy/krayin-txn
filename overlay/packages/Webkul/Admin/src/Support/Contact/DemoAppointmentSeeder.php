<?php

namespace Webkul\Admin\Support\Contact;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Webkul\Activity\Models\ActivityProxy;
use Webkul\Admin\Models\Worker;
use Webkul\Contact\Models\Person;

/**
 * Demo appointments for the real leads and contacts already in Krayin
 * (php artisan txn:demo-appointments [--remove]).
 *
 * It replays a week and a half of leads arriving: every evening from the
 * Sunday before this week, the next few leads ask for a visit, the slot
 * finder offers its times, and the "customer" picks one (usually the first).
 * So the demo follows the real rules: 9/11/1/3/5, work hours, the worker
 * already working nearby, no overlaps. The customer passes on times that
 * would make the worker late (Routes page drive times). Visits that have
 * already ended are marked done. Leads with a street address go first.
 *
 * Only activities are created, tagged additional.txn.demo; contacts and
 * leads are linked, never changed. --remove deletes the tagged activities
 * (and the customers of the earlier, made-up demo, @txn-demo.example).
 */
final class DemoAppointmentSeeder
{
    private const OLD_DEMO_EMAIL_DOMAIN = 'txn-demo.example';

    // Evenings on which leads arrive, and how many arrive each evening.
    private const ARRIVAL_DAYS = 10;

    private const LEADS_PER_DAY = 7;

    // Busy blocks without an address: [worker name, days after this Monday, start, end, title].
    private const BUSY_BLOCKS = [
        ['Worker 1', 4, '15:00', '16:00', 'Truck service'],
        ['Worker 2', 8, '11:00', '12:00', 'Supplier pickup'],
    ];

    public function __construct(
        private SlotSuggestionService $slotSuggestionService,
        private WorkerRouteService $routes
    ) {}

    /** @return array{appointments: int, busy: int, done: int, skipped: array<string, int>} */
    public function create(): array
    {
        $this->remove();

        $timezone = config('slot_finder.timezone');
        $realNow = CarbonImmutable::now($timezone);
        $monday = $realNow->startOfWeek(CarbonImmutable::MONDAY)->startOfDay();
        $workers = Worker::query()->pluck('id', 'name');
        $result = ['appointments' => 0, 'busy' => 0, 'done' => 0, 'skipped' => []];

        foreach (self::BUSY_BLOCKS as [$workerName, $day, $start, $end, $title]) {
            if (! isset($workers[$workerName])) {
                continue;
            }

            $date = $monday->addDays($day)->toDateString();
            $this->createActivity([
                'title' => "Busy: {$title}",
                'schedule_from' => "{$date} {$start}:00",
                'schedule_to' => "{$date} {$end}:00",
                'comment' => "Worker: {$workerName}\nDemo busy block",
                'kind' => 'busy',
            ], $workers[$workerName]);
            $result['busy']++;
        }

        $leads = $this->leads()->take(self::ARRIVAL_DAYS * self::LEADS_PER_DAY)->values();

        foreach ($leads as $index => $lead) {
            $arrival = $monday->subDay()->addDays(intdiv($index, self::LEADS_PER_DAY))->setTime(18, 0);
            $person = Person::query()->find($lead->person_id);
            $offer = $this->slotSuggestionService->suggestForPerson($person, null, $arrival);

            if ($offer['status'] !== SlotSuggestionService::OK) {
                $result['skipped'][$offer['status']] = ($result['skipped'][$offer['status']] ?? 0) + 1;

                continue;
            }

            // Most customers take the first time offered, some another. The
            // slot finder may offer times the worker can't reach (driving only
            // ranks), so the demo customer passes on any time that would make
            // the worker late on the Routes page, and books nothing if all do.
            $preferred = [0, 0, 0, 0, 1, 2][$index % 6];
            $choices = array_keys($offer['options']);
            usort($choices, fn ($a, $b) => [$a !== $preferred, $a] <=> [$b !== $preferred, $b]);
            $booked = false;

            foreach ($choices as $number) {
                if ($this->bookIfOnTime($offer, $offer['options'][$number], $person, $lead, $arrival)) {
                    $booked = true;
                    break;
                }
            }

            if ($booked) {
                $result['appointments']++;
            } else {
                $result['skipped']['NO_REACHABLE_TIME'] = ($result['skipped']['NO_REACHABLE_TIME'] ?? 0) + 1;
            }
        }

        // Visits that have already ended were carried out.
        $result['done'] = ActivityProxy::modelClass()::query()
            ->where('additional->txn->demo', true)
            ->where('additional->txn->kind', 'visit')
            ->where('schedule_to', '<=', $realNow->format('Y-m-d H:i:s'))
            ->update(['is_done' => true]);

        return $result;
    }

    /** Book the visit, unless it leaves any stop that day late; then undo it. */
    private function bookIfOnTime(array $offer, array $choice, Person $person, $lead, CarbonImmutable $arrival): bool
    {
        $start = CarbonImmutable::createFromFormat('Y-m-d\TH:i', $choice['value'], config('slot_finder.timezone'));

        DB::beginTransaction();

        $this->createActivity([
            'title' => "Home visit - {$person->name}",
            'schedule_from' => $start->format('Y-m-d H:i:s'),
            'schedule_to' => $start->addMinutes(config('slot_finder.visit_minutes'))->format('Y-m-d H:i:s'),
            'location' => $lead->address,
            'latitude' => $offer['location']['lat'],
            'longitude' => $offer['location']['lon'],
            'comment' => implode("\n", array_filter([
                "Worker: {$choice['worker']}",
                $lead->service ? "Service: {$lead->service}" : null,
                $offer['location']['precision'] === 'zip' ? 'Location: ZIP code only' : null,
                'Demo appointment, booked by the slot finder on '.$arrival->format('D M j'),
            ])),
            'kind' => 'visit',
            'service' => $lead->service,
            'person_id' => $person->id,
            'lead_id' => $lead->id,
        ], $choice['worker_id']);

        $route = $this->routes->dayRoute(Worker::query()->findOrFail($choice['worker_id']), $start->startOfDay());
        $onTime = ! collect($route['stops'])->contains(fn ($stop) => $stop['late_minutes'] > 0);

        $onTime ? DB::commit() : DB::rollBack();

        return $onTime;
    }

    /** @return array{customers: int, appointments: int} */
    public function remove(): array
    {
        return DB::transaction(function () {
            $persons = Person::query()->where('emails', 'like', '%@'.self::OLD_DEMO_EMAIL_DOMAIN.'%')->get();
            // The demo appointments, plus the history entries Krayin adds when
            // one of the made-up demo customers was created.
            $activities = ActivityProxy::modelClass()::query()
                ->where('additional->txn->demo', true)
                ->orWhereIn('id', DB::table('person_activities')->whereIn('person_id', $persons->pluck('id'))->select('activity_id'))
                ->get();

            foreach (['lead_activities', 'person_activities', 'activity_workers'] as $table) {
                DB::table($table)->whereIn('activity_id', $activities->pluck('id'))->delete();
            }

            $activities->each->delete();
            $persons->each(function (Person $person) {
                DB::table('attribute_values')
                    ->where('entity_type', 'persons')
                    ->where('entity_id', $person->id)
                    ->delete();
                $person->delete();
            });

            return [
                'customers'    => $persons->count(),
                'appointments' => $activities->filter(fn ($activity) => $activity->type === 'meeting')->count(),
            ];
        });
    }

    /**
     * Leads with their contact's address and service, street addresses first,
     * then in the order they were received.
     */
    private function leads()
    {
        $attributes = DB::table('attributes')
            ->whereIn('code', ['csv_address', 'csv_city', 'csv_postal_code', 'csv_service_name', 'csv_received_at'])
            ->pluck('id', 'code');

        $value = fn (string $entity, string $code, string $column = 'text_value') => DB::table('attribute_values')
            ->select($column)
            ->where('entity_type', $entity)
            ->where('attribute_id', $attributes[$code] ?? 0)
            ->whereColumn('entity_id', $entity === 'leads' ? 'leads.id' : 'leads.person_id')
            ->limit(1);

        return DB::table('leads')
            ->whereNotNull('person_id')
            ->whereNotIn('person_id', Person::query()->where('emails', 'like', '%@'.self::OLD_DEMO_EMAIL_DOMAIN.'%')->select('id'))
            ->select(['leads.id', 'leads.person_id'])
            ->selectSub($value('persons', 'csv_address'), 'street')
            ->selectSub($value('persons', 'csv_city'), 'city')
            ->selectSub($value('persons', 'csv_postal_code'), 'zip')
            ->selectSub($value('leads', 'csv_service_name'), 'service')
            ->selectSub($value('leads', 'csv_received_at', 'datetime_value'), 'received_at')
            ->get()
            ->map(function ($lead) {
                $lead->has_street = (bool) preg_match('/^\d+[A-Za-z]?\s+\S*[A-Za-z]/', trim((string) $lead->street));
                $lead->address = collect([
                    $lead->has_street ? trim($lead->street) : null,
                    $lead->city ? trim($lead->city) : null,
                    trim('TX '.trim((string) $lead->zip)),
                ])->filter()->implode(', ');

                return $lead;
            })
            ->filter(fn ($lead) => $lead->has_street || filled($lead->zip))
            ->sortBy([['has_street', 'desc'], ['received_at', 'asc'], ['id', 'asc']])
            ->values();
    }

    private function createActivity(array $data, int $workerId): void
    {
        DB::transaction(function () use ($data, $workerId) {
            $activity = ActivityProxy::modelClass()::create([
                'title'         => $data['title'],
                'type'          => 'meeting',
                'location'      => $data['location'] ?? null,
                'comment'       => $data['comment'],
                'schedule_from' => $data['schedule_from'],
                'schedule_to'   => $data['schedule_to'],
                'is_done'       => false,
                'user_id'       => config('calcom.activity_user_id'),
                'additional'    => json_encode(['txn' => array_filter([
                    'demo'    => true,
                    'kind'    => $data['kind'],
                    'service' => $data['service'] ?? null,
                ])]),
            ]);

            $activity->forceFill([
                'worker_id' => $workerId,
                'latitude'  => $data['latitude'] ?? null,
                'longitude' => $data['longitude'] ?? null,
            ])->save();
            $activity->workers()->attach($workerId);

            // Linked to the contact only, never added as a participant:
            // Krayin's "Emails to participants" workflows would email the
            // real customer about a demo visit whenever it is edited.
            if (isset($data['person_id'])) {
                $activity->persons()->attach($data['person_id']);
            }

            if (isset($data['lead_id'])) {
                $activity->leads()->attach($data['lead_id']);
            }
        });
    }
}
