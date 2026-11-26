<?php

namespace Webkul\Admin\Support\Replies;

use Illuminate\Support\Facades\DB;
use Webkul\Admin\Support\Contact\PipelineEvents;
use Webkul\Admin\Support\Contact\StageRoles;
use Webkul\Email\Models\Email;

/**
 * Demo of email replies (txn:demo-email-replies): a demo contact and lead,
 * a booking email "sent" to them, and made-up incoming emails run through
 * the real EmailReplyImporter, so Mail > Inbox and the lead show exactly
 * what real replies will look like. Nothing is sent and Microsoft 365 is
 * not contacted. Every name and subject says "Demo"; addresses are
 * @test.invalid (can't receive mail). Contact and lead are written straight
 * to the database, so no workflow or automation reacts.
 * remove() deletes all of it (txn:demo-email-replies --remove).
 */
final class DemoEmailReplies
{
    public const CUSTOMER = 'demo-reply-customer@test.invalid';

    public const STRANGER = 'demo-stranger@test.invalid';

    /** Message-IDs of demo emails start with this (used by remove()). */
    public const ID_PREFIX = 'txn-demo-';

    public const LEAD_TITLE = 'Demo - Gutter cleaning (email replies)';

    public function __construct(private readonly EmailReplyImporter $importer) {}

    /** @return array{lead_id: int, person_id: int, results: array<string, string>} */
    public function create(): array
    {
        $this->remove();

        $settings = (array) config('lead_triage.import');
        $pipelineId = (int) (DB::table('lead_pipelines')->where('is_default', 1)->value('id') ?: DB::table('lead_pipelines')->value('id'));
        $stageId = app(StageRoles::class)->stageId($pipelineId, StageRoles::CONTACTED)
            ?: (int) DB::table('lead_pipeline_stages')->where('lead_pipeline_id', $pipelineId)->orderBy('sort_order')->value('id');
        $userId = (int) ($settings['owner_id'] ?? 0) ?: (int) DB::table('users')->value('id');
        $mainAddress = Mailboxes::mainAddress() ?: 'demo-sender@test.invalid';
        $now = now();

        $personId = DB::table('persons')->insertGetId([
            'name' => 'Demo Reply Customer',
            'emails' => json_encode([['label' => 'work', 'value' => self::CUSTOMER]]),
            'contact_numbers' => json_encode([]),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $leadId = DB::table('leads')->insertGetId([
            'title' => self::LEAD_TITLE,
            'description' => 'Demo lead for email replies. Remove with: php artisan txn:demo-email-replies --remove',
            'lead_value' => 0,
            'status' => 1,
            'user_id' => $userId,
            'person_id' => $personId,
            'lead_source_id' => (int) ($settings['source_id'] ?? 0) ?: (int) DB::table('lead_sources')->value('id'),
            'lead_type_id' => (int) ($settings['type_id'] ?? 0) ?: (int) DB::table('lead_types')->value('id'),
            'lead_pipeline_id' => $pipelineId,
            'lead_pipeline_stage_id' => $stageId,
            'created_at' => $now->copy()->subHours(3),
            'updated_at' => $now,
        ]);

        // The booking email Krayin "sent" (Mail > Sent), the one the customer replies to.
        $sentId = self::ID_PREFIX.'booking-link@demo.invalid';
        Email::query()->create([
            'subject' => 'Demo: Book your gutter cleaning visit',
            'source' => 'web',
            'user_type' => 'admin',
            'name' => (string) config('mail.from.name', 'TXN Home Services'),
            'reply' => '<p>Hi Demo,</p><p>Thanks for reaching out about gutter cleaning. Pick a time that suits you: Tue 9:00 AM, Tue 11:00 AM or Wed 1:00 PM.</p><p>(Demo email: nothing was sent.)</p>',
            'is_read' => 1,
            'folders' => ['sent'],
            'from' => $mainAddress,
            'reply_to' => [self::CUSTOMER],
            'unique_id' => $sentId,
            'message_id' => $sentId,
            'reference_ids' => [$sentId],
            'person_id' => $personId,
            'lead_id' => $leadId,
            'created_at' => $now->copy()->subHours(2),
            'updated_at' => $now->copy()->subHours(2),
        ]);

        $at = fn (int $minutes) => $now->copy()->subMinutes($minutes)->utc()->format('Y-m-d\TH:i:s\Z');
        $messages = [
            'Reply in the thread' => $this->message('reply', self::CUSTOMER, 'Demo Reply Customer', 'Re: Demo: Book your gutter cleaning visit', $at(50),
                '<p>Hi,</p><p>Tuesday at 11 works for me. Please use the side gate.</p><p>Thanks,<br>Demo Reply Customer</p><blockquote>Pick a time that suits you: Tue 9:00 AM, Tue 11:00 AM or Wed 1:00 PM.</blockquote>',
                ['In-Reply-To' => "<{$sentId}>", 'References' => "<{$sentId}>"]),
            'New email, known sender' => $this->message('question', self::CUSTOMER, 'Demo Reply Customer', 'Demo: Do you also clean windows?', $at(30),
                '<p>One more question: do you also clean windows? Could you do both on Tuesday?</p>'),
            'Unknown sender' => $this->message('stranger', self::STRANGER, 'Demo Stranger', 'Demo: Do you do roof repairs?', $at(20),
                '<p>Hello, I found you online. Do you do roof repairs in Katy?</p>'),
            'Out-of-office (skipped)' => $this->message('ooo', self::CUSTOMER, 'Demo Reply Customer', 'Demo: Automatic reply: Out of office', $at(10),
                '<p>I am out of the office until Monday.</p>', ['Auto-Submitted' => 'auto-replied', 'X-Auto-Response-Suppress' => 'All']),
        ];

        $own = Mailboxes::addresses();
        $results = PipelineEvents::as('Demo email replies', 'command', fn () => collect($messages)
            ->map(fn ($message) => $this->importer->import($message, $mainAddress, $own))
            ->all());

        return ['lead_id' => $leadId, 'person_id' => $personId, 'results' => $results];
    }

    /** Deletes every demo email, event, lead and contact. @return int rows removed */
    public function remove(): int
    {
        $personIds = DB::table('persons')->where('emails', 'like', '%'.self::CUSTOMER.'%')->where('name', 'like', 'Demo%')->pluck('id');
        $leadIds = DB::table('leads')->whereIn('person_id', $personIds)->where('title', self::LEAD_TITLE)->pluck('id');

        $removed = DB::table('txn_events')->whereIn('lead_id', $leadIds)->orWhereIn('person_id', $personIds)
            ->orWhere(fn ($q) => $q->where('event', 'email_reply_received')->where('data', 'like', '%'.self::ID_PREFIX.'%'))
            ->delete();
        // Replies first (they point at the thread), then the rest.
        $removed += DB::table('emails')->where('message_id', 'like', self::ID_PREFIX.'%')->whereNotNull('parent_id')->delete();
        $removed += DB::table('emails')->where('message_id', 'like', self::ID_PREFIX.'%')->delete();
        $removed += DB::table('leads')->whereIn('id', $leadIds)->delete();
        $removed += DB::table('persons')->whereIn('id', $personIds)->delete();

        return $removed;
    }

    private function message(string $id, string $from, string $name, string $subject, string $received, string $html, array $headers = []): array
    {
        return [
            'id' => self::ID_PREFIX.$id,
            'internetMessageId' => '<'.self::ID_PREFIX."{$id}@demo.invalid>",
            'subject' => $subject,
            'from' => ['emailAddress' => ['address' => $from, 'name' => $name]],
            'toRecipients' => [['emailAddress' => ['address' => Mailboxes::mainAddress() ?: 'demo-sender@test.invalid']]],
            'ccRecipients' => [],
            'receivedDateTime' => $received,
            'body' => ['contentType' => 'html', 'content' => "<html><body>{$html}</body></html>"],
            'internetMessageHeaders' => collect($headers)->map(fn ($value, $key) => ['name' => $key, 'value' => $value])->values()->all(),
            'hasAttachments' => false,
        ];
    }
}
