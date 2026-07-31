<?php

declare(strict_types=1);

namespace App\Modules\DeviceManager\Barcode;

use App\Modules\DeviceManager\Data\ScanResult;

/**
 * TVS Electronics scanners (BS-L100, BS-i202, BSC-101).
 *
 * TVS units are the most common counter scanner in the Gujarat retail market.
 * In HID mode they behave like a wedge; in serial mode the firmware prepends a
 * two-character code-type marker that has to come off before lookup.
 */
class TvsBarcodeDriver extends AbstractBarcodeDriver
{
    /** TVS serial-mode code-type markers. */
    private const CODE_TYPES = [
        'A0' => 'CODE-128',
        'A1' => 'EAN-13',
        'A2' => 'EAN-8',
        'A3' => 'UPC-A',
        'A4' => 'CODE-39',
        'A5' => 'ITF-14',
        'A6' => 'QR',
    ];

    public function decode(string $raw): ScanResult
    {
        $code = $this->clean($raw);
        $symbology = null;

        $prefix = (string) $this->setting('prefix', '');
        if ($prefix !== '' && str_starts_with($code, $prefix)) {
            $code = substr($code, strlen($prefix));
        }

        if ($this->setting('interface', 'hid') === 'serial' && strlen($code) > 2) {
            $marker = substr($code, 0, 2);
            if (isset(self::CODE_TYPES[$marker])) {
                $symbology = self::CODE_TYPES[$marker];
                $code = substr($code, 2);
            }
        }

        $code = $this->assertLength(trim($code));

        return new ScanResult($code, $symbology ?? $this->guessSymbology($code), $raw);
    }

    protected function wrapFrame(string $code, string $symbology): string
    {
        $prefix = (string) $this->setting('prefix', '');

        if ($this->setting('interface', 'hid') === 'serial') {
            $marker = array_search($symbology, self::CODE_TYPES, true) ?: 'A0';

            return $prefix . $marker . $code . "\r\n";
        }

        return $prefix . $code . "\r";
    }
}
