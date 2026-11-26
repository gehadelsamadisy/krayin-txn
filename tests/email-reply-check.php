<?php

/*
 * Email replies check (Support\Replies: Mailboxes, GraphMailbox,
 * EmailReplyImporter; Settings > TXN Settings > Mailboxes).
 *
 * Run inside the Krayin container (copy the file in first):
 *   docker cp tests/email-reply-check.php krayin:/var/www/laravel-crm/tests/
 *   docker exec -u www-data -e HOME=/tmp krayin php tests/email-reply-check.php
 *
 * Every Microsoft 365 call is faked (fake logins, *.example addresses), so
 * nothing reaches Microsoft and nothing is sent. All rows are written inside
 * a transaction that is rolled back at the end.
 * Exit code = number of failed checks.
 */

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Webkul\Admin\Support\Contact\TxnSettings;
use Webkul\Admin\Support\Replies\EmailReplyImporter;
use Webkul\Admin\Support\Replies\Mailboxes;
use Webkul\Contact\Repositories\PersonRepository;
use Webkul\Email\Models\Email;
use Webkul\Lead\Repositories\LeadRepository;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$failures = 0;

function check(string $name, bool $ok, string $detail = ''): void
{
    global $failures;
    echo ($ok ? '  ok    ' : '  FAIL  ').$name.($ok || $detail === '' ? '' : " ({$detail})").PHP_EOL;
    $failures += $ok ? 0 : 1;
}

/** A Graph message as /mailFolders/inbox/messages returns it. */
function graphMessage(string $id, string $from, string $subject, array $headers = [], string $html = '<p>Tuesday at 11 works.</p>', ?string $received = null): array
{
    return [
        'id' => "graph-{$id}",
        'internetMessageId' => "<{$id}@mail.example>",
        'subject' => $subject,
        'from' => ['emailAddress' => ['address' => $from, 'name' => 'Check Customer']],
        'toRecipients' => [['emailAddress' => ['address' => 'main@txn-check.example']]],
        'ccRecipients' => [],
        'receivedDateTime' => $received ?? now()->subMinutes(10)->utc()->format('Y-m-d\TH:i:s\Z'),
        'body' => ['contentType' => 'html', 'content' => "<html><body>{$html}</body></html>"],
        'internetMessageHeaders' => collect($headers)->map(fn ($v, $k) => ['name' => $k, 'value' => $v])->values()->all(),
        'hasAttachments' => false,
    ];
}

/** Fake Microsoft 365: token, then one inbox page per mailbox (by address in the URL). */
function fakeGraph(array $inboxes, int $status = 200): void
{
    Http::swap(new Factory());
    Http::fake(function (Request $request) use ($inboxes, $status) {
        if (str_contains($request->url(), 'login.microsoftonline.com')) {
            return Http::response(['access_token' => 'check-token', 'expires_in' => 3600], 200);
        }

        if ($request->method() === 'PATCH') {
            return Http::response(['isRead' => true], 200);
        }

        foreach ($inboxes as $address => $messages) {
            if (str_contains(urldecode($request->url()), "/users/{$address}/")) {
                return $status === 200
                    ? Http::response(['value' => $messages], 200)
                    : Http::response(['error' => ['code' => 'ErrorAccessDenied', 'message' => 'Access is denied.']], $status);
            }
        }

        return Http::response(['value' => []], 200);
    });
}

DB::beginTransaction();

try {
    DB::table('txn_settings')->delete();
    TxnSettings::apply();

    config([
        'cache.default' => 'array',
        'mail.from.address' => 'main@txn-check.example',
        'graph_mail.tenant_id' => '11111111-1111-1111-1111-111111111111',
        'graph_mail.client_id' => '22222222-2222-2222-2222-222222222222',
        'graph_mail.client_secret' => 'main-check-secret',
        'automation.email_intake.enabled' => true,
        'automation.email_intake.mark_read' => true,
        'automation.email_intake.lookback_days' => 3,
    ]);
    DB::table('txn_mailboxes')->delete();

    $adminId = DB::table('users')->join('roles', 'roles.id', '=', 'users.role_id')
        ->where('roles.permission_type', 'all')->where('users.status', 1)->value('users.id');
    auth()->guard('user')->setUser(Webkul\User\Models\User::find($adminId));

    // 1. Mailbox list: the main sender is always there, with the Connections login.
    $all = Mailboxes::all();
    $main = $all->firstWhere('address', 'main@txn-check.example');
    check('main sender is in the list', $main && $main->is_main);
    check('main sender uses the Connections login', (Mailboxes::login($main)['secret'] ?? null) === 'main-check-secret');
    check('main sender keeps no secret of its own', $main && $main->client_secret === null);

    // 2. A second mailbox with its own login; the secret is stored encrypted.
    [$otherId, $errors] = Mailboxes::save(null, [
        'address' => 'Estimates@TXN-check.example', 'name' => 'Estimates',
        'tenant_id' => '33333333-3333-3333-3333-333333333333', 'client_id' => '44444444-4444-4444-4444-444444444444',
        'client_secret' => 'other-check-secret', 'read_replies' => '1',
    ], $adminId);
    $other = Mailboxes::find((int) $otherId);
    check('second mailbox saved', $otherId && ! $errors, implode(' ', $errors));
    check('address stored in lower case', $other && $other->address === 'estimates@txn-check.example');
    check('secret stored encrypted', $other && $other->client_secret !== 'other-check-secret' && Crypt::decryptString($other->client_secret) === 'other-check-secret');
    check('own login used for it', (Mailboxes::login($other)['client'] ?? '') === '44444444-4444-4444-4444-444444444444');
    $log = DB::table('txn_settings_log')->where('area', Mailboxes::AREA)->pluck('new_value')->implode(' ');
    check('change log never holds the secret', ! str_contains($log, 'other-check-secret') && str_contains($log, '…cret'), $log);

    [, $errors] = Mailboxes::save(null, ['address' => 'estimates@txn-check.example', 'tenant_id' => 'x', 'client_id' => 'y', 'client_secret' => ''], $adminId);
    check('duplicate address and bad IDs refused', count($errors) >= 3, implode(' | ', $errors));

    [, $errors] = Mailboxes::save((int) $otherId, ['address' => 'estimates@txn-check.example', 'name' => 'Estimates', 'tenant_id' => $other->tenant_id, 'client_id' => $other->client_id, 'client_secret' => '', 'read_replies' => '1'], $adminId);
    check('blank secret on edit keeps the stored one', ! $errors && Crypt::decryptString(Mailboxes::find((int) $otherId)->client_secret) === 'other-check-secret', implode(' ', $errors));
    check('main sender cannot be removed', ! Mailboxes::delete((int) $main->id, $adminId));

    // 3. Customer, open lead, and the booking email Krayin sent them.
    $person = app(PersonRepository::class)->create([
        'entity_type' => 'persons', 'name' => 'Reply Check',
        'emails' => [['label' => 'work', 'value' => 'customer@reply-check.example']],
        'contact_numbers' => [['label' => 'work', 'value' => '+17135550123']],
        'user_id' => $adminId,
    ]);
    $settings = config('lead_triage.import');
    $defaultId = (int) DB::table('lead_pipelines')->where('is_default', 1)->value('id');
    $stage = (int) DB::table('lead_pipeline_stages')->where('lead_pipeline_id', $defaultId)->where('code', 'new')->value('id');
    $lead = app(LeadRepository::class)->create([
        'entity_type' => 'leads', 'title' => 'Gutter - Reply Check', 'lead_value' => 0, 'status' => 1,
        'lead_source_id' => $settings['source_id'], 'lead_type_id' => $settings['type_id'], 'user_id' => $settings['owner_id'],
        'lead_pipeline_id' => $defaultId, 'lead_pipeline_stage_id' => $stage, 'person' => ['id' => $person->id],
    ]);
    $sent = Email::query()->create([
        'subject' => 'Book your gutter visit', 'source' => 'web', 'user_type' => 'admin', 'name' => 'TXN',
        'reply' => '<p>Pick a time</p>', 'is_read' => 1, 'folders' => ['sent'], 'from' => 'main@txn-check.example',
        'reply_to' => ['customer@reply-check.example'], 'unique_id' => 'sent-1@txn-check.example', 'message_id' => 'sent-1@txn-check.example',
        'reference_ids' => ['sent-1@txn-check.example'], 'person_id' => $person->id, 'lead_id' => $lead->id,
    ]);

    // 4. Import from both mailboxes.
    fakeGraph([
        'main@txn-check.example' => [
            graphMessage('reply-1', 'Customer@Reply-Check.example', 'Re: Book your gutter visit', ['In-Reply-To' => '<sent-1@txn-check.example>', 'References' => '<sent-1@txn-check.example>'],
                '<p onclick="alert(1)">Tuesday at 11 works.</p><script>alert(2)</script><a href="javascript:alert(3)">x</a>'),
            graphMessage('bounce-1', 'mailer-daemon@outlook.example', 'Undeliverable', ['Auto-Submitted' => 'auto-replied']),
            graphMessage('ooo-1', 'someone@reply-check.example', 'Out of office', ['Auto-Submitted' => 'auto-replied']),
            graphMessage('own-1', 'estimates@txn-check.example', 'Internal note'),
        ],
        'estimates@txn-check.example' => [
            graphMessage('fresh-1', 'customer@reply-check.example', 'Question about my estimate'),
            graphMessage('stranger-1', 'stranger@unknown-check.example', 'Do you do roofs?'),
        ],
    ]);

    $results = app(EmailReplyImporter::class)->run();
    check('both mailboxes checked', count($results) === 2 && collect($results)->every(fn ($r) => $r['ok']), json_encode($results));

    $reply = Email::query()->where('message_id', 'reply-1@mail.example')->first();
    check('reply imported', (bool) $reply);
    check('reply joins the sent email\'s thread', $reply && (int) $reply->parent_id === (int) $sent->id);
    check('reply is on the contact and lead', $reply && (int) $reply->lead_id === (int) $lead->id && (int) $reply->person_id === (int) $person->id);
    check('reply is in Inbox, unread', $reply && $reply->folders === ['inbox'] && ! $reply->is_read);
    check('thread now shows in Inbox, unread', in_array('inbox', (array) $sent->fresh()->folders, true) && ! $sent->fresh()->is_read);
    check('scripts, handlers and javascript: links removed', $reply && ! str_contains($reply->reply, '<script') && ! str_contains($reply->reply, 'onclick') && ! str_contains($reply->reply, 'javascript:') && str_contains($reply->reply, 'Tuesday at 11 works.'), $reply?->reply ?? '');

    $fresh = Email::query()->where('message_id', 'fresh-1@mail.example')->first();
    check('new email from a known address goes to the open lead', $fresh && (int) $fresh->lead_id === (int) $lead->id && $fresh->parent_id === null);

    $stranger = Email::query()->where('message_id', 'stranger-1@mail.example')->first();
    check('unknown sender kept without a lead', $stranger && $stranger->lead_id === null && $stranger->person_id === null);

    check('bounce skipped', ! Email::query()->where('message_id', 'bounce-1@mail.example')->exists());
    check('out-of-office skipped', ! Email::query()->where('message_id', 'ooo-1@mail.example')->exists());
    check('email from our own mailbox skipped', ! Email::query()->where('message_id', 'own-1@mail.example')->exists());

    check('event on the lead', DB::table('txn_events')->where('event', 'email_reply_received')->where('lead_id', $lead->id)->count() === 2);
    check('imported emails marked read in the mailbox', collect(Http::recorded())->filter(fn ($pair) => $pair[0]->method() === 'PATCH')->count() === 3);
    check('only reads: nothing sent through Microsoft 365', collect(Http::recorded())->every(fn ($pair) => ! str_contains($pair[0]->url(), 'sendMail')));

    $mainAfter = Mailboxes::find((int) $main->id);
    check('mailbox status saved', $mainAfter->last_checked_at && $mainAfter->received_until && $mainAfter->last_error === null, (string) $mainAfter->last_result);

    // 5. Checking again adds nothing twice.
    $before = Email::query()->count();
    app(EmailReplyImporter::class)->run();
    check('second check adds no duplicates', Email::query()->count() === $before);

    // 6. Missing permission: readable error on that mailbox, the other one goes on.
    fakeGraph(['estimates@txn-check.example' => []], 403);
    $results = collect(app(EmailReplyImporter::class)->run())->keyBy('address');
    check('permission problem reported', ! ($results['estimates@txn-check.example']['ok'] ?? true) && str_contains($results['estimates@txn-check.example']['message'] ?? '', 'Mail.ReadWrite'), json_encode($results));
    check('error stored on the mailbox', str_contains((string) Mailboxes::find((int) $otherId)->last_error, 'Permission missing'));

    // 7. A sending address without a login is listed.
    Email::query()->create([
        'subject' => 'Old', 'source' => 'web', 'user_type' => 'admin', 'name' => 'TXN', 'reply' => '', 'is_read' => 1, 'folders' => ['sent'],
        'from' => 'old-sender@txn-check.example', 'reply_to' => ['customer@reply-check.example'], 'unique_id' => 'sent-2@txn-check.example', 'message_id' => 'sent-2@txn-check.example',
    ]);
    check('sending address without a login is listed', in_array('old-sender@txn-check.example', Mailboxes::missingLogins(), true), json_encode(Mailboxes::missingLogins()));
    check('covered addresses are not listed', ! in_array('main@txn-check.example', Mailboxes::missingLogins(), true));

    // 8. Switch off = the scheduler does not run it.
    config(['automation.email_intake.enabled' => false]);
    check('switch off: not due', ! EmailReplyImporter::due());
} catch (Throwable $exception) {
    check('no unexpected error', false, get_class($exception).': '.$exception->getMessage().' @ '.basename($exception->getFile()).':'.$exception->getLine());
} finally {
    DB::rollBack();
    Http::swap(new Factory());
}

echo $failures === 0 ? 'All checks passed.'.PHP_EOL : "{$failures} check(s) failed.".PHP_EOL;
exit($failures);
