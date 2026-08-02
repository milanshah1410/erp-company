<?php

declare(strict_types=1);

namespace App\Modules\Industry\BookStore\Services;

/**
 * The ISBN, as arithmetic.
 *
 * Every one of these is a pure function on a string, which is the point: the
 * ISBN is the one identifier in this ERP that the shop does not issue and cannot
 * change, so nothing here needs a database, a tenant or a model. The rules are
 * the same in every shop in the world.
 *
 * Two facts do the work:
 *
 *  - **Both forms carry a check digit**, so a mistyped ISBN can be caught at the
 *    keyboard rather than three weeks later when the wrong book arrives. That is
 *    worth more here than in any other master: a book is ordered by its number,
 *    not its name, and one wrong digit is a different book that also exists.
 *  - **The thirteen-digit form is the barcode.** An ISBN-13 beginning 978 or 979
 *    is a valid EAN-13, printed on the back cover, and it is what the scanner at
 *    the counter sends. This is why the plugin copies it onto `products.barcode`
 *    rather than asking a shop to key the same thirteen digits twice.
 *
 * There is deliberately no hyphenation helper. Correct ISBN hyphenation needs the
 * registration-group range table published by the International ISBN Agency, and
 * a plausible-looking guess at where the dashes go is worse than none: it prints
 * on a label and reads as authoritative.
 */
final class Isbn
{
    /**
     * Digits only, upper-cased — the form everything else here expects, and the
     * form stored. Hyphens and spaces are how an ISBN is *printed*; they carry no
     * information and matching on them means a customer reading a number down the
     * phone gets "no such book".
     *
     * Returns null for anything that is not a plausible ISBN at all, so a caller
     * can treat "unparseable" and "absent" the same way.
     */
    public static function normalize(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $clean = strtoupper(preg_replace('/[^0-9Xx]/', '', $raw) ?? '');

        if ($clean === '') {
            return null;
        }

        // 'X' is only ever the check digit of a ten-digit ISBN. Anywhere else it
        // is a typing slip, and letting it through would store a number that can
        // never match anything.
        if (str_contains(substr($clean, 0, -1), 'X')) {
            return null;
        }

        return in_array(strlen($clean), [10, 13], true) ? $clean : null;
    }

    /** Is this a structurally valid ISBN in either form? */
    public static function isValid(?string $raw): bool
    {
        $isbn = self::normalize($raw);

        if ($isbn === null) {
            return false;
        }

        return strlen($isbn) === 10 ? self::isValid10($isbn) : self::isValid13($isbn);
    }

    /**
     * ISBN-10: the digits weighted 10 down to 1 must sum to a multiple of 11.
     * The check digit may be X, standing for ten — the reason the eleventh
     * residue needs a symbol the decimal system does not have.
     */
    public static function isValid10(string $isbn): bool
    {
        if (strlen($isbn) !== 10) {
            return false;
        }

        $sum = 0;
        for ($i = 0; $i < 10; $i++) {
            $char = $isbn[$i];
            $value = $char === 'X' ? 10 : (ctype_digit($char) ? (int) $char : -1);

            // X anywhere but the last position, or any other stray character.
            if ($value < 0 || ($value === 10 && $i !== 9)) {
                return false;
            }

            $sum += $value * (10 - $i);
        }

        return $sum % 11 === 0;
    }

    /**
     * ISBN-13: the EAN-13 rule, digits weighted 1 and 3 alternately summing to a
     * multiple of ten. Same arithmetic every barcode scanner in the shop already
     * does.
     */
    public static function isValid13(string $isbn): bool
    {
        if (strlen($isbn) !== 13 || ! ctype_digit($isbn)) {
            return false;
        }

        $sum = 0;
        for ($i = 0; $i < 13; $i++) {
            $sum += ((int) $isbn[$i]) * ($i % 2 === 0 ? 1 : 3);
        }

        return $sum % 10 === 0;
    }

    /**
     * The thirteen-digit form of a ten-digit ISBN: prefix 978, keep the first
     * nine digits, recompute the check.
     *
     * Every ISBN-10 has an ISBN-13; this is what makes a pre-2007 backlist
     * scannable at a modern till, and it is why the register only ever has to be
     * *given* one of the two.
     */
    public static function toIsbn13(?string $raw): ?string
    {
        $isbn = self::normalize($raw);

        if ($isbn === null) {
            return null;
        }

        if (strlen($isbn) === 13) {
            return self::isValid13($isbn) ? $isbn : null;
        }

        if (! self::isValid10($isbn)) {
            return null;
        }

        $body = '978'.substr($isbn, 0, 9);

        return $body.self::checkDigit13($body);
    }

    /**
     * The ten-digit form, where one exists. Only ISBNs in the 978 prefix have
     * one: the 979 range was created because 978 filled up, and a 979 title has
     * never had a ten-digit number at all. Returning null for those is the honest
     * answer, not a failure.
     */
    public static function toIsbn10(?string $raw): ?string
    {
        $isbn = self::normalize($raw);

        if ($isbn === null) {
            return null;
        }

        if (strlen($isbn) === 10) {
            return self::isValid10($isbn) ? $isbn : null;
        }

        if (! self::isValid13($isbn) || ! str_starts_with($isbn, '978')) {
            return null;
        }

        $body = substr($isbn, 3, 9);
        $sum = 0;
        for ($i = 0; $i < 9; $i++) {
            $sum += ((int) $body[$i]) * (10 - $i);
        }

        $check = (11 - ($sum % 11)) % 11;

        return $body.($check === 10 ? 'X' : (string) $check);
    }

    /** The EAN-13 check digit for a twelve-digit body. */
    private static function checkDigit13(string $body): string
    {
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $sum += ((int) $body[$i]) * ($i % 2 === 0 ? 1 : 3);
        }

        return (string) ((10 - ($sum % 10)) % 10);
    }
}
