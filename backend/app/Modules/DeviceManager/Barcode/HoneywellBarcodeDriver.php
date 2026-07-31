<?php

declare(strict_types=1);

namespace App\Modules\DeviceManager\Barcode;

use App\Modules\DeviceManager\Data\ScanResult;

/**
 * Honeywell scanners (Voyager 1250g, Xenon 1900, Orbit 7190g).
 *
 * With "Transmit Code ID" on, Honeywell firmware prefixes the AIM symbology
 * identifier — ]E0 for EAN-13, ]C1 for Code 128, ]Q1 for QR and so on. Reading
 * it gives the ERP a reliable symbology instead of a guess from the digit count,
 * which matters when an internal SKU happens to be 13 digits long.
 */
class HoneywellBarcodeDriver extends AbstractBarcodeDriver
{
    /** AIM ISO/IEC 15424 identifiers Honeywell emits, mapped to our names. */
    private const AIM = [
        ']E0' => 'EAN-13',
        ']E4' => 'EAN-8',
        ']E3' => 'UPC-A',
        ']C0' => 'CODE-128',
        ']C1' => 'CODE-128',
        ']A0' => 'CODE-39',
        ']I0' => 'ITF-14',
        ']Q1' => 'QR',
        ']d1' => 'DATAMATRIX',
        ']X0' => 'CODE-93',
    ];

    public function decode(string $raw): ScanResult
    {
        $code = $this->clean($raw);
        $symbology = null;

        if ($this->setting('aim_identifiers', true) && strlen($code) > 3 && $code[0] === ']') {
            $id = substr($code, 0, 3);
            $symbology = self::AIM[$id] ?? null;
            if ($symbology !== null) {
                $code = substr($code, 3);
            }
        }

        $code = $this->assertLength(trim($code));

        return new ScanResult($code, $symbology ?? $this->guessSymbology($code), $raw);
    }

    protected function wrapFrame(string $code, string $symbology): string
    {
        if (! $this->setting('aim_identifiers', true)) {
            return $code . "\r";
        }

        $id = array_search($symbology, self::AIM, true) ?: ']C1';

        return $id . $code . "\r";
    }
}
