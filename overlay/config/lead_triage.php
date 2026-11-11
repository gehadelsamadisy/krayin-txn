<?php

/*
|--------------------------------------------------------------------------
| Lead check (Support\Contact\LeadTriageService)
|--------------------------------------------------------------------------
| Normalizes the contact, classifies the requested service, checks the
| location and decides the lead's next action. Copy to
| /var/www/laravel-crm/config/lead_triage.php, then php artisan config:clear.
*/

return [

    // ------------------------------------------------------------------
    // Services: checked in order, first match wins. "match" is one or more
    // phrases looked for (case-insensitive) in the lead's service name.
    // flow: standard  = offer times and send the booking link
    //       roof_report = roof replacement: the roof report comes first
    //                     (that flow isn't built yet, so it goes to review)
    // An unmatched service goes to review.
    // ------------------------------------------------------------------

    'services' => [
        // Spec: any service text containing "gutter" is the standard gutter flow.
        ['match' => 'gutter', 'category' => 'Gutter', 'flow' => 'standard'],
        ['match' => ['roof replacement', 'replace roof', 'reroof', 're-roof'], 'category' => 'Roof replacement', 'flow' => 'roof_report'],
        // Documented as standard; open question for Moe whether it follows the roof-report flow.
        ['match' => ['new roof'], 'category' => 'New roof installation', 'flow' => 'standard'],
        ['match' => ['roof repair', 'roof inspection', 'roof leak', 'leaking roof', 'leak', 'roofing', 'roof'], 'category' => 'Roof repair', 'flow' => 'standard'],
        ['match' => ['siding'], 'category' => 'Siding', 'flow' => 'standard'],
        ['match' => ['fascia', 'soffit'], 'category' => 'Fascia', 'flow' => 'standard'],
    ],

    'flow_labels' => [
        'standard' => 'Standard (booking link)',
        'roof_report' => 'Roof report first',
        'review' => 'Review',
    ],

    // ------------------------------------------------------------------
    // Service area: miles from the centre (straight line). Moe: Greater
    // Houston and Southeast Texas, about 120 miles; leads farther away are
    // never rejected, they go to review for the out-of-area message.
    // Uses slot_finder.service_area for the centre and radius.
    // ------------------------------------------------------------------

    'out_of_area_message' => 'Thank you for contacting TXN Home Services! We do travel outside our regular service area for larger projects. Please provide your property address, phone number, and type of work needed so we can determine availability.',

    // Values that mean "nothing here".
    'empty_values' => ['n/a', 'na', 'none', 'null', 'nil', '-', '--', 'unknown', 'not provided', '0'],

    // Tag put on leads that need a person to look at them.
    'review_tag' => ['name' => 'Needs review', 'color' => '#DC2626'],

    // ------------------------------------------------------------------
    // "Import leads (CSV)" on the Leads list (LeadCsvImportService).
    // IDs are Krayin's: source 2 = Web, type 1 = New Business, owner 1 =
    // the admin (same as the old Python importer used).
    // store_real_emails: off while testing, so a new contact gets
    // contact{id}@test.invalid instead of the customer's real address
    // (addresses in MAIL_ALLOWED_RECIPIENTS are always kept).
    // ------------------------------------------------------------------

    'import' => [
        'source_id' => (int) env('LEAD_IMPORT_SOURCE_ID', 2),
        'type_id' => (int) env('LEAD_IMPORT_TYPE_ID', 1),
        'owner_id' => (int) env('LEAD_IMPORT_OWNER_ID', 1),
        'store_real_emails' => (bool) env('LEAD_IMPORT_STORE_REAL_EMAILS', false),
        // Seconds an import (or one "Check new leads" click) spends on lead
        // checks before stopping; the server ends requests after 300 s.
        // Unchecked leads stay in Raw Lead for the next "Check new leads".
        'check_seconds' => (int) env('LEAD_IMPORT_CHECK_SECONDS', 200),
    ],

];
