<?php

// Twilio SMS (Support\Contact\SmsSender): Krayin's own settings, from the Krayin
// environment (TWILIO_*) or Settings > TXN Settings > Connections. Never put values here.
return [
    'account_sid' => env('TWILIO_ACCOUNT_SID'),
    'auth_token' => env('TWILIO_AUTH_TOKEN'),
    // Messaging Service (preferred) or a sending number.
    'messaging_service_sid' => env('TWILIO_MESSAGING_SERVICE_SID'),
    'from_number' => env('TWILIO_FROM_NUMBER'),

    // While testing, a text leaves only if the number is listed here
    // (comma-separated, any format). Empty = nothing is ever sent.
    'allowed_recipients' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('SMS_ALLOWED_RECIPIENTS', ''))
    ))),

    // Who the customer texts are from ("this is <signature>"). Default; staff
    // change it in Settings > TXN Settings > Company & sender.
    'signature' => env('SMS_SIGNATURE', 'TXN Home Services'),

    // Customer texts. Defaults; staff change them in Settings > TXN Settings >
    // Emails (Text message). Placeholders: TxnSettings::SMS_PLACEHOLDERS.
    'templates' => [
        'booking_link' => 'Hi {first_name}, this is {signature}. Pick a time for your free, no-obligation estimate here: {link}',
        // Sent with the 24h reminder when the SMS engine's "Reminder text" is on.
        'reminder' => 'Hi {first_name}, {signature} here. Your free estimate times are still open: {link}',
        // With the 72h final email (SMS engine, "Final text").
        'final' => 'Hi {first_name}, {signature} checking in one last time about your {service} request. If you still need help, call or text us at {rep_cell}.',
        // When the customer cancels a booked visit (SMS engine, "Cancellation text").
        'cancelled' => 'Hi {first_name}, your appointment with {signature} on {appointment} has been canceled. To reschedule your free estimate, call or text us at {rep_cell}.',
    ],
];
