<?php

namespace Webkul\Admin\Support\Contact;

use Webkul\Contact\Models\Person;

/**
 * The Cal.com guard (Settings > TXN Settings > Safety, txn.guard_calcom;
 * missing = on), in one place for every path that creates or sends a booking
 * link: the booking-link page, automation and follow-ups.
 *
 * Guard on: links only for the test contacts (calcom.test_person_ids) that
 * have a test recipient address, and only while "Allow booking links for the
 * test contacts" is on. Guard off: any contact.
 */
final class BookingLinkGuard
{
    public static function on(): bool
    {
        return (bool) config('txn.guard_calcom', true);
    }

    /** May a Cal.com link be created for this contact at all? */
    public static function allows(Person $person): bool
    {
        if (! self::on()) {
            return true;
        }

        return config('calcom.allow_customer_email', false)
            && in_array((int) $person->id, array_map('intval', (array) config('calcom.test_person_ids', [])), true)
            && array_intersect(self::testRecipients(), array_map('strtolower', self::personEmails($person))) !== [];
    }

    /** Throws when the guard stops a link for this contact (CalcomBookingLinkService calls it first). */
    public static function assertAllows(Person $person): void
    {
        if (! self::allows($person)) {
            throw new CalcomApiException('The Cal.com guard is on (Settings > TXN Settings > Safety): booking links are only created for the test contacts.');
        }
    }

    /**
     * The address a link email may go to for this contact: guard on, a test
     * recipient address; guard off, one of the contact's own addresses.
     * Null when it may not.
     */
    public static function recipientProblem(Person $person, string $email): ?string
    {
        $email = strtolower($email);

        if (self::on()) {
            return in_array($email, self::testRecipients(), true) ? null : 'This address is not an allowed test recipient.';
        }

        return in_array($email, array_map('strtolower', self::personEmails($person)), true) ? null : 'Choose one of this contact\'s email addresses.';
    }

    /** The first address a link email can go to automatically, or null. */
    public static function recipientFor(Person $person): ?string
    {
        foreach (self::personEmails($person) as $email) {
            if (self::recipientProblem($person, $email) === null) {
                return $email;
            }
        }

        return null;
    }

    /** In test mode only one booking-link email may ever be sent (calcom.single_test_attempt). */
    public static function singleTestAttempt(): bool
    {
        return self::on() && config('calcom.single_test_attempt', true);
    }

    /** The contact's valid email addresses. */
    public static function personEmails(Person $person): array
    {
        return collect($person->emails ?? [])
            ->pluck('value')
            ->filter(fn ($email) => is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL))
            ->unique()
            ->values()
            ->all();
    }

    private static function testRecipients(): array
    {
        return array_map('strtolower', (array) config('calcom.test_recipient_emails', []));
    }
}
