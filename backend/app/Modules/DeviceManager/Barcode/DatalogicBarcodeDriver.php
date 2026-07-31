<?php

declare(strict_types=1);

namespace App\Modules\DeviceManager\Barcode;

use App\Modules\DeviceManager\Data\ScanResult;

/**
 * Datalogic scanners (QuickScan QW2100, Gryphon GD4500).
 *
 * In RS-232 mode Datalogic firmware wraps the payload in STX … ETX. Those two
 * control bytes survive a raw serial read (though not a wedge burst), so they
 * are stripped before the generic cleanup runs.
 */
class DatalogicBarcodeDriver extends AbstractBarcodeDriver
{
    private const STX = "\x02";

    private const ETX = "\x03";

    public function decode(string $raw): ScanResult
    {
        $code = $raw;

        if ($this->setting('strip_stx_etx', true)) {
            $start = strpos($code, self::STX);
            $end = strrpos($code, self::ETX);
            if ($start !== false && $end !== false && $end > $start) {
                $code = substr($code, $start + 1, $end - $start - 1);
            }
        }

        $code = $this->assertLength($this->clean($code));

        return new ScanResult($code, $this->guessSymbology($code), $raw);
    }

    protected function wrapFrame(string $code, string $symbology): string
    {
        return $this->setting('strip_stx_etx', true)
            ? self::STX . $code . self::ETX . "\r"
            : $code . "\r";
    }
}
