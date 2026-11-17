<?php

namespace Webkul\Admin\Support\Contact;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Settings > TXN Settings: every value staff may change, in one list
 * (fields()). A saved value lives in table txn_settings under its config
 * path ("slot_finder.slot_times") and is put over the config at start-up
 * (apply(), from AppServiceProvider), so all code keeps reading config().
 * The config files and .env give the defaults; a setting equal to its
 * default is not stored. A stored value that fails the checks is ignored
 * (the default applies). Every change goes to txn_settings_log.
 *
 * Not here on purpose: keys and passwords, test mode and allowed recipients,
 * lead database and webhook token, time zone, technical route tuning.
 */
final class TxnSettings
{
    /** Screen slug => [title, one-line description]. Order = Settings page order. */
    public const SCREENS = [
        'company' => ['Company & sender', 'Company name, rep name, phone numbers and email colour used in customer messages.'],
        'services' => ['Services', 'Which service a lead asks for, how it is handled, and the line each service adds to the booking confirmation.'],
        'area' => ['Service area & scheduling', 'Service area, out-of-area message and the times offered to customers.'],
        'follow-ups' => ['Follow-ups', 'When reminders go out and when an unanswered lead is marked Lost.'],
        'emails' => ['Emails', 'The booking-link, reminder and final emails, part by part.'],
    ];

    /** Placeholders allowed in the email templates (BookingEmail::vars). */
    public const PLACEHOLDERS = [
        'first_name' => "Customer's first name",
        'service' => 'The service, e.g. "gutter"',
        'rep_name' => 'Rep name',
        'company' => 'Company name',
        'rep_cell' => 'Rep cell',
        'office_phone' => 'Office phone',
        'call' => '"call or text us at <rep cell>" ("give us a call" without a cell)',
        'timezone' => 'e.g. "Central Time"',
    ];

    /** Email templates: group => [title, part => [label, one line?, required?]]. */
    public const EMAIL_PARTS = [
        'shared' => ['Shared by all three emails', [
            'header' => ['Header (top line)', true, false],
            'greeting' => ['Greeting', true, false],
            'button' => ['Button label', true, false],
            'times_heading' => ['Line above the link (plain-text version)', true, false],
            'fallback' => ['"Button doesn\'t work" line', true, false],
            'signature' => ['Signature (a line whose placeholders are empty is left out)', false, false],
        ]],
        'link' => ['Booking link email', [
            'subject' => ['Subject', true, true],
            'intro' => ['Intro (before the times)', false, true],
            'closing' => ['Closing (after the button)', false, false],
        ]],
        'reminder' => ['Reminder email (24h)', [
            'subject' => ['Subject', true, true],
            'intro' => ['Intro when times are still open', false, true],
            'intro_no_times' => ['Intro when no times are left', false, true],
            'closing' => ['Closing (after the button)', false, false],
        ]],
        'final' => ['Final email (72h)', [
            'subject' => ['Subject', true, true],
            'intro' => ['Text', false, true],
        ]],
    ];

    public const FLOWS = ['standard' => 'Standard (booking link)', 'roof_report' => 'Roof report first'];

    /** Config values as they were before saved settings were applied. */
    private static ?array $defaults = null;

    /**
     * Config path => [screen, type, label, options]. Types: text, longtext,
     * int, color, times, services, lines, area, template.
     */
    public static function fields(): array
    {
        $fields = [
            'lead_messages.company' => ['company', 'text', 'Company name', ['required' => true, 'max' => 100]],
            'lead_messages.rep_name' => ['company', 'text', 'Rep name', ['max' => 100]],
            'lead_messages.rep_cell' => ['company', 'text', 'Rep cell', ['max' => 40]],
            'lead_messages.office_phone' => ['company', 'text', 'Office phone', ['max' => 40]],
            'lead_messages.accent' => ['company', 'color', 'Email accent colour', []],

            'lead_triage.services' => ['services', 'services', 'Services', []],
            'lead_messages.service_lines' => ['services', 'lines', 'Service lines', []],

            'slot_finder.service_area' => ['area', 'area', 'Service area', []],
            'lead_triage.out_of_area_message' => ['area', 'longtext', 'Out-of-area message', ['required' => true, 'max' => 2000]],
            'slot_finder.slot_times' => ['area', 'times', 'Slot times', []],
            'slot_finder.slots_to_offer' => ['area', 'int', 'Times to offer', ['min' => 1, 'max' => 10]],
            'slot_finder.search_days' => ['area', 'int', 'Days ahead to look for times', ['min' => 1, 'max' => 60]],
            'slot_finder.min_notice_minutes' => ['area', 'int', 'Minimum notice (minutes)', ['min' => 0, 'max' => 2880]],
            'slot_finder.visit_minutes' => ['area', 'int', 'Visit length (minutes)', ['min' => 15, 'max' => 480]],
            'slot_finder.max_slots_per_day' => ['area', 'int', 'Most times offered on one day', ['min' => 1, 'max' => 20]],
            'slot_finder.max_extra_drive_minutes' => ['area', 'int', 'Max extra drive for a visit (minutes)', ['min' => 5, 'max' => 240]],

            'follow_ups.reminder_after_hours' => ['follow-ups', 'int', 'Reminder after (hours)', ['min' => 1, 'max' => 720]],
            'follow_ups.final_after_hours' => ['follow-ups', 'int', 'Final email after (hours)', ['min' => 1, 'max' => 720]],
            'follow_ups.lost_after_hours' => ['follow-ups', 'int', 'Mark Lost after (hours)', ['min' => 1, 'max' => 2160]],
            'follow_ups.min_hours_before_slot' => ['follow-ups', 'int', 'Only offer times at least this many hours away in the reminder', ['min' => 0, 'max' => 72]],
            'follow_ups.lost_reason' => ['follow-ups', 'text', 'Lost reason', ['required' => true, 'max' => 200]],
        ];

        foreach (self::EMAIL_PARTS as $group => [$title, $parts]) {
            foreach ($parts as $part => [$label, $oneLine, $required]) {
                $fields["lead_messages.templates.{$group}.{$part}"] = ['emails', 'template', "{$title}: {$label}", [
                    'one_line' => $oneLine,
                    'required' => $required,
                    'max' => $oneLine ? 300 : 3000,
                ]];
            }
        }

        return $fields;
    }

    /** Keys of one screen. */
    public static function keys(string $screen): array
    {
        return array_keys(array_filter(self::fields(), fn ($field) => $field[0] === $screen));
    }

    /**
     * Puts the saved values over the config. Safe before the table exists
     * (first migration) or when the database is down: then the defaults stay.
     */
    public static function apply(): void
    {
        try {
            if (! Schema::hasTable('txn_settings')) {
                return;
            }

            $fields = self::fields();
            self::$defaults ??= collect($fields)->mapWithKeys(fn ($field, $key) => [$key => config($key)])->all();

            // Back to the defaults first, so a value reset since the last apply
            // (long-running queue worker) is gone.
            foreach (self::$defaults as $key => $value) {
                config([$key => $value]);
            }

            foreach (DB::table('txn_settings')->pluck('value', 'key') as $key => $json) {
                if (! isset($fields[$key])) {
                    continue;
                }

                [$errors, $clean] = self::check($key, json_decode((string) $json, true));

                if (! $errors) {
                    config([$key => $clean]);
                }
            }
        } catch (Throwable) {
            // No database yet: keep the defaults.
        }
    }

    public static function default(string $key): mixed
    {
        return self::$defaults[$key] ?? config($key);
    }

    /** Whether the setting has a saved value (not the default). */
    public static function isSaved(string $key): bool
    {
        return DB::table('txn_settings')->where('key', $key)->exists();
    }

    /**
     * Saves $values (key => value; null = back to the default), all checked
     * first: one bad value and nothing is saved (ValidationException).
     *
     * @return int number of settings whose value changed
     */
    public static function save(string $screen, array $values, ?int $userId, array $extraErrors = []): int
    {
        $fields = self::fields();
        $errors = $extraErrors;
        $clean = [];

        foreach ($values as $key => $value) {
            if (! isset($fields[$key]) || $fields[$key][0] !== $screen) {
                continue;
            }

            if ($value === null) {
                $clean[$key] = null;

                continue;
            }

            [$fieldErrors, $clean[$key]] = self::check($key, $value);
            array_push($errors, ...$fieldErrors);
        }

        if ($errors) {
            throw ValidationException::withMessages(['settings' => array_values(array_unique($errors))]);
        }

        $changes = 0;

        DB::transaction(function () use ($clean, $fields, $screen, $userId, &$changes) {
            foreach ($clean as $key => $value) {
                $old = config($key);
                $new = $value ?? self::default($key);

                if ($new == self::default($key)) {
                    DB::table('txn_settings')->where('key', $key)->delete();
                } else {
                    DB::table('txn_settings')->updateOrInsert(
                        ['key' => $key],
                        ['value' => json_encode($new), 'updated_by' => $userId, 'updated_at' => now(), 'created_at' => now()]
                    );
                }

                if ($old == $new) {
                    continue;
                }

                config([$key => $new]);

                DB::table('txn_settings_log')->insert([
                    'user_id' => $userId,
                    'area' => "settings:{$screen}",
                    'item' => $fields[$key][2],
                    'old_value' => self::describe($old),
                    'new_value' => self::describe($new).($value === null ? ' (default)' : ''),
                    'created_at' => now(),
                ]);

                $changes++;
            }
        });

        return $changes;
    }

    /**
     * Checks and cleans one value.
     *
     * @return array{0: list<string>, 1: mixed} [errors, clean value]
     */
    public static function check(string $key, mixed $value): array
    {
        [, $type, $label, $options] = self::fields()[$key];
        $errors = [];

        switch ($type) {
            case 'text':
            case 'longtext':
            case 'template':
                $value = is_scalar($value) ? trim(str_replace("\r\n", "\n", (string) $value)) : '';

                if (($options['required'] ?? false) && $value === '') {
                    $errors[] = "{$label}: can't be empty.";
                }

                if (mb_strlen($value) > ($options['max'] ?? 255)) {
                    $errors[] = "{$label}: at most {$options['max']} characters.";
                }

                if ($type === 'template') {
                    if (($options['one_line'] ?? false) && str_contains($value, "\n")) {
                        $errors[] = "{$label}: one line only.";
                    }

                    preg_match_all('/\{([^{}]*)\}/', $value, $found);
                    foreach (array_unique($found[1]) as $name) {
                        if (! array_key_exists($name, self::PLACEHOLDERS)) {
                            $errors[] = "{$label}: unknown placeholder {{$name}}. Allowed: {".implode('} {', array_keys(self::PLACEHOLDERS)).'}.';
                        }
                    }
                } elseif ($type === 'text' && str_contains($value, "\n")) {
                    $errors[] = "{$label}: one line only.";
                }
                break;

            case 'int':
                if (! is_numeric($value) || (int) $value != $value) {
                    $errors[] = "{$label}: must be a whole number.";
                    $value = null;
                    break;
                }

                $value = (int) $value;

                if ($value < $options['min'] || $value > $options['max']) {
                    $errors[] = "{$label}: must be between {$options['min']} and {$options['max']}.";
                }
                break;

            case 'color':
                $value = is_string($value) ? trim($value) : '';

                if (! preg_match('/^#[0-9A-Fa-f]{6}$/', $value)) {
                    $errors[] = "{$label}: must be a colour like #0B84C6.";
                }
                break;

            case 'times':
                $value = is_array($value) ? $value : preg_split('/[\s,;]+/', (string) $value, -1, PREG_SPLIT_NO_EMPTY);
                $value = array_map(fn ($time) => preg_match('/^\d:\d\d$/', trim((string) $time)) ? '0'.trim((string) $time) : trim((string) $time), $value);

                foreach ($value as $time) {
                    if (! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
                        $errors[] = "{$label}: \"{$time}\" is not a time like 09:00 or 13:30 (24-hour clock).";
                    }
                }

                $value = array_values(array_unique($value));
                sort($value);

                if (! $value || count($value) > 12) {
                    $errors[] = "{$label}: give between 1 and 12 times.";
                }
                break;

            case 'services':
                $rows = [];

                foreach (is_array($value) ? $value : [] as $row) {
                    $match = $row['match'] ?? [];
                    $match = is_array($match) ? $match : preg_split('/\s*,\s*/', (string) $match, -1, PREG_SPLIT_NO_EMPTY);
                    $match = array_values(array_unique(array_filter(array_map(fn ($word) => mb_strtolower(trim((string) $word)), $match))));
                    $category = trim((string) ($row['category'] ?? ''));
                    $flow = (string) ($row['flow'] ?? 'standard');

                    if (! $match && $category === '') {
                        continue; // empty row
                    }

                    if (! $match) {
                        $errors[] = "Service \"{$category}\": give at least one keyword.";
                    }

                    if ($category === '' || mb_strlen($category) > 60) {
                        $errors[] = 'Service with keywords "'.implode(', ', $match).'": the category must be 1 to 60 characters.';
                    }

                    if (! array_key_exists($flow, self::FLOWS)) {
                        $errors[] = "Service \"{$category}\": unknown flow.";
                    }

                    foreach ($match as $word) {
                        if (mb_strlen($word) > 60) {
                            $errors[] = "Service \"{$category}\": keyword \"{$word}\" is too long.";
                        }
                    }

                    $rows[] = ['match' => $match, 'category' => $category, 'flow' => $flow];
                }

                if (! $rows) {
                    $errors[] = 'Services: keep at least one service.';
                }

                $value = $rows;
                break;

            case 'lines':
                $value = collect(is_array($value) ? $value : [])
                    ->mapWithKeys(fn ($line, $category) => [trim((string) $category) => trim((string) $line)])
                    ->filter(fn ($line, $category) => $category !== '' && $line !== '')
                    ->all();

                if (($value['default'] ?? '') === '') {
                    $errors[] = 'Service lines: the line for other services can\'t be empty.';
                }

                foreach ($value as $category => $line) {
                    if (mb_strlen($line) > 500) {
                        $errors[] = "Service line for \"{$category}\": at most 500 characters.";
                    }
                }
                break;

            case 'area':
                $value = is_array($value) ? $value : [];
                $lat = $value['center_lat'] ?? null;
                $lon = $value['center_lon'] ?? null;
                $miles = $value['miles'] ?? null;

                if (! is_numeric($lat) || $lat < -90 || $lat > 90 || ! is_numeric($lon) || $lon < -180 || $lon > 180) {
                    $errors[] = 'Service area: the centre must be a valid latitude and longitude.';
                }

                if (! is_numeric($miles) || $miles < 5 || $miles > 1000) {
                    $errors[] = 'Service area: the radius must be between 5 and 1000 miles.';
                }

                $value = [
                    'center_lat' => round((float) $lat, 6),
                    'center_lon' => round((float) $lon, 6),
                    'miles' => (int) $miles,
                    'address' => mb_substr(trim((string) ($value['address'] ?? '')), 0, 200),
                ];
                break;
        }

        return [$errors, $value];
    }

    /** A value in a few words, for the change log. */
    public static function describe(mixed $value): string
    {
        $text = match (true) {
            $value === null => '(empty)',
            is_bool($value) => $value ? 'yes' : 'no',
            is_scalar($value) => (string) $value,
            isset($value['center_lat']) => trim(($value['address'] ?? '').' ('.$value['center_lat'].', '.$value['center_lon'].'), '.$value['miles'].' mi'),
            array_is_list($value) && isset($value[0]['category']) => collect($value)->map(fn ($row) => $row['category'].' ('.implode(', ', (array) $row['match']).')')->implode('; '),
            array_is_list($value) => implode(', ', array_map('strval', $value)),
            default => collect($value)->map(fn ($v, $k) => "{$k}: ".(is_scalar($v) ? $v : json_encode($v)))->implode('; '),
        };

        $text = trim($text) === '' ? '(empty)' : $text;

        return mb_strlen($text) > 500 ? mb_substr($text, 0, 497).'...' : $text;
    }
}
