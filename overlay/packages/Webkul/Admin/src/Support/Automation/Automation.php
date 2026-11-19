<?php

namespace Webkul\Admin\Support\Automation;

/**
 * The automation engines' switches (config/automation.php, changed in
 * Settings > TXN Settings > Automation). Everything ships OFF.
 *
 *   Email engine     booking link after the lead check, follow-up emails (EmailEngine)
 *   SMS engine       texts next to those emails (used by EmailEngine and the follow-ups)
 *   Staff notifier   an email to staff when something needs a person (StaffNotifier)
 *   Cal.com webhook  bookings arrive at once (CalcomWebhookController); own switch
 *
 * An engine runs only when the master switch AND its own switch are on.
 * The Safety guards still decide who may receive anything.
 */
final class Automation
{
    public const ENGINES = [
        'email' => ['Email engine', 'Sends the booking link when a new lead passes the check, then the reminder and final emails, and marks unanswered leads Lost.'],
        'sms' => ['SMS engine', 'Texts the customer next to those emails (Twilio). Wording on Settings > TXN Settings > Emails.'],
        'staff' => ['Staff notifications', 'Emails staff when something needs a person.'],
    ];

    public const STAFF_EVENTS = [
        'needs_review' => 'A new lead needs review',
        'out_of_area' => 'A lead is outside the service area',
        'no_times' => 'No times could be found for a lead',
        'send_failed' => 'An automatic send failed or was blocked',
        'booking_cancelled' => 'A customer cancelled a booking',
    ];

    public static function masterOn(): bool
    {
        return (bool) config('automation.enabled', false);
    }

    /** Engine on: master switch and its own switch. */
    public static function on(string $engine): bool
    {
        return self::masterOn() && (bool) config("automation.{$engine}.enabled", false);
    }

    /** A rule of an engine ("email.reminder"), only true while that engine is on. */
    public static function rule(string $key): bool
    {
        return self::on(strtok($key, '.')) && (bool) config("automation.{$key}", false);
    }

    /** The Cal.com webhook has its own switch (bookings in are not a message out). */
    public static function webhookOn(): bool
    {
        return (bool) config('automation.webhook.enabled', false);
    }

    /** What the clock may do with the follow-ups (FollowUpService::run). */
    public static function followUpGates(): array
    {
        return [
            'reminder' => self::rule('email.reminder'),
            'final' => self::rule('email.final'),
            'lost' => self::rule('email.lost'),
            'sms_reminder' => self::rule('email.reminder') && self::rule('sms.reminder'),
        ];
    }

    public static function anyFollowUps(): bool
    {
        $gates = self::followUpGates();

        return $gates['reminder'] || $gates['final'] || $gates['lost'];
    }
}
