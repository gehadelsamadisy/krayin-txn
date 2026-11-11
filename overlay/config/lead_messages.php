<?php

// Sender identity for customer messages (requirements §3: "the
// representative name is a setting"). The rep's name and the phone numbers
// are personal, so they come only from the Krayin environment (.env).
return [
    'company' => env('TXN_COMPANY_NAME', 'TXN Home Services'),
    'rep_name' => env('TXN_REP_NAME', ''),
    'rep_cell' => env('TXN_REP_CELL', ''),
    'office_phone' => env('TXN_OFFICE_PHONE', ''),
    // Button and accent colour in emails.
    'accent' => env('TXN_EMAIL_ACCENT', '#0B84C6'),

    // Confirmation (requirements A9): the line added for each service
    // category (the lead check's "Service Category"). Roof and siding are the
    // requirements' wording; the others are placeholders until Moe sends his.
    'service_lines' => [
        'Gutter' => "We'll look at your gutters and downspouts and walk you through the options.",
        'Roof replacement' => 'Please make sure someone is home so our team can access the roof.',
        'New roof installation' => 'Please make sure someone is home so our team can access the roof.',
        'Roof repair' => 'Please make sure someone is home so our team can access the roof.',
        'Siding' => "We'll take measurements so we can put your quote together.",
        'Fascia' => "We'll check the fascia and trim and go over what's needed.",
        'default' => "We'll take a look and go over your options with you.",
    ],
];
