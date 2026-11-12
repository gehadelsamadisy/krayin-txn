<?php

/*
 * TXN_TEST_MODE (default true). While on, Krayin only contacts the test
 * allowlists: emails to MAIL_ALLOWED_RECIPIENTS, texts to
 * SMS_ALLOWED_RECIPIENTS, booking links only for the CALCOM_TEST_* contact,
 * and imported leads keep placeholder emails.
 * TXN_TEST_MODE=false lifts all of these: real contacts are emailed/texted.
 * After changing it: docker compose up -d --force-recreate krayin krayin-queue
 */

return [
    'test_mode' => (bool) env('TXN_TEST_MODE', true),
];
