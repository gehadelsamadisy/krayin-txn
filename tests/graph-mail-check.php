<?php

/*
 * Microsoft 365 mail check (MAIL_MAILER=graph, app/Mail/GraphTransport.php).
 *
 * Run inside the Krayin container (copy the file in first):
 *   docker cp tests/graph-mail-check.php krayin:/var/www/laravel-crm/tests/
 *   docker exec -u www-data -e HOME=/tmp krayin php tests/graph-mail-check.php
 *
 * Every HTTP call is faked and fake credentials are used, so nothing reaches
 * Microsoft and no email is sent. Nothing is written to the database; only
 * this check's own token cache entry is created and removed again.
 * Exit code = number of failed checks.
 */

use App\Mail\GraphTransport;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$tenant = 'test-tenant';
$client = 'test-client';
$sender = 'estimates@example.test';

config([
    'graph_mail.tenant_id' => $tenant,
    'graph_mail.client_id' => $client,
    'graph_mail.client_secret' => 'test-secret',
    'mail.from.address' => $sender,
    'mail.from.name' => 'TXN Test',
    'mail_guard.allowed_recipients' => ['customer@example.test', 'office@example.test', 'hidden@example.test'],
]);

$cacheKey = GraphTransport::tokenCacheKey($tenant, $client);
$failures = 0;

function check(string $name, bool $ok, string $detail = ''): void
{
    global $failures;
    echo ($ok ? '  ok    ' : '  FAIL  ').$name.($ok || $detail === '' ? '' : " ({$detail})").PHP_EOL;
    $failures += $ok ? 0 : 1;
}

/** Fresh fake HTTP client: token responses and Graph responses, in order. */
function fakeHttp(array $tokens, array $graph): void
{
    Http::swap(new Factory());
    $tokenSeq = Http::sequence();
    foreach ($tokens as $token) {
        $tokenSeq->push(['access_token' => $token, 'expires_in' => 3600, 'token_type' => 'Bearer'], 200);
    }
    $graphSeq = Http::sequence();
    foreach ($graph as [$status, $body]) {
        $graphSeq->push($body, $status);
    }
    Http::fake([
        'login.microsoftonline.com/*' => $tokenSeq,
        'graph.microsoft.com/*' => $graphSeq,
    ]);
}

/** Recorded requests whose URL contains $host. */
function sent(string $host): array
{
    return array_values(array_map(
        fn ($pair) => $pair[0],
        array_filter(Http::recorded(), fn ($pair) => str_contains($pair[0]->url(), $host))
    ));
}

function sendTest(string $subject = 'Graph check'): ?Throwable
{
    try {
        Mail::mailer('graph')->raw('Hello from the Graph check.', fn ($m) => $m
            ->to('customer@example.test')
            ->cc('office@example.test')
            ->bcc('hidden@example.test')
            ->subject($subject));
    } catch (Throwable $e) {
        return $e;
    }

    return null;
}

echo 'Graph mail check'.PHP_EOL;

// 1. A normal send: token request, then the MIME message to the sender's mailbox.
Cache::forget($cacheKey);
fakeHttp(['token-1'], [[202, '']]);
$error = sendTest();
$tokenCalls = sent('login.microsoftonline.com');
$graphCalls = sent('graph.microsoft.com');
check('send succeeds', $error === null, $error?->getMessage() ?? '');
check('one token request', count($tokenCalls) === 1, count($tokenCalls).' calls');
if ($tokenCalls) {
    /** @var Request $t */
    $t = $tokenCalls[0];
    check('token URL uses the tenant', str_contains($t->url(), "/{$tenant}/oauth2/v2.0/token"), $t->url());
    check('token request is client credentials',
        ($t['grant_type'] ?? null) === 'client_credentials'
        && ($t['client_id'] ?? null) === $client
        && ($t['scope'] ?? null) === 'https://graph.microsoft.com/.default');
}
check('one Graph request', count($graphCalls) === 1, count($graphCalls).' calls');
if ($graphCalls) {
    /** @var Request $g */
    $g = $graphCalls[0];
    $mime = base64_decode($g->body(), true) ?: '';
    check('Graph URL is the sender mailbox', $g->url() === 'https://graph.microsoft.com/v1.0/users/'.rawurlencode($sender).'/sendMail', $g->url());
    check('bearer token sent', ($g->header('Authorization')[0] ?? '') === 'Bearer token-1');
    check('body is base64 MIME', $mime !== '' && str_contains($mime, 'Subject: Graph check'));
    check('To and Cc kept', str_contains($mime, 'customer@example.test') && preg_match('/^Cc: .*office@example\.test/mi', $mime) === 1);
    check('Bcc recipient added back', preg_match('/^Bcc: hidden@example\.test\r?$/mi', $mime) === 1);
    check('From is the sender', preg_match('/^From: .*'.preg_quote($sender, '/').'/mi', $mime) === 1);
}

// 2. Second mail reuses the cached token.
fakeHttp([], [[202, '']]);
$error = sendTest('Second');
check('second send succeeds', $error === null, $error?->getMessage() ?? '');
check('cached token reused (no token request)', count(sent('login.microsoftonline.com')) === 0);

// 3. Graph says 401 (token revoked): new token, one retry.
fakeHttp(['token-2'], [[401, ['error' => ['code' => 'InvalidAuthenticationToken', 'message' => 'expired']]], [202, '']]);
$error = sendTest('Retry');
$graphCalls = sent('graph.microsoft.com');
check('401 then retry succeeds', $error === null, $error?->getMessage() ?? '');
check('new token fetched after 401', count(sent('login.microsoftonline.com')) === 1);
check('retry uses the new token', count($graphCalls) === 2 && ($graphCalls[1]->header('Authorization')[0] ?? '') === 'Bearer token-2');

// 4. Graph refuses (e.g. app not allowed for this mailbox): clear error, no retry.
fakeHttp([], [[403, ['error' => ['code' => 'ErrorAccessDenied', 'message' => 'Access is denied.']]]]);
$error = sendTest('Denied');
check('403 throws a mail transport error', $error instanceof TransportExceptionInterface, $error ? get_class($error) : 'no error');
check('error shows Graph\'s reason', $error && str_contains($error->getMessage(), 'ErrorAccessDenied'), $error?->getMessage() ?? '');
check('no retry on 403', count(sent('graph.microsoft.com')) === 1);

// 5. Wrong secret: sign-in fails with a readable message, nothing posted to Graph.
Cache::forget($cacheKey);
Http::swap(new Factory());
Http::fake([
    'login.microsoftonline.com/*' => Http::response(['error' => 'invalid_client', 'error_description' => "AADSTS7000215: Invalid client secret provided.\r\nTrace ID: x"], 401),
    'graph.microsoft.com/*' => Http::response('', 202),
]);
$error = sendTest('Bad secret');
check('bad secret throws a mail transport error', $error instanceof TransportExceptionInterface, $error ? get_class($error) : 'no error');
check('error names the cause', $error && str_contains($error->getMessage(), 'Invalid client secret'), $error?->getMessage() ?? '');
check('nothing posted to Graph', count(sent('graph.microsoft.com')) === 0);

// 6. The test guard still blocks recipients that are not allowed.
fakeHttp(['token-3'], [[202, '']]);
Mail::mailer('graph')->raw('Should not leave.', fn ($m) => $m->to('stranger@example.test')->subject('Guard'));
check('guard blocks a non-allowed recipient (no Graph call)', count(sent('graph.microsoft.com')) === 0);

Cache::forget($cacheKey);

echo $failures === 0 ? 'All checks passed.'.PHP_EOL : "{$failures} check(s) failed.".PHP_EOL;
exit($failures);
