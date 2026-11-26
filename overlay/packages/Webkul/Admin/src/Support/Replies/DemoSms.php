<?php

namespace Webkul\Admin\Support\Replies;

use Illuminate\Support\Facades\DB;
use Webkul\Admin\Support\Contact\PipelineEvents;
use Webkul\Admin\Support\Contact\StageRoles;

/**
 * Demo of incoming texts (txn:demo-sms): demo contacts and a demo lead, a
 * booking text "sent" by Krayin, and made-up incoming texts run through the
 * real SmsInbox matching, so Leads > SMS messages and the lead show what
 * real texts will look like. Nothing is sent and Twilio is not contacted.
 * Every name says "Demo"; numbers are fictional 555-01xx ones. Contacts and
 * lead are written straight to the database, so no workflow reacts.
 * remove() deletes all of it (txn:demo-sms --remove).
 */
final class DemoSms
{
    public const CUSTOMER = '+12025550142';

    public const UNKNOWN = '+12025550199';

    public const SHARED = '+12025550177';

    public const SID_PREFIX = 'SMdemo-';

    public const LEAD_TITLE = 'Demo - Window cleaning (SMS)';

    public function __construct(private readonly SmsInbox $inbox) {}

    /** @return array{lead_id: int, person_id: int, results: array<string, string>} */
    public function create(): array
    {
        $this->remove();

        $settings = (array) config('lead_triage.import');
        $pipelineId = (int) (DB::table('lead_pipelines')->where('is_default', 1)->value('id') ?: DB::table('lead_pipelines')->value('id'));
        $stageId = app(StageRoles::class)->stageId($pipelineId, StageRoles::CONTACTED)
            ?: (int) DB::table('lead_pipeline_stages')->where('lead_pipeline_id', $pipelineId)->orderBy('sort_order')->value('id');
        $ourNumber = (string) (config('sms.from_number') ?: '+12025550100');
        $now = now();

        // The number written differently from Twilio's +1… form, to show the matching.
        $personId = $this->person('Demo SMS Customer', '(202) 555-0142', $now);
        $this->person('Demo SMS Shared A', '202-555-0177', $now);
        $this->person('Demo SMS Shared B', '+1 202 555 0177', $now);

        $leadId = DB::table('leads')->insertGetId([
            'title' => self::LEAD_TITLE,
            'description' => 'Demo lead for incoming texts. Remove with: php artisan txn:demo-sms --remove',
            'lead_value' => 0,
            'status' => 1,
            'user_id' => (int) ($settings['owner_id'] ?? 0) ?: (int) DB::table('users')->value('id'),
            'person_id' => $personId,
            'lead_source_id' => (int) ($settings['source_id'] ?? 0) ?: (int) DB::table('lead_sources')->value('id'),
            'lead_type_id' => (int) ($settings['type_id'] ?? 0) ?: (int) DB::table('lead_types')->value('id'),
            'lead_pipeline_id' => $pipelineId,
            'lead_pipeline_stage_id' => $stageId,
            'created_at' => $now->copy()->subHours(3),
            'updated_at' => $now,
        ]);

        // The booking text Krayin "sent" (as SmsSender keeps it).
        SmsInbox::recordOutgoing(self::SID_PREFIX.'booking-link', self::CUSTOMER, $ourNumber,
            'Hi Demo, this is TXN Home Services. Pick a time for your free window cleaning estimate here: https://cal.com/demo (Demo text: nothing was sent.)',
            $personId, $leadId, null, $now->copy()->subHours(2));

        $texts = [
            'Reply from the customer' => ['reply', self::CUSTOMER, 'Demo: Tuesday at 11 works for me, thanks!', 50],
            'Second text, same customer' => ['follow-up', self::CUSTOMER, 'Demo: also, can you do the gutters on the same visit?', 40],
            'Unknown number' => ['unknown', self::UNKNOWN, 'Demo: hi, do you do roof repairs in Katy?', 30],
            'Number on two contacts (needs staff)' => ['shared', self::SHARED, 'Demo: is this TXN Home? I booked last week and need to move it.', 20],
        ];

        $results = PipelineEvents::as('Demo texts', 'command', fn () => collect($texts)->map(function ($text) use ($ourNumber, $now) {
            [$id, $from, $body, $minutesAgo] = $text;
            $result = $this->inbox->storeIncoming(['MessageSid' => self::SID_PREFIX.$id, 'From' => $from, 'To' => $ourNumber, 'Body' => $body, 'NumMedia' => '0'], $now->copy()->subMinutes($minutesAgo));

            return match (true) {
                $result['status'] !== 'stored' => $result['status'],
                ! empty($result['needs_staff']) => 'needs_staff',
                ! empty($result['lead_id']) => 'lead',
                default => 'unknown',
            };
        })->all());

        return ['lead_id' => $leadId, 'person_id' => $personId, 'results' => $results];
    }

    /** Deletes every demo text, event, lead and contact. @return int rows removed */
    public function remove(): int
    {
        $personIds = DB::table('persons')->where('name', 'like', 'Demo SMS %')->where('contact_numbers', 'like', '%555%')->pluck('id');
        $leadIds = DB::table('leads')->whereIn('person_id', $personIds)->where('title', self::LEAD_TITLE)->pluck('id');

        $removed = DB::table('txn_events')->whereIn('lead_id', $leadIds)->orWhereIn('person_id', $personIds)
            ->orWhere(fn ($q) => $q->where('event', 'sms_received')->where('data', 'like', '%'.self::SID_PREFIX.'%'))
            ->delete();
        $removed += DB::table('txn_sms_messages')->where('message_sid', 'like', self::SID_PREFIX.'%')->delete();
        $removed += DB::table('leads')->whereIn('id', $leadIds)->delete();
        $removed += DB::table('persons')->whereIn('id', $personIds)->delete();

        return $removed;
    }

    private function person(string $name, string $number, $now): int
    {
        return DB::table('persons')->insertGetId([
            'name' => $name,
            'emails' => json_encode([]),
            'contact_numbers' => json_encode([['label' => 'mobile', 'value' => $number]]),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
