<?php

/*
 * Automation engines (Support\Automation\Automation). DEFAULTS ONLY: staff
 * switch them in Settings > TXN Settings > Automation. Everything is OFF
 * until someone turns it on there. The Safety guards still decide who can
 * receive an email, a text or a booking link.
 *
 * Follow-ups are a schedule (Leads > Scheduled messages): each step is sent
 * at its exact time by the krayin-queue worker (MessageScheduler).
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
        'cancel_notice' => true,      // email the customer when they cancel a booked visit
    ],

    // SMS engine: texts next to the emails above (Twilio, SMS guard).
    // Requirements A7 / A8: the follow-ups go on the same channels as the first message.
    'sms' => [
        'enabled' => false,
        'booking_link' => true,
        'reminder' => true,
        'final' => true,
        'cancel_notice' => true,
    ],

    // Staff notifications: an email to staff when something needs a person.
    'staff' => [
        'enabled' => false,
        'recipients' => [],
        'events' => ['needs_review', 'out_of_area', 'no_times', 'send_failed', 'booking_cancelled', 'marked_lost'],
    ],

    // Cal.com webhook (POST /txn/calcom-webhook): bookings arrive at once.
    'webhook' => [
        'enabled' => false,
        'secret' => env('CALCOM_WEBHOOK_SECRET'),
    ],

    // Yelp messages (POST /txn/yelp-message, YelpMessageController): Zapier
    // posts each Yelp chat message; Krayin saves it in the lead database's
    // yelp_message table (connection msg_db). Own switch, off by default.
    'yelp' => [
        'enabled' => false,
        'token' => env('YELP_MESSAGES_TOKEN'),

        // Replies typed in Krayin (YelpReplySender): posted to the Zapier
        // Catch Hook of the reply Zap, which sends them in Yelp. Own switch,
        // off by default. While the Yelp reply guard (config/txn.php) is on,
        // only the chats in test_lead_ids (Yelp lead IDs) get replies.
        'reply_enabled' => false,
        'reply_hook' => env('YELP_REPLY_HOOK'),
        'test_lead_ids' => array_values(array_filter(array_map('trim', explode(',', (string) env('YELP_TEST_LEAD_IDS', ''))))),
    ],

    // Email replies (Support\Replies\EmailReplyImporter): every few minutes
    // Krayin reads new emails in each mailbox on Settings > TXN Settings >
    // Mailboxes (Microsoft 365, Mail.ReadWrite) and adds them to the lead and
    // Mail > Inbox. Read-only: nothing is sent. Own switch, off by default.
    // The automatic check needs the scheduler (KRAYIN_SCHEDULER=true);
    // "Check now" on the Mailboxes screen works without it.
    'email_intake' => [
        'enabled' => false,
        'minutes' => 2,          // how often the mailboxes are checked
        'lookback_days' => 3,    // first check of a mailbox: emails this many days back
        'mark_read' => true,     // mark imported emails as read in the mailbox
    ],
];
