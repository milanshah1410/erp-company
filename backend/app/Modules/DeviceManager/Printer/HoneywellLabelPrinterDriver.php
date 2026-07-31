<?php

declare(strict_types=1);

namespace App\Modules\DeviceManager\Printer;

use App\Modules\DeviceManager\Data\PrintJob;

/**
 * Honeywell label printers (PC42t, PC42d, PM42).
 *
 * These ship in ZPL-II emulation, so the compiler is inherited — but the
 * emulation ignores ^MD and expects darkness through ~SD, and it needs an
 * explicit home offset (^LH) or the first field lands off the label. Exactly the
 * kind of small brand difference a per-brand adapter is for.
 */
class HoneywellLabelPrinterDriver extends ZebraLabelPrinterDriver
{
    public function compile(PrintJob $job): string
    {
        $darkness = max(0, min(30, (int) $this->setting('darkness', 12)));

        $zpl = parent::compile($job);

        // ~SD before the label body; drop the ^MD the emulation would ignore.
        $zpl = preg_replace('/\^MD\d+\n/', '', $zpl) ?? $zpl;
        $zpl = str_replace("^XA\n", sprintf("^XA\n~SD%02d\n^LH0,0\n", $darkness), $zpl);

        return $zpl;
    }
}
