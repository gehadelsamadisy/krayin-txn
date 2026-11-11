<?php

// Twilio SMS (Support\Contact\SmsSender), the same account as the TXNHome
// lead service. Values come from the Krayin environment; never put them here.
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

    // Signature at the end of customer texts (spec: "<rep name> | TXN Home Services").
    'signature' => env('SMS_SIGNATURE', 'TXN Home Services'),
];
