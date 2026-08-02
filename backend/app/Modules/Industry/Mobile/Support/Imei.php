<?php

declare(strict_types=1);

namespace App\Modules\Industry\Mobile\Support;

/**
 * IMEI handling.
 *
 * An IMEI is fifteen digits whose last one is a Luhn check digit over the first
 * fourteen — the same scheme a card number uses. That single fact is worth a class
 * of its own here, because the number is *transcribed by hand* dozens of times a
 * day: read off the back of a handset, off a box, off a printed sticker, over a
 * phone. A transposed pair is the normal failure and it is silent — the handset is
 * registered, cover is written against it, and the mistake only surfaces months
 * later when the customer comes back with a claim and nothing matches.
 *
 * Validation is therefore offered but never enforced at the API edge.
 * Refurbished stock, grey imports and older feature phones all turn up with
 * numbers that do not check out, and a shop that cannot register the handset in
 * front of it will stop using the register altogether. So callers warn on a bad
 * check digit rather than refusing the row.
 */
final class Imei
{
    /** Strip everything a human might type around the digits. */
    public static function normalize(?string $value): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $value);

        return $digits === '' ? null : $digits;
    }

    /**
     * Does this look like a real IMEI — fifteen digits with a valid Luhn check
     * digit?
     *
     * A 14-digit number (an IMEI quoted without its check digit, which some boxes
     * do) is not accepted: it is not so much wrong as incomplete, and accepting it
     * would leave two forms of the same handset in the register.
     */
    public static function isValid(?string $value): bool
    {
        $imei = self::normalize($value);

        if ($imei === null || strlen($imei) !== 15) {
            return false;
        }

        return self::luhn($imei);
    }

    /** Luhn checksum over the whole string, including its check digit. */
    private static function luhn(string $digits): bool
    {
        $sum = 0;
        $len = strlen($digits);

        // Right to left, doubling every second digit. A doubled result above 9 has
        // its own digits added, which is the same as subtracting 9.
        for ($i = 0; $i < $len; $i++) {
            $digit = (int) $digits[$len - 1 - $i];

            if ($i % 2 === 1) {
                $digit *= 2;
                if ($digit > 9) {
                    $digit -= 9;
                }
            }

            $sum += $digit;
        }

        return $sum % 10 === 0;
    }
}
