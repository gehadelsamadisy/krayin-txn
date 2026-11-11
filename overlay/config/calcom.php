<?php

return [
    'api_key' => env('CALCOM_API_KEY', env('CAL_API_KEY')),
    'allow_customer_email' => (bool) env('CALCOM_ALLOW_CUSTOMER_EMAIL', false),
    'default_timezone' => env('CALCOM_DEFAULT_TIMEZONE', 'America/Chicago'),
    // Comma-separated lists: the test contacts (ids) and the addresses
    // booking links may be emailed to.
    'test_person_ids' => array_values(array_map('intval', array_filter(
        array_map('trim', explode(',', (string) env('CALCOM_TEST_PERSON_ID', ''))),
        'is_numeric'
    ))),
    'test_recipient_emails' => array_values(array_filter(array_map(
        fn ($email) => strtolower(trim($email)),
        explode(',', (string) env('CALCOM_TEST_RECIPIENT_EMAIL', ''))
    ))),
    'single_test_attempt' => (bool) env('CALCOM_SINGLE_TEST_ATTEMPT', true),

    // Booking sync (php artisan calcom:sync-bookings): bookings starting
    // this many days ago or later are copied into Krayin as activities.
    'sync_lookback_days' => (int) env('CALCOM_SYNC_LOOKBACK_DAYS', 30),
    // Krayin user that owns the appointment activity when the person has
    // no sales owner.
    'activity_user_id' => (int) env('CALCOM_ACTIVITY_USER_ID', 1),
];
