<?php

/*
 * Who Krayin may email while testing (app/Providers/AppServiceProvider.php).
 * MAIL_ALLOWED_RECIPIENTS: comma-separated addresses. An email is sent only
 * if every recipient is listed; empty = no email is ever sent.
 * After changing the environment value: php artisan config:clear
 */

return [
    'allowed_recipients' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('MAIL_ALLOWED_RECIPIENTS', ''))
    ))),
];
