<?php

namespace App\Mail;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Sends mail through Microsoft 365 (Exchange Online) with the Graph API
 * (MAIL_MAILER=graph, config/graph_mail.php).
 *
 * Signs in as an Entra app (client credentials, Mail.Send application
 * permission) and posts the complete MIME message to
 * /users/{sender}/sendMail, so threading headers, attachments and both
 * text/HTML parts are kept and a copy lands in the sender's Sent Items.
 * The sender is the message's envelope sender (MAIL_FROM_ADDRESS); the app
 * must be allowed to send as that mailbox.
 */
final class GraphTransport extends AbstractTransport
{
    private const GRAPH_URL = 'https://graph.microsoft.com/v1.0/users/%s/sendMail';

    private const TOKEN_URL = 'https://login.microsoftonline.com/%s/oauth2/v2.0/token';

    public function __construct(
        private readonly ?string $tenantId,
        private readonly ?string $clientId,
        private readonly ?string $clientSecret,
    ) {
        parent::__construct();
    }

    /** Cache key of the app token for this tenant and app. */
    public static function tokenCacheKey(?string $tenantId, ?string $clientId): string
    {
        return 'graph-mail.token.'.sha1($tenantId.'|'.$clientId);
    }

    protected function doSend(SentMessage $message): void
    {
        if (! filled($this->tenantId) || ! filled($this->clientId) || ! filled($this->clientSecret)) {
            throw new TransportException('Microsoft 365 mail is not configured: set GRAPH_TENANT_ID, GRAPH_CLIENT_ID and GRAPH_CLIENT_SECRET in the Krayin environment.');
        }

        $envelope = $message->getEnvelope();
        $url = sprintf(self::GRAPH_URL, rawurlencode($envelope->getSender()->getAddress()));
        $body = base64_encode($this->mime($message));

        $response = $this->post($url, $body, $this->token());

        // Token revoked or expired early: get a new one and try once more.
        if ($response->status() === 401) {
            Cache::forget(self::tokenCacheKey($this->tenantId, $this->clientId));
            $response = $this->post($url, $body, $this->token());
        }

        if ($response->status() !== 202 && ! $response->successful()) {
            throw new TransportException(sprintf(
                'Microsoft 365 did not send the email (HTTP %d%s).',
                $response->status(),
                $this->graphError($response)
            ));
        }
    }

    public function __toString(): string
    {
        return 'graph://'.$this->tenantId;
    }

    /**
     * The MIME message. Symfony leaves the Bcc header out of it, so envelope
     * recipients that are not in To/Cc are added back as a Bcc header; Graph
     * delivers to them and removes the header.
     */
    private function mime(SentMessage $message): string
    {
        $mime = $message->toString();
        $original = $message->getOriginalMessage();

        if (! $original instanceof Email) {
            return $mime;
        }

        $visible = array_map(
            fn (Address $address) => strtolower($address->getAddress()),
            [...$original->getTo(), ...$original->getCc()]
        );
        $hidden = array_values(array_filter(
            array_map(fn (Address $address) => $address->getAddress(), $message->getEnvelope()->getRecipients()),
            fn (string $address) => ! in_array(strtolower($address), $visible, true)
        ));

        return $hidden ? 'Bcc: '.implode(', ', $hidden)."\r\n".$mime : $mime;
    }

    private function post(string $url, string $body, string $token): Response
    {
        try {
            return Http::withToken($token)
                ->timeout(30)
                ->withBody($body, 'text/plain')
                ->post($url);
        } catch (ConnectionException) {
            throw new TransportException('Krayin could not reach Microsoft 365 to send the email.');
        }
    }

    /** App token, cached until 5 minutes before it expires. */
    private function token(): string
    {
        $key = self::tokenCacheKey($this->tenantId, $this->clientId);

        if ($token = Cache::get($key)) {
            return $token;
        }

        try {
            $response = Http::asForm()
                ->timeout(20)
                ->post(sprintf(self::TOKEN_URL, rawurlencode($this->tenantId)), [
                    'grant_type' => 'client_credentials',
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret,
                    'scope' => 'https://graph.microsoft.com/.default',
                ]);
        } catch (ConnectionException) {
            throw new TransportException('Krayin could not reach Microsoft 365 to sign in for sending email.');
        }

        $token = $response->json('access_token');

        if (! $response->successful() || ! is_string($token) || $token === '') {
            $reason = $response->json('error_description') ?? $response->json('error') ?? 'no token returned';
            $reason = strtok((string) $reason, "\r\n");

            throw new TransportException("Microsoft 365 sign-in for sending email failed (HTTP {$response->status()}): {$reason}. Check GRAPH_TENANT_ID, GRAPH_CLIENT_ID and GRAPH_CLIENT_SECRET (the secret may have expired).");
        }

        Cache::put($key, $token, max(60, (int) $response->json('expires_in', 3600) - 300));

        return $token;
    }

    private function graphError(Response $response): string
    {
        $code = $response->json('error.code');
        $text = $response->json('error.message');

        return $code || $text ? ': '.trim("{$code} {$text}") : '';
    }
}
