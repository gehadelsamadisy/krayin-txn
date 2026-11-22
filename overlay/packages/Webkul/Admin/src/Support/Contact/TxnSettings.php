<?php

namespace Webkul\Admin\Support\Contact;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Settings > TXN Settings: every value staff may change, in one list
 * (fields()). A saved value lives in table txn_settings under its config
 * path ("slot_finder.slot_times") and is put over the config at start-up
 * (apply(), from AppServiceProvider), so all code keeps reading config().
 * The config files and .env give the defaults; a setting equal to its
 * default is not stored. A stored value that fails the checks is ignored
 * (the default applies). Every change goes to txn_settings_log.
 *
 * Connections screen: API keys, tokens and logins. Secrets (type "secret")
 * are stored encrypted with APP_KEY, never sent back to the browser and
 * never written to the log in full ("ends …a1b2"). A secret that can't be
 * decrypted (APP_KEY changed) is skipped, so .env applies.
 *
 * Not here on purpose: APP_KEY, Krayin's own database and Redis (needed to
 * start).
 */
final class TxnSettings
{
    /** Screen slug => [title, one-line description]. Order = Settings page order. */
    public const SCREENS = [
        'company' => ['Company & sender', 'Company name, rep name, phone numbers and email colour used in customer messages.'],
        'services' => ['Services', 'Which service a lead asks for, how it is handled, and the line each service adds to the booking confirmation.'],
        'area' => ['Service area & scheduling', 'Service area, out-of-area message and the times offered to customers.'],
        'follow-ups' => ['Follow-ups', 'When reminders go out and when an unanswered lead is marked Lost.'],
        'emails' => ['Emails', 'The booking-link, reminder and final emails, part by part.'],
        'connections' => ['Connections', 'API keys, tokens and logins for Cal.com, Twilio, email sending, Geocodio and the lead database.'],
        'safety' => ['Safety', 'Whether emails, texts and booking links can reach customers, or only the test addresses.'],
        'automation' => ['Automation', 'Engines that send booking links, texts and staff alerts on their own, and the Cal.com webhook. Everything is off until you turn it on; the Safety guards still apply.'],
    ];

    /** Guards on the Safety screen: turning one off needs this typed confirmation. */
    public const GUARDS = ['txn.guard_email' => 'Email guard', 'txn.guard_sms' => 'SMS guard', 'txn.guard_calcom' => 'Cal.com guard', 'txn.guard_yelp' => 'Yelp reply guard'];

    public const GUARD_OFF_CONFIRMATION = 'SEND TO CUSTOMERS';

    /** Whether any guard is off (customers can be contacted), for the banner. */
    public static function guardsOff(): array
    {
        return array_values(array_filter(self::GUARDS, fn ($label, $key) => ! config($key, true), ARRAY_FILTER_USE_BOTH));
    }

    /**
     * Connections screen: one card per service, with its keys and its Test.
     * "test" is what TxnConnectionTester runs. Email has one card with a
     * mailer switch (mail.default); each mailer brings its own keys and test.
     */
    public const CONNECTION_GROUPS = [
        'calcom' => [
            'title' => 'Cal.com',
            'mark' => 'Ca',
            'purpose' => 'Booking links, booking sync and follow-up checks.',
            'note' => null,
            'test' => 'calcom',
            'keys' => ['calcom.api_key'],
        ],
        'twilio' => [
            'title' => 'Twilio',
            'mark' => 'Tw',
            'purpose' => 'Sends the booking-link texts.',
            'note' => "Krayin sends every text with these settings (Krayin's .env, or the value saved here). It doesn't use TXNHome's settings.",
            'test' => 'twilio',
            'keys' => ['sms.account_sid', 'sms.auth_token', 'sms.messaging_service_sid', 'sms.from_number'],
        ],
        'email' => [
            'title' => 'Email sending',
            'mark' => '@',
            'purpose' => 'Sends booking-link, reminder and final emails, from the sender below.',
            'note' => 'A new sender address must be verified with the mail service, or emails are refused or land in spam.',
            'test' => null,
            'keys' => ['mail.from.address', 'mail.from.name'],
            'mailers' => [
                'smtp' => ['test' => 'smtp', 'hint' => 'Port 587 uses STARTTLS, 465 uses SSL.', 'keys' => ['mail.mailers.smtp.host', 'mail.mailers.smtp.port', 'mail.mailers.smtp.username', 'mail.mailers.smtp.password']],
                'graph' => ['test' => 'graph', 'hint' => 'An Entra app registration with the Mail.Send permission.', 'keys' => ['graph_mail.tenant_id', 'graph_mail.client_id', 'graph_mail.client_secret']],
            ],
        ],
        'geocodio' => [
            'title' => 'Geocodio',
            'mark' => 'Ge',
            'purpose' => 'Finds customer addresses on the map for suggested times and routes.',
            'note' => null,
            'test' => 'geocodio',
            'keys' => ['slot_finder.geocodio_api_key'],
        ],
        'lead_db' => [
            'title' => 'Lead database',
            'mark' => 'DB',
            'purpose' => 'Where Krayin reads new Yelp and Angi leads from TXNHome (read-only login).',
            'note' => 'The webhook token must match KRAYIN_WEBHOOK_TOKEN in TXNHome\'s .env, or new leads stop arriving.',
            'test' => 'lead_db',
            'keys' => ['lead_triage.lead_db.host', 'lead_triage.lead_db.port', 'lead_triage.lead_db.database', 'lead_triage.lead_db.username', 'lead_triage.lead_db.password', 'lead_triage.webhook_token'],
        ],
        'msg_db' => [
            'title' => 'Yelp messages database',
            'mark' => 'Ye',
            'purpose' => 'Where Krayin saves the Yelp chat messages Zapier sends: table yelp_message in the lead database above (same host and name).',
            'note' => 'Its own login, allowed only to read, add and update yelp_message (docs/sql/yelp_message.sql). The switch and the Zap token are on Automation.',
            'test' => 'msg_db',
            'keys' => ['lead_triage.msg_db.username', 'lead_triage.msg_db.password'],
        ],
    ];

    public const MAILERS = ['smtp' => 'Brevo SMTP', 'graph' => 'Microsoft 365'];

    /**
     * Time zones offered on the Service area & scheduling screen => the name
     * customers read ("Times are in Central Time."). Appointment times are
     * stored as wall-clock time in this zone, so changing it does not move
     * existing appointments' clock times.
     */
    public const TIMEZONES = [
        'America/New_York' => 'Eastern Time',
        'America/Chicago' => 'Central Time',
        'America/Denver' => 'Mountain Time',
        'America/Phoenix' => 'Arizona Time',
        'America/Los_Angeles' => 'Pacific Time',
        'America/Anchorage' => 'Alaska Time',
        'Pacific/Honolulu' => 'Hawaii Time',
    ];

    /** "Central Time" for America/Chicago; the zone's own name for a zone not in the list. */
    public static function timezoneLabel(?string $timezone = null): string
    {
        $timezone ??= (string) config('slot_finder.timezone');

        return self::TIMEZONES[$timezone] ?? $timezone;
    }

    /** The service-area centre as staff named it ("Houston, TX"), for "... mi from Houston, TX". */
    public static function areaCentreName(): string
    {
        return trim((string) config('slot_finder.service_area.address')) ?: 'the area centre';
    }

    /** Keys put over the config by the last apply()/save(), so the next apply() can undo them. */
    private static array $appliedKeys = [];

    /** Secrets saved in Krayin that could not be decrypted at the last apply(). */
    private static array $unreadable = [];

    /** Placeholders allowed in the email templates (BookingEmail::vars). */
    public const PLACEHOLDERS = [
        'first_name' => "Customer's first name",
        'service' => 'The service, e.g. "gutter"',
        'rep_name' => 'Rep name',
        'company' => 'Company name',
        'rep_cell' => 'Rep cell',
        'office_phone' => 'Office phone',
        'call' => '"call or text us at <rep cell>" ("give us a call" without a cell)',
        'timezone' => 'e.g. "Central Time"',
        'appointment' => 'The visit that was canceled, e.g. "Tuesday, Mar 2 at 9:00 AM" (cancellation email)',
    ];

    /** Placeholders allowed in the customer text messages (BookingEmail::smsVars). */
    public const SMS_PLACEHOLDERS = [
        'first_name' => "Customer's first name",
        'signature' => 'SMS signature (Company & sender)',
        'company' => 'Company name',
        'rep_name' => 'Rep name',
        'rep_cell' => 'Rep cell',
        'service' => 'The service, e.g. "gutter"',
        'link' => 'The booking link (required in the link and reminder texts)',
        'appointment' => 'The canceled visit (cancellation text)',
    ];

    /** Email templates: group => [title, part => [label, one line?, required?]]. */
    public const EMAIL_PARTS = [
        'shared' => ['Shared by all three emails', [
            'header' => ['Header (top line)', true, false],
            'greeting' => ['Greeting', true, false],
            'button' => ['Button label', true, false],
            'times_heading' => ['Line above the link (plain-text version)', true, false],
            'fallback' => ['"Button doesn\'t work" line', true, false],
            'signature' => ['Signature (a line whose placeholders are empty is left out)', false, false],
        ]],
        'link' => ['Booking link email', [
            'subject' => ['Subject', true, true],
            'intro' => ['Intro (before the times)', false, true],
            'closing' => ['Closing (after the button)', false, false],
        ]],
        'reminder' => ['Reminder email (24h)', [
            'subject' => ['Subject', true, true],
            'intro' => ['Intro when times are still open', false, true],
            'intro_no_times' => ['Intro when no times are left', false, true],
            'closing' => ['Closing (after the button)', false, false],
        ]],
        'final' => ['Final email (72h)', [
            'subject' => ['Subject', true, true],
            'intro' => ['Text', false, true],
        ]],
        'cancelled' => ['Cancellation email (customer canceled)', [
            'subject' => ['Subject', true, true],
            'intro' => ['Text', false, true],
        ]],
    ];

    public const FLOWS = ['standard' => 'Standard (booking link)', 'roof_report' => 'Roof report first'];

    /** Config values as they were before saved settings were applied. */
    private static ?array $defaults = null;

    /**
     * Config path => [screen, type, label, options]. Types: text, longtext,
     * int, color, times, services, lines, area, template, secret, choice,
     * email, host, port, bool, decimal, url, traffic. Text options: pattern + hint (checked when not empty).
     */
    public static function fields(): array
    {
        $fields = [
            'lead_messages.company' => ['company', 'text', 'Company name', ['required' => true, 'max' => 100]],
            'lead_messages.rep_name' => ['company', 'text', 'Rep name', ['max' => 100]],
            'lead_messages.rep_cell' => ['company', 'text', 'Rep cell', ['max' => 40]],
            'lead_messages.office_phone' => ['company', 'text', 'Office phone', ['max' => 40]],
            'lead_messages.accent' => ['company', 'color', 'Email accent colour', []],
            'sms.signature' => ['company', 'text', 'SMS signature', ['required' => true, 'max' => 100]],
            'sms.templates.booking_link' => ['emails', 'template', 'Text message: booking link', ['required' => true, 'max' => 640, 'one_line' => true, 'placeholders' => self::SMS_PLACEHOLDERS, 'must_have' => ['link']]],
            'sms.templates.reminder' => ['emails', 'template', 'Text message: reminder', ['required' => true, 'max' => 640, 'one_line' => true, 'placeholders' => self::SMS_PLACEHOLDERS, 'must_have' => ['link']]],
            'sms.templates.final' => ['emails', 'template', 'Text message: final (72h)', ['required' => true, 'max' => 640, 'one_line' => true, 'placeholders' => self::SMS_PLACEHOLDERS]],
            'sms.templates.cancelled' => ['emails', 'template', 'Text message: cancellation', ['required' => true, 'max' => 640, 'one_line' => true, 'placeholders' => self::SMS_PLACEHOLDERS]],

            'lead_triage.services' => ['services', 'services', 'Services', []],
            'lead_messages.service_lines' => ['services', 'lines', 'Service lines', []],

            'slot_finder.service_area' => ['area', 'area', 'Service area', []],
            'lead_triage.out_of_area_message' => ['area', 'longtext', 'Out-of-area message', ['required' => true, 'max' => 2000]],
            'slot_finder.slot_times' => ['area', 'times', 'Slot times', []],
            'slot_finder.slots_to_offer' => ['area', 'int', 'Times to offer', ['min' => 1, 'max' => 10]],
            'slot_finder.search_days' => ['area', 'int', 'Days ahead to look for times', ['min' => 1, 'max' => 60]],
            'slot_finder.min_notice_minutes' => ['area', 'int', 'Minimum notice (minutes)', ['min' => 0, 'max' => 2880]],
            'slot_finder.visit_minutes' => ['area', 'int', 'Visit length (minutes)', ['min' => 15, 'max' => 480]],
            'slot_finder.max_slots_per_day' => ['area', 'int', 'Most times offered on one day', ['min' => 1, 'max' => 20]],
            'slot_finder.max_extra_drive_minutes' => ['area', 'int', 'Max extra drive for a visit (minutes)', ['min' => 5, 'max' => 240]],
            'slot_finder.timezone' => ['area', 'choice', 'Time zone', ['choices' => self::TIMEZONES]],

            // "Ranking & routes" (advanced) on the same screen.
            'slot_finder.ranking.road_times' => ['area', 'bool', 'Rank with road drive times', []],
            'slot_finder.ranking.max_table_points' => ['area', 'int', 'Most points in one road-times request', ['min' => 2, 'max' => 1000]],
            'slot_finder.near_job_minutes' => ['area', 'int', '"Near a job" within (minutes)', ['min' => 1, 'max' => 240]],
            'slot_finder.route_fit_tolerance_minutes' => ['area', 'int', 'Extra drive counted as equal (minutes)', ['min' => 0, 'max' => 120]],
            'slot_finder.road_distance_factor' => ['area', 'decimal', 'Road distance factor', ['min' => 1, 'max' => 3]],
            'slot_finder.average_speed_mph' => ['area', 'int', 'Average speed (mph)', ['min' => 5, 'max' => 80]],
            'slot_finder.routing.osrm_url' => ['area', 'url', 'Routing server (OSRM) address', []],
            'slot_finder.routing.base_traffic_factor' => ['area', 'decimal', 'Traffic factor outside rush hours', ['min' => 1, 'max' => 3]],
            'slot_finder.routing.traffic_factors' => ['area', 'traffic', 'Rush hours (weekdays)', []],
            'slot_finder.routing.return_to_start' => ['area', 'bool', 'Draw the drive back to the start address', []],
            'slot_finder.routing.cache_days' => ['area', 'int', 'Reuse saved road routes for (days)', ['min' => 1, 'max' => 365]],

            'follow_ups.reminder_after_hours' => ['follow-ups', 'int', 'Reminder after (hours)', ['min' => 1, 'max' => 720]],
            'follow_ups.final_after_hours' => ['follow-ups', 'int', 'Final email after (hours)', ['min' => 1, 'max' => 720]],
            'follow_ups.lost_after_hours' => ['follow-ups', 'int', 'Mark Lost after (hours)', ['min' => 1, 'max' => 2160]],
            'follow_ups.min_hours_before_slot' => ['follow-ups', 'int', 'Only offer times at least this many hours away in the reminder', ['min' => 0, 'max' => 72]],
            'follow_ups.lost_reason' => ['follow-ups', 'text', 'Lost reason', ['required' => true, 'max' => 200]],

            'calcom.api_key' => ['connections', 'secret', 'Cal.com API key', []],

            'sms.account_sid' => ['connections', 'text', 'Twilio Account SID', ['max' => 64, 'pattern' => '/^AC[0-9a-fA-F]{32}$/', 'hint' => 'starts with AC, then 32 letters/digits']],
            'sms.auth_token' => ['connections', 'secret', 'Twilio Auth token', []],
            'sms.messaging_service_sid' => ['connections', 'text', 'Twilio Messaging Service SID', ['max' => 64, 'pattern' => '/^MG[0-9a-fA-F]{32}$/', 'hint' => 'starts with MG, then 32 letters/digits']],
            'sms.from_number' => ['connections', 'text', 'Twilio From number', ['max' => 20, 'pattern' => '/^\+[1-9]\d{6,14}$/', 'hint' => 'international format, e.g. +17135550100']],

            'mail.default' => ['connections', 'choice', 'Mailer', ['choices' => self::MAILERS]],
            'mail.from.address' => ['connections', 'email', 'Sender address', ['required' => true]],
            'mail.from.name' => ['connections', 'text', 'Sender name', ['required' => true, 'max' => 100]],

            'mail.mailers.smtp.host' => ['connections', 'host', 'SMTP host', []],
            'mail.mailers.smtp.port' => ['connections', 'port', 'SMTP port', []],
            'mail.mailers.smtp.username' => ['connections', 'text', 'SMTP login', ['max' => 200]],
            'mail.mailers.smtp.password' => ['connections', 'secret', 'SMTP key', []],

            'graph_mail.tenant_id' => ['connections', 'text', 'Microsoft 365 tenant ID', ['max' => 64, 'pattern' => '/^[0-9a-fA-F-]{36}$/', 'hint' => 'a GUID like 1b2c3d4e-...']],
            'graph_mail.client_id' => ['connections', 'text', 'Microsoft 365 client ID', ['max' => 64, 'pattern' => '/^[0-9a-fA-F-]{36}$/', 'hint' => 'a GUID like 1b2c3d4e-...']],
            'graph_mail.client_secret' => ['connections', 'secret', 'Microsoft 365 client secret', []],

            'slot_finder.geocodio_api_key' => ['connections', 'secret', 'Geocodio API key', []],

            'lead_triage.lead_db.host' => ['connections', 'host', 'Lead database host', []],
            'lead_triage.lead_db.port' => ['connections', 'port', 'Lead database port', []],
            'lead_triage.lead_db.database' => ['connections', 'text', 'Lead database name', ['max' => 64, 'pattern' => '/^[A-Za-z0-9_]+$/', 'hint' => 'letters, digits and _ only']],
            'lead_triage.lead_db.username' => ['connections', 'text', 'Lead database login', ['max' => 64]],
            'lead_triage.lead_db.password' => ['connections', 'secret', 'Lead database password', []],
            'lead_triage.webhook_token' => ['connections', 'secret', 'Webhook token (shared with TXNHome)', ['min' => 16]],
            'lead_triage.msg_db.username' => ['connections', 'text', 'Messages database login', ['max' => 64]],
            'lead_triage.msg_db.password' => ['connections', 'secret', 'Messages database password', []],

            'txn.guard_email' => ['safety', 'guard', 'Email guard', []],
            'mail_guard.allowed_recipients' => ['safety', 'emails', 'Allowed test emails', ['max_items' => 50]],
            'lead_triage.import.store_real_emails' => ['safety', 'bool', 'Store real customer emails on import', []],
            'txn.guard_sms' => ['safety', 'guard', 'SMS guard', []],
            'sms.allowed_recipients' => ['safety', 'phones', 'Allowed test phones', ['max_items' => 50]],
            'txn.guard_calcom' => ['safety', 'guard', 'Cal.com guard', []],
            'calcom.allow_customer_email' => ['safety', 'bool', 'Allow booking links for the test contacts', []],
            'calcom.test_person_ids' => ['safety', 'ids', 'Test contacts (contact IDs)', ['max_items' => 50]],
            'calcom.test_recipient_emails' => ['safety', 'emails', 'Test recipient emails', ['max_items' => 50]],
            'calcom.single_test_attempt' => ['safety', 'bool', 'Only one booking-link email per test', []],
            'txn.guard_yelp' => ['safety', 'guard', 'Yelp reply guard', []],
            'automation.yelp.test_lead_ids' => ['safety', 'ids', 'Allowed test Yelp chats (Yelp lead IDs)', ['max_items' => 50]],

            // Automation (Support\Automation\Automation). All off by default.
            'automation.enabled' => ['automation', 'bool', 'Automation (master switch)', []],
            'automation.email.enabled' => ['automation', 'bool', 'Email engine', []],
            'automation.email.send_link' => ['automation', 'bool', 'Send the booking link when a new lead passes the check', []],
            'automation.email.when_fixed' => ['automation', 'bool', 'Also when a Needs Review lead is fixed', []],
            'automation.email.flows' => ['automation', 'multi', 'Flows that get a link automatically', ['choices' => self::FLOWS]],
            'automation.email.reminder' => ['automation', 'bool', 'Reminder email', []],
            'automation.email.final' => ['automation', 'bool', 'Final email', []],
            'automation.email.lost' => ['automation', 'bool', 'Mark Lost after the last follow-up', []],
            'automation.email.cancel_notice' => ['automation', 'bool', 'Cancellation email', []],
            'automation.sms.enabled' => ['automation', 'bool', 'SMS engine', []],
            'automation.sms.booking_link' => ['automation', 'bool', 'Text the booking link', []],
            'automation.sms.reminder' => ['automation', 'bool', 'Reminder text', []],
            'automation.sms.final' => ['automation', 'bool', 'Final text', []],
            'automation.sms.cancel_notice' => ['automation', 'bool', 'Cancellation text', []],
            'automation.staff.enabled' => ['automation', 'bool', 'Staff notifications', []],
            'automation.staff.recipients' => ['automation', 'emails', 'Staff who get the notifications', ['max_items' => 20]],
            'automation.staff.events' => ['automation', 'multi', 'Notify about', ['choices' => \Webkul\Admin\Support\Automation\Automation::STAFF_EVENTS]],
            'automation.webhook.enabled' => ['automation', 'bool', 'Cal.com webhook', []],
            'automation.webhook.secret' => ['automation', 'secret', 'Cal.com webhook secret', ['min' => 16]],
            'automation.yelp.enabled' => ['automation', 'bool', 'Yelp messages', []],
            'automation.yelp.token' => ['automation', 'secret', 'Yelp messages token (X-TXN-Token in the Zap)', ['min' => 16]],
            'automation.yelp.reply_enabled' => ['automation', 'bool', 'Yelp replies from Krayin', []],
            'automation.yelp.reply_hook' => ['automation', 'secret', 'Reply Zap address (Webhooks by Zapier > Catch Hook URL)', ['min' => 30]],
        ];

        foreach (self::EMAIL_PARTS as $group => [$title, $parts]) {
            foreach ($parts as $part => [$label, $oneLine, $required]) {
                $fields["lead_messages.templates.{$group}.{$part}"] = ['emails', 'template', "{$title}: {$label}", [
                    'one_line' => $oneLine,
                    'required' => $required,
                    'max' => $oneLine ? 300 : 3000,
                ]];
            }
        }

        return $fields;
    }

    /** Keys of one screen. */
    public static function keys(string $screen): array
    {
        return array_keys(array_filter(self::fields(), fn ($field) => $field[0] === $screen));
    }

    /**
     * Puts the saved values over the config. Safe before the table exists
     * (first migration) or when the database is down: then the defaults stay.
     */
    public static function apply(): void
    {
        try {
            if (! Schema::hasTable('txn_settings')) {
                return;
            }

            $fields = self::fields();
            self::$defaults ??= collect($fields)->mapWithKeys(fn ($field, $key) => [$key => config($key)])->all();
            $before = self::connectionSnapshot();

            // Undo what the last apply/save put over the config, so a value
            // reset since then (long-running queue worker) is gone. Values set
            // by someone else (a check script's fake mailer) are left alone.
            foreach (self::$appliedKeys as $key => $_) {
                config([$key => self::$defaults[$key] ?? null]);
            }
            self::$appliedKeys = [];
            self::$unreadable = [];

            foreach (DB::table('txn_settings')->pluck('value', 'key') as $key => $json) {
                if (! isset($fields[$key])) {
                    continue;
                }

                $value = json_decode((string) $json, true);

                if ($fields[$key][1] === 'secret') {
                    $value = self::decrypt($value);

                    if ($value === null) {
                        self::$unreadable[$key] = true; // .env applies

                        continue;
                    }
                }

                // A test mailer (array/log) set at run time is never replaced.
                if ($key === 'mail.default' && in_array(config('mail.default'), ['array', 'log'], true)) {
                    continue;
                }

                [$errors, $clean] = self::check($key, $value);

                if (! $errors) {
                    config([$key => $clean]);
                    self::$appliedKeys[$key] = true;
                } elseif ($fields[$key][1] === 'guard') {
                    // A saved guard that can't be read counts as ON (fail safe).
                    config([$key => true]);
                    self::$appliedKeys[$key] = true;
                }
            }

            self::refreshConnections($before);
        } catch (Throwable) {
            // No database yet: keep the defaults.
        }
    }

    /** Mail and lead-database config, to see whether apply()/save() changed it. */
    private static function connectionSnapshot(): string
    {
        return serialize([config('mail.default'), config('mail.from'), config('mail.mailers.smtp'), config('graph_mail'), config('lead_triage.lead_db'), config('lead_triage.msg_db')]);
    }

    /**
     * After a change: the next email uses the new mailer and login, and the
     * lead database connection is set up again with the new login
     * (AppServiceProvider::register() set it up before the settings were read).
     */
    private static function refreshConnections(string $before): void
    {
        config(['database.connections.lead_db' => config('lead_triage.lead_db')]);
        config(['database.connections.msg_db' => YelpMessageStore::connectionConfig()]);

        if ($before === self::connectionSnapshot()) {
            return;
        }

        DB::purge('lead_db');
        DB::purge('msg_db');

        if (app()->resolved('mail.manager')) {
            app('mail.manager')->forgetMailers();
        }

        // An app token fetched with the old Microsoft 365 login is not reused.
        \Illuminate\Support\Facades\Cache::forget(\App\Mail\GraphTransport::tokenCacheKey(config('graph_mail.tenant_id'), config('graph_mail.client_id')));
    }

    private static function encrypt(string $value): string
    {
        return Crypt::encryptString($value);
    }

    /** Decrypted secret, or null when it can't be read (APP_KEY changed, damaged value). */
    private static function decrypt(mixed $stored): ?string
    {
        if (! is_string($stored) || $stored === '') {
            return null;
        }

        try {
            return Crypt::decryptString($stored);
        } catch (Throwable) {
            return null;
        }
    }

    public static function isSecret(string $key): bool
    {
        return (self::fields()[$key][1] ?? null) === 'secret';
    }

    /** "…a1b2" for a secret: the last 4 characters only (none for short values). */
    public static function mask(?string $value): string
    {
        $value = (string) $value;

        return $value === '' ? '(empty)' : (mb_strlen($value) >= 12 ? 'ends …'.mb_substr($value, -4) : 'set (short)');
    }

    /**
     * Where a secret comes from, for the screen: saved (in Krayin), env, none;
     * plus the masked ending and whether a saved value could not be read.
     *
     * @return array{source: string, ends: string, unreadable: bool}
     */
    public static function secretStatus(string $key): array
    {
        $unreadable = isset(self::$unreadable[$key]);
        $saved = ! $unreadable && self::isSaved($key);
        $value = (string) ($saved ? config($key) : self::default($key));

        return [
            'source' => $saved ? 'saved' : ($value !== '' ? 'env' : 'none'),
            'ends' => self::mask($value),
            'unreadable' => $unreadable,
        ];
    }

    public static function default(string $key): mixed
    {
        return self::$defaults[$key] ?? config($key);
    }

    /** Whether the setting has a saved value (not the default). */
    public static function isSaved(string $key): bool
    {
        return DB::table('txn_settings')->where('key', $key)->exists();
    }

    /**
     * Saves $values (key => value; null = back to the default), all checked
     * first: one bad value and nothing is saved (ValidationException).
     *
     * @return int number of settings whose value changed
     */
    public static function save(string $screen, array $values, ?int $userId, array $extraErrors = []): int
    {
        $fields = self::fields();
        $errors = $extraErrors;
        $clean = [];

        foreach ($values as $key => $value) {
            if (! isset($fields[$key]) || $fields[$key][0] !== $screen) {
                continue;
            }

            if ($value === null) {
                $clean[$key] = null;

                continue;
            }

            [$fieldErrors, $clean[$key]] = self::check($key, $value);
            array_push($errors, ...$fieldErrors);
        }

        if ($errors) {
            throw ValidationException::withMessages(['settings' => array_values(array_unique($errors))]);
        }

        $changes = 0;
        $before = self::connectionSnapshot();

        $changedKeys = [];

        DB::transaction(function () use ($clean, $fields, $screen, $userId, &$changes, &$changedKeys) {
            foreach ($clean as $key => $value) {
                $secret = $fields[$key][1] === 'secret';
                $guard = $fields[$key][1] === 'guard';
                // A test mailer (array/log) set at run time stays in place; the row is still saved.
                $keepTestMailer = $key === 'mail.default' && in_array(config('mail.default'), ['array', 'log'], true);
                $old = $keepTestMailer ? self::default($key) : config($key);
                $new = $value ?? self::default($key);

                if ($new == self::default($key)) {
                    DB::table('txn_settings')->where('key', $key)->delete();
                    unset(self::$appliedKeys[$key], self::$unreadable[$key]);
                } else {
                    DB::table('txn_settings')->updateOrInsert(
                        ['key' => $key],
                        ['value' => json_encode($secret ? self::encrypt((string) $new) : $new), 'updated_by' => $userId, 'updated_at' => now(), 'created_at' => now()]
                    );
                    self::$appliedKeys[$key] = true;
                    unset(self::$unreadable[$key]);
                }

                if ($old == $new) {
                    continue;
                }

                if (! $keepTestMailer) {
                    config([$key => $new]);
                }

                DB::table('txn_settings_log')->insert([
                    'user_id' => $userId,
                    'area' => "settings:{$screen}",
                    'item' => $fields[$key][2],
                    'old_value' => $secret ? self::mask($old) : ($guard ? ($old ? 'on' : 'off') : self::describe($old)),
                    'new_value' => ($secret ? self::mask($new) : ($guard ? ($new ? 'on' : 'off') : self::describe($new))).($value === null ? ' (default)' : ''),
                    'created_at' => now(),
                ]);

                // An engine or the webhook switched on/off: also in the pipeline event log.
                if (str_starts_with($key, 'automation.') && str_ends_with($key, 'enabled')) {
                    PipelineEvents::record('engine_switched', $fields[$key][2].' turned '.($new ? 'ON' : 'off'), ['setting' => $key, 'on' => (bool) $new]);
                }

                $changes++;
                $changedKeys[] = $key;
            }
        });

        self::refreshConnections($before);

        // Follow-up schedule: a step switched off cancels its pending entries,
        // switched on schedules it, new hours re-time the pending ones.
        if ($changes && in_array($screen, ['automation', 'follow-ups'], true)) {
            try {
                app(\Webkul\Admin\Support\Automation\MessageScheduler::class)->syncWithSettings();
            } catch (Throwable $exception) {
                \Illuminate\Support\Facades\Log::error('Follow-up schedule not updated after a settings change.', ['error' => $exception->getMessage()]);
            }
        }

        // A new key or login for a service: clear its alert until it is used again.
        foreach (array_unique(array_filter(array_map([ServiceHealth::class, 'serviceForKey'], $changedKeys))) as $service) {
            ServiceHealth::ok($service);
        }

        return $changes;
    }

    /**
     * Checks and cleans one value.
     *
     * @return array{0: list<string>, 1: mixed} [errors, clean value]
     */
    public static function check(string $key, mixed $value): array
    {
        [, $type, $label, $options] = self::fields()[$key];
        $errors = [];

        switch ($type) {
            case 'text':
            case 'longtext':
            case 'template':
                $value = is_scalar($value) ? trim(str_replace("\r\n", "\n", (string) $value)) : '';

                if (($options['required'] ?? false) && $value === '') {
                    $errors[] = "{$label}: can't be empty.";
                }

                if (mb_strlen($value) > ($options['max'] ?? 255)) {
                    $errors[] = "{$label}: at most {$options['max']} characters.";
                }

                if ($type === 'template') {
                    if (($options['one_line'] ?? false) && str_contains($value, "\n")) {
                        $errors[] = "{$label}: one line only.";
                    }

                    $allowed = $options['placeholders'] ?? self::PLACEHOLDERS;
                    preg_match_all('/\{([^{}]*)\}/', $value, $found);
                    foreach (array_unique($found[1]) as $name) {
                        if (! array_key_exists($name, $allowed)) {
                            $errors[] = "{$label}: unknown placeholder {{$name}}. Allowed: {".implode('} {', array_keys($allowed)).'}.';
                        }
                    }

                    foreach ($options['must_have'] ?? [] as $name) {
                        if ($value !== '' && ! in_array($name, $found[1], true)) {
                            $errors[] = "{$label}: must contain {{$name}}.";
                        }
                    }
                } elseif ($type === 'text' && str_contains($value, "\n")) {
                    $errors[] = "{$label}: one line only.";
                }

                if ($type === 'text' && $value !== '' && isset($options['pattern']) && ! preg_match($options['pattern'], $value)) {
                    $errors[] = "{$label}: should be {$options['hint']}.";
                }
                break;

            case 'secret':
                // Never echoes the value in a message.
                $value = is_scalar($value) ? trim((string) $value) : '';

                if ($value === '' || preg_match('/\s/', $value)) {
                    $errors[] = "{$label}: can't be empty or contain spaces.";
                } elseif (mb_strlen($value) > 1000) {
                    $errors[] = "{$label}: at most 1000 characters.";
                } elseif (mb_strlen($value) < ($options['min'] ?? 1)) {
                    $errors[] = "{$label}: at least {$options['min']} characters.";
                }
                break;

            case 'guard':
            case 'bool':
                $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

                if (! is_bool($value) && ($parsed === null || $value === '' || $value === null)) {
                    $errors[] = "{$label}: must be on or off.";
                    $value = $type === 'guard'; // a guard that can't be read is on
                    break;
                }

                $value = is_bool($value) ? $value : $parsed;
                break;

            case 'emails':
            case 'phones':
            case 'ids':
                // One entry per line (or comma/semicolon); spaces stay inside an entry ("+1 713 555 0102").
                $items = is_array($value) ? $value : preg_split('/[\r\n,;]+/', (string) $value, -1, PREG_SPLIT_NO_EMPTY);
                $items = array_values(array_unique(array_filter(array_map(fn ($item) => trim((string) $item), $items), fn ($item) => $item !== '')));
                // No format check (the user's choice): an entry that isn't a real
                // address, number or ID simply never matches anyone.
                $clean = array_map(fn ($item) => match ($type) {
                    'emails' => mb_strtolower($item),
                    'ids' => ctype_digit($item) ? (int) $item : $item,
                    default => $item,
                }, $items);

                if (count($clean) > ($options['max_items'] ?? 50)) {
                    $errors[] = "{$label}: at most {$options['max_items']} entries.";
                }

                $value = array_values(array_unique($clean));
                break;

            case 'choice':
                $value = is_scalar($value) ? (string) $value : '';

                if (! array_key_exists($value, $options['choices'])) {
                    $errors[] = "{$label}: choose one of ".implode(', ', $options['choices']).'.';
                }
                break;

            case 'email':
                $value = is_scalar($value) ? trim((string) $value) : '';

                if ($value === '' && ! ($options['required'] ?? false)) {
                    break;
                }

                if (! filter_var($value, FILTER_VALIDATE_EMAIL) || mb_strlen($value) > 200) {
                    $errors[] = "{$label}: must be an email address.";
                }
                break;

            case 'host':
                $value = is_scalar($value) ? mb_strtolower(trim((string) $value)) : '';

                if ($value === '') {
                    break; // not used (e.g. Brevo while Microsoft 365 sends)
                }

                if (! filter_var($value, FILTER_VALIDATE_IP) && ! preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*$/', $value)) {
                    $errors[] = "{$label}: must be a host name like smtp-relay.brevo.com or an IP address.";
                }
                break;

            case 'port':
                if ($value === null || $value === '') {
                    $value = null; // not used

                    break;
                }

                if (! is_numeric($value) || (int) $value != $value || $value < 1 || $value > 65535) {
                    $errors[] = "{$label}: must be a port number (1 to 65535).";
                    $value = null;
                    break;
                }

                $value = (int) $value;
                break;

            case 'int':
                if (! is_numeric($value) || (int) $value != $value) {
                    $errors[] = "{$label}: must be a whole number.";
                    $value = null;
                    break;
                }

                $value = (int) $value;

                if ($value < $options['min'] || $value > $options['max']) {
                    $errors[] = "{$label}: must be between {$options['min']} and {$options['max']}.";
                }
                break;

            case 'color':
                $value = is_string($value) ? trim($value) : '';

                if (! preg_match('/^#[0-9A-Fa-f]{6}$/', $value)) {
                    $errors[] = "{$label}: must be a colour like #0B84C6.";
                }
                break;

            case 'times':
                $value = is_array($value) ? $value : preg_split('/[\s,;]+/', (string) $value, -1, PREG_SPLIT_NO_EMPTY);
                $value = array_map(fn ($time) => preg_match('/^\d:\d\d$/', trim((string) $time)) ? '0'.trim((string) $time) : trim((string) $time), $value);

                foreach ($value as $time) {
                    if (! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
                        $errors[] = "{$label}: \"{$time}\" is not a time like 09:00 or 13:30 (24-hour clock).";
                    }
                }

                $value = array_values(array_unique($value));
                sort($value);

                if (! $value || count($value) > 12) {
                    $errors[] = "{$label}: give between 1 and 12 times.";
                }
                break;

            case 'services':
                $rows = [];

                foreach (is_array($value) ? $value : [] as $row) {
                    $match = $row['match'] ?? [];
                    $match = is_array($match) ? $match : preg_split('/\s*,\s*/', (string) $match, -1, PREG_SPLIT_NO_EMPTY);
                    $match = array_values(array_unique(array_filter(array_map(fn ($word) => mb_strtolower(trim((string) $word)), $match))));
                    $category = trim((string) ($row['category'] ?? ''));
                    $flow = (string) ($row['flow'] ?? 'standard');

                    if (! $match && $category === '') {
                        continue; // empty row
                    }

                    if (! $match) {
                        $errors[] = "Service \"{$category}\": give at least one keyword.";
                    }

                    if ($category === '' || mb_strlen($category) > 60) {
                        $errors[] = 'Service with keywords "'.implode(', ', $match).'": the category must be 1 to 60 characters.';
                    }

                    if (! array_key_exists($flow, self::FLOWS)) {
                        $errors[] = "Service \"{$category}\": unknown flow.";
                    }

                    foreach ($match as $word) {
                        if (mb_strlen($word) > 60) {
                            $errors[] = "Service \"{$category}\": keyword \"{$word}\" is too long.";
                        }
                    }

                    $rows[] = ['match' => $match, 'category' => $category, 'flow' => $flow];
                }

                if (! $rows) {
                    $errors[] = 'Services: keep at least one service.';
                }

                $value = $rows;
                break;

            case 'lines':
                $value = collect(is_array($value) ? $value : [])
                    ->mapWithKeys(fn ($line, $category) => [trim((string) $category) => trim((string) $line)])
                    ->filter(fn ($line, $category) => $category !== '' && $line !== '')
                    ->all();

                if (($value['default'] ?? '') === '') {
                    $errors[] = 'Service lines: the line for other services can\'t be empty.';
                }

                foreach ($value as $category => $line) {
                    if (mb_strlen($line) > 500) {
                        $errors[] = "Service line for \"{$category}\": at most 500 characters.";
                    }
                }
                break;

            case 'area':
                $value = is_array($value) ? $value : [];
                $lat = $value['center_lat'] ?? null;
                $lon = $value['center_lon'] ?? null;
                $miles = $value['miles'] ?? null;

                if (! is_numeric($lat) || $lat < -90 || $lat > 90 || ! is_numeric($lon) || $lon < -180 || $lon > 180) {
                    $errors[] = 'Service area: the centre must be a valid latitude and longitude.';
                }

                if (! is_numeric($miles) || $miles < 5 || $miles > 1000) {
                    $errors[] = 'Service area: the radius must be between 5 and 1000 miles.';
                }

                $value = [
                    'center_lat' => round((float) $lat, 6),
                    'center_lon' => round((float) $lon, 6),
                    'miles' => (int) $miles,
                    'address' => mb_substr(trim((string) ($value['address'] ?? '')), 0, 200),
                ];
                break;

            case 'multi':
                // Some of the allowed keys (none is allowed: e.g. no flow sent automatically).
                $value = is_array($value) ? $value : preg_split('/[\s,;]+/', (string) $value, -1, PREG_SPLIT_NO_EMPTY);
                $value = array_values(array_unique(array_map('strval', $value)));

                foreach ($value as $item) {
                    if (! array_key_exists($item, $options['choices'])) {
                        $errors[] = "{$label}: unknown choice \"{$item}\".";
                    }
                }

                $value = array_values(array_intersect(array_keys($options['choices']), $value));
                break;

            case 'decimal':
                if (! is_numeric($value)) {
                    $errors[] = "{$label}: must be a number like 1.3.";
                    $value = null;
                    break;
                }

                $value = round((float) $value, 2);

                if ($value < $options['min'] || $value > $options['max']) {
                    $errors[] = "{$label}: must be between {$options['min']} and {$options['max']}.";
                }
                break;

            case 'url':
                $value = is_scalar($value) ? rtrim(trim((string) $value), '/') : '';

                if (! preg_match('#^https?://#i', $value) || ! filter_var($value, FILTER_VALIDATE_URL) || mb_strlen($value) > 255) {
                    $errors[] = "{$label}: must be a web address starting with http:// or https://.";
                }
                break;

            case 'traffic':
                // Rows [from, to, factor]; empty rows dropped. No rows = the
                // base factor all day.
                $rows = [];

                foreach (is_array($value) ? $value : [] as $row) {
                    $row = array_values((array) $row);
                    [$from, $to, $factor] = array_map(fn ($part) => trim((string) $part), $row + ['', '', '']);

                    if ($from === '' && $to === '' && $factor === '') {
                        continue;
                    }

                    $from = preg_match('/^\d:\d\d$/', $from) ? '0'.$from : $from;
                    $to = preg_match('/^\d:\d\d$/', $to) ? '0'.$to : $to;

                    if (! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $from) || ! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $to) || $from >= $to) {
                        $errors[] = "{$label}: \"{$from} to {$to}\" must be two times like 07:00 and 09:30, the first one earlier.";
                    }

                    if (! is_numeric($factor) || $factor < 1 || $factor > 3) {
                        $errors[] = "{$label}: the factor for {$from} to {$to} must be between 1 and 3.";
                    }

                    $rows[] = [$from, $to, round((float) $factor, 2)];
                }

                usort($rows, fn ($a, $b) => strcmp($a[0], $b[0]));

                for ($i = 1; $i < count($rows); $i++) {
                    if ($rows[$i][0] < $rows[$i - 1][1]) {
                        $errors[] = "{$label}: {$rows[$i - 1][0]} to {$rows[$i - 1][1]} and {$rows[$i][0]} to {$rows[$i][1]} overlap.";
                    }
                }

                if (count($rows) > 8) {
                    $errors[] = "{$label}: at most 8 rush-hour periods.";
                }

                $value = $rows;
                break;
        }

        return [$errors, $value];
    }

    /** A value in a few words, for the change log. */
    public static function describe(mixed $value): string
    {
        $text = match (true) {
            $value === null => '(empty)',
            is_bool($value) => $value ? 'yes' : 'no',
            is_scalar($value) => (string) $value,
            isset($value['center_lat']) => trim(($value['address'] ?? '').' ('.$value['center_lat'].', '.$value['center_lon'].'), '.$value['miles'].' mi'),
            array_is_list($value) && isset($value[0]) && is_array($value[0]) && array_is_list($value[0]) => collect($value)->map(fn ($row) => "{$row[0]} to {$row[1]} x{$row[2]}")->implode('; '),
            $value === [] => '(none)',
            array_is_list($value) && isset($value[0]['category']) =>collect($value)->map(fn ($row) => $row['category'].' ('.implode(', ', (array) $row['match']).')')->implode('; '),
            array_is_list($value) => implode(', ', array_map('strval', $value)),
            default => collect($value)->map(fn ($v, $k) => "{$k}: ".(is_scalar($v) ? $v : json_encode($v)))->implode('; '),
        };

        $text = trim($text) === '' ? '(empty)' : $text;

        return mb_strlen($text) > 500 ? mb_substr($text, 0, 497).'...' : $text;
    }
}
