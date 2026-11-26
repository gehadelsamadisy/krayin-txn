<?php

namespace Webkul\Admin\Support\Replies;

use App\Mail\GraphTransport;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Reads one Microsoft 365 mailbox through the Graph API, signed in as its
 * Entra app (client credentials). Needs the application permission
 * Mail.ReadWrite (Mail.Read is enough if marking as read is off), with admin
 * consent, and the app's access policy must include this mailbox.
 *
 * The app token is cached under the same key as GraphTransport's, so the
 * main sender's sending and reading share one token. Never sends anything.
 * Errors are RuntimeExceptions with a readable message and no secret in it.
 */
final class GraphMailbox
{
    private const API = 'https://graph.microsoft.com/v1.0/users/%s';

    private const TOKEN_URL = 'https://login.microsoftonline.com/%s/oauth2/v2.0/token';

    /** Fields asked for per message (internetMessageHeaders only comes when selected). */
    private const FIELDS = 'id,internetMessageId,subject,from,toRecipients,ccRecipients,receivedDateTime,body,uniqueBody,internetMessageHeaders,hasAttachments';

    public function __construct(
        private readonly string $address,
        private readonly string $tenantId,
        private readonly string $clientId,
        private readonly string $clientSecret,
    ) {}

    /**
     * Inbox messages received at or after $since, oldest first, at most $max.
     *
     * @return array<int, array> Graph message objects
     */
    public function messagesSince(CarbonInterface $since, int $max = 200): array
    {
        $url = sprintf(self::API, rawurlencode($this->address)).'/mailFolders/inbox/messages';
        $query = [
            '$filter' => 'receivedDateTime ge '.$since->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
            '$orderby' => 'receivedDateTime asc',
            '$select' => self::FIELDS,
            '$top' => 50,
        ];

        $messages = [];

        while ($url && count($messages) < $max) {
            $response = $this->get($url, $query);
            $messages = array_merge($messages, (array) $response->json('value', []));
            // nextLink already holds the query.
            $url = $response->json('@odata.nextLink');
            $query = [];
        }

        return array_slice($messages, 0, $max);
    }

    /** Marks a message as read in the mailbox. Fails quietly (false) without Mail.ReadWrite. */
    public function markRead(string $messageId): bool
    {
        try {
            $url = sprintf(self::API, rawurlencode($this->address)).'/messages/'.rawurlencode($messageId);

            return $this->send(fn (string $token) => Http::withToken($token)->timeout(20)->patch($url, ['isRead' => true]))->successful();
        } catch (RuntimeException) {
            return false;
        }
    }

    /**
     * Test button: signs in and reads one message (nothing is changed).
     *
     * @return array{ok: bool, message: string}
     */
    public function test(): array
    {
        try {
            $response = $this->get(sprintf(self::API, rawurlencode($this->address)).'/mailFolders/inbox/messages', ['$top' => 1, '$select' => 'id,receivedDateTime']);
        } catch (RuntimeException $exception) {
            return ['ok' => false, 'message' => $exception->getMessage()];
        }

        $latest = $response->json('value.0.receivedDateTime');

        return ['ok' => true, 'message' => 'Krayin can read '.$this->address.'.'.($latest ? ' Newest email: '.\Carbon\Carbon::parse($latest)->timezone(config('app.timezone'))->format('M j, g:i A').'.' : ' The inbox is empty.')];
    }

    private function get(string $url, array $query): Response
    {
        $response = $this->send(fn (string $token) => Http::withToken($token)
            ->withHeaders(['Prefer' => 'outlook.body-content-type="html"'])
            ->acceptJson()
            ->timeout(30)
            ->get($url, $query));

        if (! $response->successful()) {
            throw new RuntimeException($this->explain($response));
        }

        return $response;
    }

    /** Runs a request; on 401 gets a fresh token and tries once more. */
    private function send(callable $request): Response
    {
        try {
            $response = $request($this->token());

            if ($response->status() === 401) {
                Cache::forget($this->cacheKey());
                $response = $request($this->token());
            }

            return $response;
        } catch (ConnectionException) {
            throw new RuntimeException('Krayin could not reach Microsoft 365.');
        }
    }

    private function cacheKey(): string
    {
        return GraphTransport::tokenCacheKey($this->tenantId, $this->clientId);
    }

    /** App token, cached until 5 minutes before it expires (same cache entry as sending). */
    private function token(): string
    {
        if ($token = Cache::get($this->cacheKey())) {
            return $token;
        }

        try {
            $response = Http::asForm()->timeout(20)->post(sprintf(self::TOKEN_URL, rawurlencode($this->tenantId)), [
                'grant_type' => 'client_credentials',
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'scope' => 'https://graph.microsoft.com/.default',
            ]);
        } catch (ConnectionException) {
            throw new RuntimeException('Krayin could not reach Microsoft 365 to sign in.');
        }

        $token = $response->json('access_token');

        if (! $response->successful() || ! is_string($token) || $token === '') {
            $reason = (string) ($response->json('error_description') ?? $response->json('error') ?? 'no token returned');

            throw new RuntimeException('Microsoft 365 refused the app login (HTTP '.$response->status().'): '.strtok($reason, "\r\n").'. Check the tenant ID, client ID and secret (the secret may have expired).');
        }

        Cache::put($this->cacheKey(), $token, max(60, (int) $response->json('expires_in', 3600) - 300));

        return $token;
    }

    /** A readable reason for a failed Graph call. */
    private function explain(Response $response): string
    {
        $code = (string) $response->json('error.code');
        $text = (string) $response->json('error.message');

        return match (true) {
            $response->status() === 403 || $code === 'ErrorAccessDenied' => "Permission missing: the app may not read {$this->address}. In Entra, add the Microsoft Graph application permission Mail.ReadWrite, grant admin consent, and make sure the app's access policy includes this mailbox.",
            $response->status() === 404 || in_array($code, ['ResourceNotFound', 'MailboxNotEnabledForRESTAPI', 'ErrorInvalidUser'], true) => "Mailbox {$this->address} was not found in this Microsoft 365 tenant (or it has no Exchange Online licence).",
            default => 'Microsoft 365 refused the request (HTTP '.$response->status().($code || $text ? ': '.trim("{$code} {$text}") : '').').',
        };
    }
}
