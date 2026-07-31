<?php

declare(strict_types=1);

namespace App\Modules\DeviceManager\Barcode;

use App\Modules\DeviceManager\Data\ConnectionResult;
use App\Modules\DeviceManager\Data\ScanResult;

/**
 * Generic keyboard-wedge scanner.
 *
 * These devices are just keyboards: nothing to connect to, nothing to poll. The
 * scan screen captures the typed burst and posts it here so the configured
 * prefix/suffix are stripped in one place instead of in every view.
 */
class GenericHidBarcodeDriver extends AbstractBarcodeDriver
{
    public function decode(string $raw): ScanResult
    {
        $code = $this->clean($raw);

        $prefix = (string) $this->setting('prefix', '');
        if ($prefix !== '' && str_starts_with($code, $prefix)) {
            $code = substr($code, strlen($prefix));
        }

        // The suffix is usually the Enter key, already removed by clean(); a
        // literal one (e.g. a programmed ";") still needs trimming.
        $suffix = (string) $this->setting('suffix', '');
        $suffix = str_replace(['\r', '\n', '\t'], '', $suffix);
        if ($suffix !== '' && str_ends_with($code, $suffix)) {
            $code = substr($code, 0, -strlen($suffix));
        }

        $code = $this->assertLength(trim($code));

        return new ScanResult($code, $this->guessSymbology($code), $raw);
    }

    protected function wrapFrame(string $code, string $symbology): string
    {
        return (string) $this->setting('prefix', '') . $code . "\r";
    }

    /**
     * A wedge scanner has no channel to test, so the check is that the device is
     * configured sanely — reported honestly rather than faking a handshake.
     */
    public function testConnection(): ConnectionResult
    {
        if ($this->simulated()) {
            return ConnectionResult::ok(
                'Simulator ready — mock scans will be decoded with the configured prefix/suffix.',
                ['mode' => 'simulator'],
                latencyMs: 0,
                simulated: true,
            );
        }

        $min = (int) $this->setting('min_length', 4);

        return ConnectionResult::ok(
            'Keyboard-wedge scanners need no connection: focus a scan field and scan. Codes shorter than '
                . $min . ' characters will be ignored.',
            ['mode' => 'keyboard-wedge', 'min_length' => $min],
            latencyMs: 0,
        );
    }

    /** Nothing to poll — the browser pushes wedge input in. */
    public function poll(): ?ScanResult
    {
        return $this->simulated() ? $this->simulateScan() : null;
    }
}
