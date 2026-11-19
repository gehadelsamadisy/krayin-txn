<?php

// Sender identity and wording of customer messages (requirements §3: "the
// representative name is a setting"). These are the DEFAULTS: staff change
// them in Settings > TXN Settings (Company & sender, Services, Emails), saved
// in the database (Support\Contact\TxnSettings), never in git. The rep's name
// and phone numbers default to the Krayin environment (.env).
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

    // The booking-link emails (Support\Contact\BookingEmail), part by part.
    // Placeholders: {first_name} {service} {rep_name} {company} {rep_cell}
    // {office_phone} {call} {timezone}. **text** = bold. An empty part is
    // left out. In the signature, a line whose placeholders are all empty is
    // left out (no "Cell:" without a cell number).
    'templates' => [
        // Shared by all three emails.
        'shared' => [
            'header' => '{company}',
            'greeting' => 'Hi {first_name},',
            'times_heading' => 'Choose your time here:',
            'button' => 'Choose your time',
            'fallback' => "If the button doesn't work, copy this link into your browser:",
            'signature' => "**{rep_name}**\n{company}\nCell: {rep_cell}\nOffice: {office_phone}",
        ],
        // The booking link (staff can still edit subject, intro and closing before sending).
        'link' => [
            'subject' => 'Choose a time for your free estimate | {company}',
            'intro' => 'This is {rep_name} with {company}. Thank you for reaching out about your {service} project. We have these times open for your **free, no-obligation estimate**:',
            'closing' => "Times are in {timezone}. The booking page also asks for any details we still need. The link can be used once; after you book you'll get a confirmation email with options to reschedule or cancel.",
        ],
        // 24h follow-up: with the still-open times, or "call us" when none are left.
        'reminder' => [
            'subject' => 'Still looking for a time for your free estimate? | {company}',
            'intro' => "This is {rep_name} with {company}, following up on your {service} request. We'd still be happy to schedule your **free, no-obligation estimate**. These times are open:",
            'intro_no_times' => "This is {rep_name} with {company}, following up on your {service} request. We'd still be happy to schedule your **free, no-obligation estimate**; just {call} and we'll find a time that works for you.",
            'closing' => "Times are in {timezone}. The booking page also asks for any details we still need. The link can be used once; after you book you'll get a confirmation email with options to reschedule or cancel.",
        ],
        // 72h follow-up: no times, "call or text us".
        'final' => [
            'subject' => 'Checking in about your estimate | {company}',
            'intro' => "This is {rep_name} with {company}, checking in one last time about your {service} request. If you still need help, just {call} and we'll get your **free, no-obligation estimate** scheduled.",
        ],
        // The customer cancelled a booked visit (Email engine, "Cancellation message"). No follow-ups after it.
        'cancelled' => [
            'subject' => 'Your estimate appointment was canceled | {company}',
            'intro' => "Your appointment on {appointment} has been canceled. If you'd like to reschedule your **free, no-obligation estimate**, just {call} and we'll find a time that works for you.",
        ],
    ],
];
