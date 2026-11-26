<?php

namespace Webkul\Admin\Support\Replies;

use Carbon\Carbon;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;
use Webkul\Admin\Support\Contact\PipelineEvents;
use Webkul\Email\Models\Email;

/**
 * Brings customer email replies into Krayin (Mail > Inbox and the lead),
 * from every mailbox in Support\Replies\Mailboxes. Read-only towards
 * customers: it never sends anything.
 *
 * Per message:
 *  - already imported (same Message-ID) -> skipped, so checking twice is safe;
 *  - from one of our own mailboxes, or an auto-reply / bounce -> skipped;
 *  - a reply to an email in Krayin (In-Reply-To / References) -> added to that
 *    thread, same contact and lead; the thread shows unread in Inbox;
 *  - otherwise the sender's address -> contact -> newest open lead;
 *  - otherwise kept in Inbox without a contact ("No lead").
 * Rows are written with the model directly (no email.create events), so no
 * Krayin workflow reacts. Attachments are not copied yet (noted in the email).
 */
final class EmailReplyImporter
{
    private const LOCK = 'txn-email-replies';

    /** Whether the scheduler should run a check now (switch on, interval passed). */
    public static function due(): bool
    {
        if (! config('automation.email_intake.enabled')) {
            return false;
        }

        $minutes = max(1, (int) config('automation.email_intake.minutes', 2));
        $last = Cache::get(self::LOCK.'.last-run');

        return ! $last || now()->diffInSeconds(Carbon::parse($last), true) >= $minutes * 60 - 15;
    }

    /**
     * Checks every mailbox that reads replies (or only $mailboxId).
     *
     * @return array<int, array{address: string, ok: bool, message: string}>
     */
    public function run(?int $mailboxId = null): array
    {
        $lock = Cache::lock(self::LOCK, 600);

        if (! $lock->get()) {
            return [['address' => '', 'ok' => false, 'message' => 'A check is already running. Try again in a minute.']];
        }

        try {
            Cache::put(self::LOCK.'.last-run', now()->toIso8601String(), now()->addDay());
            $results = [];

            foreach (Mailboxes::all() as $mailbox) {
                if ($mailboxId ? (int) $mailbox->id !== $mailboxId : ! $mailbox->read_replies) {
                    continue;
                }

                $results[] = ['address' => $mailbox->address] + $this->checkMailbox($mailbox);
            }

            return $results;
        } finally {
            $lock->release();
        }
    }

    /** @return array{ok: bool, message: string} */
    private function checkMailbox(object $mailbox): array
    {
        $client = Mailboxes::client($mailbox);

        if (! $client) {
            $message = $mailbox->is_main
                ? 'No Microsoft 365 login on Connections (tenant ID, client ID, client secret).'
                : 'No login: add the tenant ID, client ID and secret.';
            Mailboxes::markChecked($mailbox->id, null, $message);

            return ['ok' => false, 'message' => $message];
        }

        // First check: look back a few days. Later: a little before the last
        // message seen (Graph times are to the second), duplicates are skipped.
        $since = $mailbox->received_until
            ? Carbon::parse($mailbox->received_until)->subMinutes(5)
            : now()->subDays(max(1, (int) config('automation.email_intake.lookback_days', 3)));

        try {
            $messages = $client->messagesSince($since);
        } catch (Throwable $exception) {
            Mailboxes::markChecked($mailbox->id, null, $exception->getMessage());
            Log::warning('Email replies: mailbox check failed.', ['mailbox' => $mailbox->address, 'error' => $exception->getMessage()]);

            return ['ok' => false, 'message' => $exception->getMessage()];
        }

        $counts = ['imported' => 0, 'no_lead' => 0, 'duplicate' => 0, 'skipped' => 0, 'failed' => 0];
        $newest = $mailbox->received_until ? Carbon::parse($mailbox->received_until) : null;
        $own = Mailboxes::addresses();

        foreach ($messages as $message) {
            try {
                $status = $this->import($message, $mailbox->address, $own);
            } catch (Throwable $exception) {
                $status = 'failed';
                Log::warning('Email replies: one email could not be imported.', ['mailbox' => $mailbox->address, 'graph_id' => $message['id'] ?? null, 'error' => $exception->getMessage()]);
            }

            $counts[$status]++;

            if (in_array($status, ['imported', 'no_lead'], true) && config('automation.email_intake.mark_read', true) && ! empty($message['id'])) {
                $client->markRead($message['id']);
            }

            if (! empty($message['receivedDateTime'])) {
                $received = Carbon::parse($message['receivedDateTime']);
                $newest = ! $newest || $received->gt($newest) ? $received : $newest;
            }
        }

        $summary = count($messages) === 0
            ? 'No new emails.'
            : collect(['imported' => 'added to leads', 'no_lead' => 'without a lead', 'duplicate' => 'already in Krayin', 'skipped' => 'skipped (ours / auto-reply)', 'failed' => 'failed'])
                ->filter(fn ($label, $key) => $counts[$key] > 0)
                ->map(fn ($label, $key) => "{$counts[$key]} {$label}")
                ->implode(', ').'.';

        Mailboxes::markChecked($mailbox->id, $summary, $counts['failed'] ? "{$counts['failed']} email(s) could not be imported; see the Krayin log." : null, $newest ? $newest->copy()->timezone(config('app.timezone'))->format('Y-m-d H:i:s') : null);

        return ['ok' => $counts['failed'] === 0, 'message' => $summary];
    }

    /**
     * One Graph message -> imported | no_lead | duplicate | skipped.
     *
     * @param  array<int, string>  $own  our mailbox addresses (lower case)
     */
    public function import(array $message, string $mailboxAddress, array $own): string
    {
        $messageId = self::cleanId((string) ($message['internetMessageId'] ?? '')) ?: 'graph-'.($message['id'] ?? uniqid('', true));

        if (Email::query()->where('message_id', $messageId)->orWhere('unique_id', $messageId)->exists()) {
            return 'duplicate';
        }

        $headers = collect((array) ($message['internetMessageHeaders'] ?? []))
            ->mapWithKeys(fn ($h) => [strtolower((string) ($h['name'] ?? '')) => (string) ($h['value'] ?? '')]);
        $fromAddress = strtolower(trim((string) data_get($message, 'from.emailAddress.address', '')));
        $fromName = trim((string) data_get($message, 'from.emailAddress.name', ''));

        if ($fromAddress === '' || in_array($fromAddress, $own, true) || self::isAutomatic($headers->all(), $fromAddress)) {
            return 'skipped';
        }

        $references = array_values(array_unique(array_merge(
            self::ids($headers->get('in-reply-to', '')),
            self::ids($headers->get('references', ''))
        )));

        // 1. A reply to an email Krayin has: join that thread (its first email).
        $parent = $references ? Email::query()->whereIn('message_id', $references)->orderByDesc('id')->first() : null;

        if (! $parent && $references) {
            foreach ($references as $reference) {
                if ($parent = Email::query()->where('reference_ids', 'like', '%'.addcslashes($reference, '%_\\').'%')->orderByDesc('id')->first()) {
                    break;
                }
            }
        }

        $root = $parent && $parent->parent_id ? (Email::query()->find($parent->parent_id) ?? $parent) : $parent;

        $personId = $root?->person_id;
        $leadId = $root?->lead_id;
        $matchedBy = $root ? 'thread' : null;

        // 2. Otherwise the sender: contact with that address, newest open lead.
        if (! $personId) {
            $personId = self::personByEmail($fromAddress);
            $matchedBy = $personId ? 'sender' : $matchedBy;
        }

        if ($personId && ! $leadId) {
            $leadId = self::openLeadOf($personId);
        }

        $received = ! empty($message['receivedDateTime']) ? Carbon::parse($message['receivedDateTime'])->timezone(config('app.timezone')) : now();
        $body = self::cleanHtml((string) data_get($message, 'body.content', ''));

        if (! empty($message['hasAttachments'])) {
            $body .= '<p style="color:#6b7280;font-size:12px;">(This email has attachments. Open it in the '.e($mailboxAddress).' mailbox to see them.)</p>';
        }

        DB::transaction(function () use ($message, $messageId, $fromAddress, $fromName, $references, $root, $personId, $leadId, $received, $body) {
            Email::query()->create([
                'subject' => mb_substr((string) ($message['subject'] ?? ''), 0, 250),
                'source' => 'email',
                'user_type' => 'person',
                'name' => $fromName ?: $fromAddress,
                'reply' => $body,
                'is_read' => 0,
                'folders' => ['inbox'],
                'from' => $fromAddress,
                'reply_to' => self::addressList($message['toRecipients'] ?? []),
                'cc' => self::addressList($message['ccRecipients'] ?? []),
                'bcc' => [],
                'unique_id' => $messageId,
                'message_id' => $messageId,
                'reference_ids' => array_values(array_unique(array_merge([$messageId], $references))),
                'person_id' => $personId,
                'lead_id' => $leadId,
                'parent_id' => $root?->id,
                'created_at' => $received,
                'updated_at' => $received,
            ]);

            // The thread shows in Inbox, unread, on top.
            if ($root) {
                $root->forceFill([
                    'folders' => array_values(array_unique(array_merge((array) $root->folders, ['inbox']))),
                    'is_read' => 0,
                    'updated_at' => now(),
                ])->save();
            }
        });

        $who = $fromName ? "{$fromName} <{$fromAddress}>" : $fromAddress;
        PipelineEvents::record(
            'email_reply_received',
            $leadId ? "Email from {$who}: ".mb_substr((string) ($message['subject'] ?? '(no subject)'), 0, 150) : "Email from {$who} (no lead found)",
            ['from' => $fromAddress, 'mailbox' => $mailboxAddress, 'matched_by' => $matchedBy, 'message_id' => $messageId],
            $leadId,
            $personId
        );

        return $leadId ? 'imported' : 'no_lead';
    }

    /** Auto-replies, out-of-office, bounces and bulk mail. */
    public static function isAutomatic(array $headers, string $from): bool
    {
        $local = strstr($from, '@', true) ?: $from;
        $auto = strtolower(trim($headers['auto-submitted'] ?? 'no'));
        $precedence = strtolower(trim($headers['precedence'] ?? ''));

        return ($auto !== '' && $auto !== 'no')
            || isset($headers['x-autoreply']) || isset($headers['x-autorespond'])
            || in_array($precedence, ['bulk', 'auto_reply', 'junk', 'list'], true)
            || str_contains(strtolower($headers['content-type'] ?? ''), 'multipart/report')
            || in_array($local, ['mailer-daemon', 'postmaster', 'no-reply', 'noreply', 'donotreply', 'do-not-reply'], true);
    }

    /** Message-IDs in a header ("<a@b> <c@d>") without the angle brackets. */
    public static function ids(string $header): array
    {
        preg_match_all('/<([^<>\s]+)>/', $header, $matches);

        return array_values(array_filter(array_map([self::class, 'cleanId'], $matches[1] ?: (trim($header) !== '' ? preg_split('/\s+/', trim($header)) : []))));
    }

    public static function cleanId(string $id): string
    {
        return mb_substr(trim($id, " \t\r\n<>"), 0, 190);
    }

    private static function personByEmail(string $address): ?int
    {
        $like = '%"'.addcslashes($address, '%_\\').'"%';

        $id = DB::table('persons')->whereRaw('LOWER(emails) LIKE ?', [$like])->orderByDesc('id')->value('id');

        return $id ? (int) $id : null;
    }

    /** The contact's newest lead that is not won or lost. */
    private static function openLeadOf(int $personId): ?int
    {
        $id = DB::table('leads')
            ->leftJoin('lead_pipeline_stages as s', 's.id', '=', 'leads.lead_pipeline_stage_id')
            ->where('leads.person_id', $personId)
            ->where(fn ($q) => $q->whereNull('s.code')->orWhereNotIn('s.code', ['won', 'lost']))
            ->orderByDesc('leads.id')
            ->value('leads.id');

        return $id ? (int) $id : null;
    }

    private static function addressList(array $recipients): array
    {
        return collect($recipients)->map(fn ($r) => strtolower((string) data_get($r, 'emailAddress.address', '')))->filter()->values()->all();
    }

    /**
     * The email's HTML, safe to show inside Krayin: scripts, styles, frames,
     * forms and event handlers removed, javascript: links dropped.
     */
    public static function cleanHtml(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        if (preg_match('~<body[^>]*>(.*)</body>~is', $html, $match)) {
            $html = $match[1];
        }

        $doc = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?><div id="txn-mail">'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new DOMXPath($doc);

        foreach (iterator_to_array($xpath->query('//script|//style|//iframe|//frame|//frameset|//object|//embed|//form|//input|//button|//textarea|//select|//meta|//link|//base|//svg|//math')) as $node) {
            $node->parentNode?->removeChild($node);
        }

        foreach (iterator_to_array($xpath->query('//*')) as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            foreach (iterator_to_array($node->attributes) as $attribute) {
                $name = strtolower($attribute->name);
                $value = strtolower(preg_replace('/[\s\x00-\x1f]+/', '', $attribute->value));

                if (str_starts_with($name, 'on')
                    || in_array($name, ['srcdoc', 'formaction', 'xlink:href'], true)
                    || (in_array($name, ['href', 'src', 'action', 'background', 'poster'], true) && (str_starts_with($value, 'javascript:') || str_starts_with($value, 'vbscript:') || (str_starts_with($value, 'data:') && ! str_starts_with($value, 'data:image/'))))
                    || ($name === 'style' && (str_contains($value, 'expression(') || str_contains($value, 'javascript:') || str_contains($value, 'url(')))) {
                    $node->removeAttribute($attribute->name);
                }
            }

            if ($node->nodeName === 'a') {
                $node->setAttribute('target', '_blank');
                $node->setAttribute('rel', 'noopener noreferrer');
            }
        }

        $wrapper = $doc->getElementById('txn-mail');
        $out = '';

        foreach ($wrapper ? iterator_to_array($wrapper->childNodes) : [] as $child) {
            $out .= $doc->saveHTML($child);
        }

        return $out;
    }
}
