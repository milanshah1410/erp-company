<?php

declare(strict_types=1);

namespace App\Modules\DeviceManager\Contracts;

use App\Modules\DeviceManager\Data\ScanResult;

/**
 * Barcode / QR scanners.
 *
 * Most retail scanners are keyboard-wedge: the browser receives the digits and
 * posts the raw frame here for normalisation. Networked and Bluetooth-cradle
 * scanners are polled through the local device agent instead. Both paths end up
 * in `decode()`, so brand quirks (prefix bytes, AIM identifiers, suffix keys)
 * live in exactly one place per brand.
 */
interface BarcodeScannerDriver extends DeviceDriver
{
    /** Normalise one raw frame as it came off the wire/wedge. */
    public function decode(string $raw): ScanResult;

    /**
     * Pull the next queued scan, for scanners that buffer instead of typing.
     * Returns null when nothing is waiting.
     */
    public function poll(): ?ScanResult;

    /** A believable scan for this brand — the Simulator's "Mock Barcode Scan". */
    public function simulateScan(?string $code = null): ScanResult;
}
