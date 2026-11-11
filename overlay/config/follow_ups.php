<?php

// Follow-ups after a booking link is emailed (Support\Contact\FollowUpService,
// run with `php artisan txn:follow-ups`). Hours count from when the link was
// emailed. Requirements §8: 24h reminder, 72h final, then Lost Lead.
return [
    'reminder_after_hours' => (int) env('FOLLOW_UP_REMINDER_HOURS', 24),
    'final_after_hours' => (int) env('FOLLOW_UP_FINAL_HOURS', 72),
    // No booking this long after the link was emailed (after the final
    // follow-up) → the lead is marked Lost.
    'lost_after_hours' => (int) env('FOLLOW_UP_LOST_HOURS', 96),
    // A still-open time in the original link counts only if it starts at
    // least this long after the reminder is sent.
    'min_hours_before_slot' => (int) env('FOLLOW_UP_MIN_HOURS_BEFORE_SLOT', 3),
    'lost_reason' => 'No reply to follow-ups',
];
