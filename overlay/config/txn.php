<?php

/*
 * Safety guards. Each can be switched in Settings > TXN Settings > Safety;
 * these are the defaults when nothing is saved there.
 *
 * guard_email:  on = emails go only to MAIL_ALLOWED_RECIPIENTS (config/mail_guard.php),
 *               and imported leads keep placeholder emails (unless store_real_emails).
 * guard_sms:    on = texts go only to SMS_ALLOWED_RECIPIENTS (config/sms.php).
 * guard_calcom: on = booking links only for the CALCOM_TEST_* contacts (config/calcom.php).
 *
 * Each defaults to TXN_TEST_MODE (default true), so one switch still sets all
 * three; TXN_GUARD_EMAIL / _SMS / _CALCOM override one. Code reading a guard
 * uses true when the value is missing (fail safe).
 * After changing the environment: docker compose up -d --force-recreate krayin krayin-queue
 */

$testMode = (bool) env('TXN_TEST_MODE', true);

return [
    'test_mode' => $testMode,
    'guard_email' => (bool) env('TXN_GUARD_EMAIL', $testMode),
    'guard_sms' => (bool) env('TXN_GUARD_SMS', $testMode),
    'guard_calcom' => (bool) env('TXN_GUARD_CALCOM', $testMode),
];
