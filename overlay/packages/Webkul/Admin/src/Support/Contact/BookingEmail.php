<?php

namespace Webkul\Admin\Support\Contact;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Webkul\Contact\Models\Person;

/**
 * The customer wording around a booking link, in one place:
 *   emails (Resources/views/emails/booking-link*.blade.php), by kind:
 *     link     - the booking link itself (staff can edit subject/intro/closing before sending)
 *     reminder - 24h follow-up: still-open times and the link, or "call us"
 *     final    - 72h follow-up: "if you still need help, call or text us"
 *   confirmation (requirements A9): the Cal.com event title and description,
 *     which Cal.com shows on the booking page and in its confirmation email.
 * Customers act through links; they aren't asked to reply.
 *
 * Every word of the emails comes from config('lead_messages.templates')
 * (defaults in overlay/config/lead_messages.php, changed in Settings > TXN
 * Settings > Emails): per email the subject, intro and closing; shared by all
 * three the header, greeting, button label, plain-text link line, "button
 * doesn't work" line and signature. Placeholders: see self::vars(). **text**
 * is bold in the HTML email and plain in the text email.
 */
final class BookingEmail
{
    /** Parts shared by all three emails (config lead_messages.templates.shared). */
    public const SHARED_PARTS = ['header', 'greeting', 'times_heading', 'button', 'fallback', 'signature'];

    /**
     * Sends one of the emails with $data from data() (subject/intro/closing may
     * have been edited). When it actually left (not stopped by the email
     * guard), a copy is kept in Krayin's Mail > Sent, on $person and $lead.
     */
    public function send(string $kind, string $to, array $data, $person = null, $lead = null): void
    {
        if (! ServiceHealth::hasKey('mail')) {
            throw new MailKeyMissingException(ServiceHealth::noKey('mail'));
        }

        $view = ['kind' => $kind] + $data;

        try {
            // Null when a MessageSending listener stopped it (the email guard).
            $sent = Mail::send(
                ['html' => 'admin::emails.booking-link', 'text' => 'admin::emails.booking-link-text'],
                $view,
                fn ($message) => $message->to($to)->subject(self::plain($data['subject']))
            );
        } catch (\Symfony\Component\Mailer\Exception\TransportExceptionInterface $exception) {
            // Login/connection to the mail service failed (ServiceHealth: red bar).
            ServiceHealth::failed('mail', $exception->getMessage());

            throw $exception;
        }

        if ($sent) {
            $this->keepInSent($to, self::plain($data['subject']), $view, $sent, $person, $lead);
        }
    }

    /**
     * A sent customer email as a row of Krayin's `emails` table, folder
     * "sent", like an email sent from Krayin's Mail screen: it shows in
     * Mail > Sent and on the lead. Written with the model directly, without
     * Krayin's email.create events, so no workflow reacts to it. A failure
     * here never undoes or repeats the send.
     */
    private function keepInSent(string $to, string $subject, array $view, $sent, $person, $lead): void
    {
        try {
            // The email's body only: Mail > Sent shows it inside Krayin's page.
            $html = view('admin::emails.booking-link', $view)->render();
            $body = preg_match('~<body[^>]*>(.*)</body>~is', $html, $match) ? $match[1] : $html;
            $id = trim((string) $sent->getMessageId(), '<>') ?: uniqid('txn-', true).'@'.(parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'krayin');

            \Webkul\Email\Models\Email::query()->create([
                'subject' => mb_substr($subject, 0, 250),
                'source' => 'web',
                'user_type' => 'admin',
                'name' => (string) config('mail.from.name'),
                'reply' => $body,
                'is_read' => 1,
                'folders' => ['sent'],
                'from' => config('mail.from.address'),
                'reply_to' => [$to],
                'unique_id' => $id,
                'message_id' => $id,
                'reference_ids' => [$id],
                'person_id' => is_object($person) ? $person->id : null,
                'lead_id' => is_object($lead) ? $lead->id : null,
            ]);
        } catch (\Throwable $exception) {
            \Illuminate\Support\Facades\Log::warning('Customer email sent, but the copy for Mail > Sent could not be saved.', ['to' => $to, 'error' => $exception->getMessage()]);
        }
    }

    /** Placeholder values for the templates. */
    public function vars(array $data): array
    {
        $s = $data['sender'];

        return [
            'first_name' => (string) $data['firstName'],
            'service' => (string) $data['service'],
            'rep_name' => (string) $s['rep_name'],
            'company' => (string) $s['company'],
            'rep_cell' => (string) $s['rep_cell'],
            'office_phone' => (string) $s['office_phone'],
            'call' => $s['rep_cell'] ? "call or text us at {$s['rep_cell']}" : 'give us a call',
            'timezone' => (string) $data['timezoneLabel'],
            'appointment' => (string) ($data['appointment'] ?? ''),
        ];
    }

    /**
     * Fills {placeholders}. With $dropEmptyLines (the signature), a line whose
     * placeholders are all empty is left out, so there is no "Cell:" without
     * a number.
     */
    public static function fill(?string $template, array $vars, bool $dropEmptyLines = false): string
    {
        $template = str_replace("\r\n", "\n", (string) $template);

        if ($dropEmptyLines) {
            $template = implode("\n", array_filter(explode("\n", $template), function (string $line) use ($vars) {
                if (! preg_match_all('/\{([a-z_]+)\}/', $line, $found)) {
                    return true;
                }

                return collect($found[1])->contains(fn ($name) => trim((string) ($vars[$name] ?? '')) !== '');
            }));
        }

        return strtr($template, collect($vars)->mapWithKeys(fn ($value, $name) => ["{{$name}}" => $value])->all());
    }

    /** HTML email: escaped, line breaks kept, **text** in bold. */
    public static function html(?string $text): string
    {
        return preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', nl2br(e(trim((string) $text))));
    }

    /** Text email and subject: the ** marks removed. */
    public static function plain(?string $text): string
    {
        return preg_replace('/\*\*(.+?)\*\*/s', '$1', trim((string) $text));
    }

    /** Placeholder values for the text messages (TxnSettings::SMS_PLACEHOLDERS). */
    public function smsVars(string $firstName, string $service, string $url, string $appointment = ''): array
    {
        $sender = config('lead_messages');

        return [
            'first_name' => $firstName,
            'signature' => (string) config('sms.signature'),
            'company' => (string) ($sender['company'] ?? ''),
            'rep_name' => (string) ($sender['rep_name'] ?? ''),
            'rep_cell' => (string) ($sender['rep_cell'] ?? ''),
            'service' => $service,
            'link' => $url,
            'appointment' => $appointment,
        ];
    }

    /**
     * A text message for $person from template sms.templates.{$template}:
     * booking_link, reminder, final, cancelled. With a $url, the link always
     * reaches the customer whatever the wording; final and cancelled have none.
     */
    public function smsText(Person $person, $lead, string $url, string $template = 'booking_link', string $appointment = ''): string
    {
        $firstName = strtok(trim((string) $person->name), ' ') ?: 'there';
        $service = mb_strtolower($this->serviceCategory($lead) ?? $this->serviceName($lead) ?? 'home');
        $text = trim(self::fill(config("sms.templates.{$template}") ?: config('sms.templates.booking_link'), $this->smsVars($firstName, $service, $url, $appointment)));

        return $url === '' || str_contains($text, $url) ? $text : trim("{$text} {$url}");
    }

    /**
     * Template data: first name, the lead's service, the times ("Monday, Nov 9
     * at 11:00 AM" in $timezone, from "Y-m-d\TH:i" $slots), the link, the
     * sender (config/lead_messages.php) and the default subject, intro and
     * closing for $kind.
     */
    public function data(Person $person, $lead, string $timezone, array $slots, ?string $url, string $kind = 'link'): array
    {
        $data = [
            'kind' => $kind,
            'firstName' => strtok(trim((string) $person->name), ' ') ?: 'there',
            'service' => mb_strtolower($this->serviceCategory($lead) ?? $this->serviceName($lead) ?? 'home'),
            'times' => collect($slots)
                ->sort()
                ->map(fn (string $slot) => CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $slot, $timezone)->format('l, M j \a\t g:i A'))
                ->values()
                ->all(),
            'timezoneLabel' => TxnSettings::timezoneLabel($timezone),
            'url' => $url,
            'sender' => config('lead_messages'),
        ];

        return $data + $this->texts($kind, $data) + ['parts' => $this->sharedParts($data)];
    }

    /**
     * Subject, intro (before the times) and closing (after the button) for
     * $kind, from the templates. A reminder without open times uses
     * intro_no_times; the closing only goes with times and a link.
     */
    public function texts(string $kind, array $data): array
    {
        $templates = (array) config("lead_messages.templates.{$kind}", []);
        $vars = $this->vars($data);
        $offer = $kind !== 'final' && $data['url'] && $data['times'];
        $intro = $kind === 'reminder' && ! $offer ? 'intro_no_times' : 'intro';

        return [
            'subject' => self::fill($templates['subject'] ?? '', $vars),
            'intro' => self::fill($templates[$intro] ?? '', $vars),
            'closing' => $offer ? self::fill($templates['closing'] ?? '', $vars) : '',
        ];
    }

    /** Header, greeting, button label, link lines and signature, filled in. */
    public function sharedParts(array $data): array
    {
        $templates = (array) config('lead_messages.templates.shared', []);
        $vars = $this->vars($data);

        return collect(self::SHARED_PARTS)->mapWithKeys(fn ($part) => [
            $part => self::fill($templates[$part] ?? '', $vars, $part === 'signature'),
        ])->all();
    }

    /**
     * Confirmation wording (A9) for the Cal.com event: title (also the
     * subject of Cal.com's confirmation email) and description, by the lead's
     * service category, with the visit address.
     *
     * @return array{title: string, description: string}
     */
    public function confirmation($lead, ?string $address): array
    {
        $s = config('lead_messages');
        $category = $this->serviceCategory($lead);
        $service = mb_strtolower($category ?? 'home');
        $line = $s['service_lines'][$category] ?? $s['service_lines']['default'];

        return [
            'title' => "Free {$service} estimate | {$s['company']}",
            'description' => implode("\n\n", array_filter([
                "Your free, no-obligation {$service} estimate with {$s['company']}.",
                'Address: '.($address ?: 'the property address you enter when booking'),
                $line,
                'If anything changes, use the reschedule or cancel link in your confirmation email'
                    .($s['rep_cell'] ? ", or call us at {$s['rep_cell']}" : '').'.',
                "{$s['rep_name']}, {$s['company']}",
            ])),
        ];
    }

    /** The lead check's category ("Gutter", "Roof repair"), or null. */
    public function serviceCategory($lead): ?string
    {
        $value = $this->attribute($lead, 'txn_service_category');

        return $value !== null && $value !== 'Unknown' ? $value : null;
    }

    /** The marketplace service name, else the lead title before " - ", or null. */
    private function serviceName($lead): ?string
    {
        if (! $lead) {
            return null;
        }

        return $this->attribute($lead, 'csv_service_name') ?? (trim(explode(' - ', (string) $lead->title)[0]) ?: null);
    }

    private function attribute($lead, string $code): ?string
    {
        if (! $lead) {
            return null;
        }

        $attribute = DB::table('attributes')->where('entity_type', 'leads')->where('code', $code)->first();
        $value = $attribute ? trim((string) $lead->getCustomAttributeValue($attribute)) : '';

        return $value !== '' ? $value : null;
    }
}
