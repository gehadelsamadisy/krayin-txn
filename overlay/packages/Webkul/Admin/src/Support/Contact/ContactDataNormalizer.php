<?php

namespace Webkul\Admin\Support\Contact;

/**
 * Pure clean-up rules for lead and contact data (no lookups, no saving).
 * Used by the lead check and by booking-answer saving.
 */
final class ContactDataNormalizer
{
    private const STATES = [
        'texas' => 'TX', 'louisiana' => 'LA', 'oklahoma' => 'OK', 'new mexico' => 'NM', 'arkansas' => 'AR',
    ];

    /** Trimmed, single-spaced text; null for blanks and "N/A"-style values. */
    public static function text(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $text = trim(preg_replace('/\s+/u', ' ', (string) $value));
        $empty = array_map('strtolower', config('lead_triage.empty_values', []));

        return ($text === '' || in_array(strtolower($text), $empty, true)) ? null : $text;
    }

    /**
     * Any phone number counts (US or not). A US number becomes "+1XXXXXXXXXX";
     * any other keeps its digits, with "+" when written internationally
     * ("+" or "00"). Null for junk: fewer than 7 or more than 15 digits.
     */
    public static function phone(mixed $value): ?string
    {
        $text = self::text($value);

        if ($text === null) {
            return null;
        }

        $number = trim(preg_replace('/\s*(x|ext\.?|#)\s*\d+$/i', '', $text));
        $international = str_starts_with($number, '+') || str_starts_with(preg_replace('/[\s().-]/', '', $number), '00');
        $digits = preg_replace('/\D/', '', $number);

        if ($international && str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        // US: 10 digits (or 1 + 10); area code and exchange don't start with
        // 0 or 1. Written internationally, only a +1 number is a US one.
        $us = match (true) {
            strlen($digits) === 11 && $digits[0] === '1' => substr($digits, 1),
            ! $international && strlen($digits) === 10 => $digits,
            default => null,
        };

        if ($us !== null && preg_match('/^[2-9]\d{2}[2-9]\d{6}$/', $us)) {
            return '+1'.$us;
        }

        $length = strlen($digits);

        if ($length < 7 || $length > 15) {
            return null;
        }

        return ($international ? '+' : '').$digits;
    }

    public static function email(mixed $value): ?string
    {
        $text = self::text($value);

        return $text !== null && filter_var(strtolower($text), FILTER_VALIDATE_EMAIL) ? strtolower($text) : null;
    }

    /** "TX" for "tx", "Texas", "texas"; other two-letter codes uppercased; anything else as typed. */
    public static function state(mixed $value): ?string
    {
        $text = self::text($value);

        if ($text === null) {
            return null;
        }

        $key = strtolower(rtrim($text, '.'));

        return self::STATES[$key] ?? (preg_match('/^[a-z]{2}$/i', $key) ? strtoupper($key) : $text);
    }

    /** The first 5-digit ZIP in the text (a ZIP+4 keeps its first 5 digits). */
    public static function postalCode(mixed $value): ?string
    {
        $text = self::text($value);

        return $text !== null && preg_match('/\b(\d{5})(?:-\d{4})?\b/', $text, $match) ? $match[1] : null;
    }

    /** City names typed all lower or all upper case become "Title Case"; mixed case is kept. */
    public static function city(mixed $value): ?string
    {
        $text = self::text($value);

        if ($text === null) {
            return null;
        }

        return ($text === mb_strtolower($text) || $text === mb_strtoupper($text))
            ? mb_convert_case(mb_strtolower($text), MB_CASE_TITLE)
            : $text;
    }

    /**
     * A ZIP typed at the end of the street line ("123 Main St, Katy, TX 77494"):
     * [street without it, zip]. A leading number is never taken for a ZIP.
     *
     * @return array{0: ?string, 1: ?string}
     */
    public static function splitTrailingZip(?string $address): array
    {
        if ($address !== null && preg_match('/^(.*\D)[,\s]+(\d{5})(?:-\d{4})?$/', $address, $match)) {
            return [self::text(rtrim($match[1], ' ,')), $match[2]];
        }

        return [$address, null];
    }

    /** Krayin's [{label, value}] list, cleaned with $clean, empties and duplicates removed. */
    public static function valueList(mixed $list, callable $clean): array
    {
        return collect(is_array($list) ? $list : [])
            ->map(function ($row) use ($clean) {
                $raw = is_array($row) ? ($row['value'] ?? null) : $row;
                $value = $clean($raw) ?? self::text($raw);

                return $value === null ? null : ['label' => (is_array($row) ? ($row['label'] ?? null) : null) ?: 'Work', 'value' => $value];
            })
            ->filter()
            ->unique('value')
            ->values()
            ->all();
    }
}
