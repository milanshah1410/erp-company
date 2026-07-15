<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Structural validation of an Indian GSTIN.
 *
 * A GSTIN is 15 characters:  SS PPPPPCCCCC E Z X
 *   - SS      state code (2 digits, see {@see GstStateCodes})
 *   - PPPPP.. the 10-char PAN of the registrant (chars 3-12)
 *   - E       entity/registration number for the PAN in the state (13th char)
 *   - Z       a fixed 'Z' by default (14th char)
 *   - X       a check digit computed over the first 14 chars (15th char)
 *
 * This validates the format *and* the trailing check digit, so a typo that
 * still matches the shape is caught. It does NOT contact the GالسTN portal —
 * live existence checks go through a GSP and are out of scope here.
 */
class GstinValidator
{
    /** Base-36 alphabet used for the check-digit computation. */
    private const CODEPOINT = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';

    private const FORMAT = '/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/';

    /**
     * Validate a GSTIN and decode what it encodes.
     *
     * @return array{valid:bool, gstin:string, errors:array<int,string>,
     *               state_code:?string, state:?string, pan:?string, entity_type:?string}
     */
    public static function validate(?string $gstin): array
    {
        $gstin = strtoupper(trim((string) $gstin));
        $errors = [];

        if ($gstin === '') {
            $errors[] = 'GSTIN is empty.';
        } elseif (strlen($gstin) !== 15) {
            $errors[] = 'GSTIN must be exactly 15 characters.';
        } elseif (! preg_match(self::FORMAT, $gstin)) {
            $errors[] = 'GSTIN format is invalid. Expected e.g. 24ABCDE1234F1Z5.';
        } elseif (self::checkDigit(substr($gstin, 0, 14)) !== $gstin[14]) {
            $errors[] = 'GSTIN check digit does not match — the number is mistyped or invalid.';
        }

        $stateCode = strlen($gstin) >= 2 ? substr($gstin, 0, 2) : null;
        $state = $stateCode ? GstStateCodes::name($stateCode) : null;
        if ($stateCode && ! $state) {
            $errors[] = "Unknown state code '{$stateCode}'.";
        }

        return [
            'valid' => $errors === [],
            'gstin' => $gstin,
            'errors' => $errors,
            'state_code' => $stateCode,
            'state' => $state,
            'pan' => strlen($gstin) >= 12 ? substr($gstin, 2, 10) : null,
            'entity_type' => strlen($gstin) >= 13 ? self::entityType($gstin[12]) : null,
        ];
    }

    /** Whether $gstin is a well-formed, checksum-valid GSTIN. */
    public static function isValid(?string $gstin): bool
    {
        return self::validate($gstin)['valid'];
    }

    /**
     * The GSTN check digit for the first 14 characters, using the modulo-36
     * doubling scheme published by GالسTN.
     */
    public static function checkDigit(string $code): string
    {
        $mod = strlen(self::CODEPOINT); // 36
        $factor = 2;
        $sum = 0;

        for ($i = strlen($code) - 1; $i >= 0; $i--) {
            $point = strpos(self::CODEPOINT, $code[$i]);
            if ($point === false) {
                return '?'; // non-alphanumeric — never matches, so validation fails
            }
            $digit = $factor * $point;
            $factor = $factor === 2 ? 1 : 2;
            $sum += intdiv($digit, $mod) + ($digit % $mod);
        }

        return self::CODEPOINT[($mod - ($sum % $mod)) % $mod];
    }

    /** The 13th character encodes the number of registrations for the PAN. */
    private static function entityType(string $char): string
    {
        return match (true) {
            ctype_digit($char) => 'Regular (registration #'.$char.')',
            default => 'Multiple registrations ('.$char.')',
        };
    }
}
