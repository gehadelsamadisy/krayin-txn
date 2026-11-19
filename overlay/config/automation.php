<?php

/*
 * Automation engines (Support\Automation\Automation). DEFAULTS ONLY: staff
 * switch them in Settings > TXN Settings > Automation. Everything is OFF
 * until someone turns it on there. The Safety guards still decide who can
 * receive an email, a text or a booking link.
 *
 * Time-based steps (follow-ups) need Krayin's scheduler container:
 * KRAYIN_SCHEDULER=true in the server .env, then ./scripts/deploy.sh.
 */
return [
    // Master switch: off = no engine does anything.
    'enabled' => false,

    // Email engine: booking link after the lead check, follow-up emails.
    'email' => [
        'enabled' => false,
        'send_link' => true,          // new lead passes the check -> booking link
        'when_fixed' => false,        // also when a Needs Review lead is fixed
        'flows' => ['standard'],      // lead flows that get a link automatically
        'reminder' => true,           // 24h reminder email
        'final' => true,              // 72h final email
        'lost' => true,               // mark Lost after the last follow-up
    ],

    // SMS engine: texts next to the emails above (Twilio, SMS guard).
    'sms' => [
        'enabled' => false,
        'booking_link' => true,
        'reminder' => false,
    ],

    // Staff notifications: an email to staff when something needs a person.
    'staff' => [
        'enabled' => false,
        'recipients' => [],
        'events' => ['needs_review', 'out_of_area', 'no_times', 'send_failed', 'booking_cancelled'],
    ],

    // Cal.com webhook (POST /txn/calcom-webhook): bookings arrive at once.
    'webhook' => [
        'enabled' => false,
        'secret' => env('CALCOM_WEBHOOK_SECRET'),
    ],
];
