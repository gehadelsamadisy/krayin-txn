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
 */
final class BookingEmail
{
    public const SUBJECTS = [
        'link' => 'Choose a time for your free estimate',
        'reminder' => 'Still looking for a time for your free estimate?',
        'final' => 'Checking in about your estimate',
    ];

    /** Sends one of the emails with $data from data() (subject/intro/closing may have been edited). */
    public function send(string $kind, string $to, array $data): void
    {
        Mail::send(
            ['html' => 'admin::emails.booking-link', 'text' => 'admin::emails.booking-link-text'],
            ['kind' => $kind] + $data,
            fn ($message) => $message->to($to)->subject($data['subject'])
        );
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
            'timezoneLabel' => $timezone === 'America/Chicago' ? 'Central Time' : $timezone,
            'url' => $url,
            'sender' => config('lead_messages'),
        ];

        return $data + $this->texts($kind, $data);
    }

    /** Default subject, intro (before the times) and closing (after the button) for $kind. */
    public function texts(string $kind, array $data): array
    {
        $s = $data['sender'];
        $me = "This is {$s['rep_name']} with {$s['company']}";
        $call = $s['rep_cell'] ? "call or text us at {$s['rep_cell']}" : 'give us a call';
        $offer = $kind !== 'final' && $data['url'] && $data['times'];

        $intro = match (true) {
            $kind === 'final' => "{$me}, checking in one last time about your {$data['service']} request. If you still need help, just {$call} and we'll get your free, no-obligation estimate scheduled.",
            $kind === 'reminder' && $offer => "{$me}, following up on your {$data['service']} request. We'd still be happy to schedule your free, no-obligation estimate. These times are open:",
            $kind === 'reminder' => "{$me}, following up on your {$data['service']} request. We'd still be happy to schedule your free, no-obligation estimate; just {$call} and we'll find a time that works for you.",
            default => "{$me}. Thank you for reaching out about your {$data['service']} project. We have these times open for your free, no-obligation estimate:",
        };

        return [
            'subject' => self::SUBJECTS[$kind].' | '.$s['company'],
            'intro' => $intro,
            'closing' => $offer
                ? "Times are in {$data['timezoneLabel']}. The booking page also asks for any details we still need. The link can be used once; after you book you'll get a confirmation email with options to reschedule or cancel."
                : '',
        ];
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
