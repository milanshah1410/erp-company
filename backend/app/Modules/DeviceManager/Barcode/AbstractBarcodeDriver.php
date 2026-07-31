<?php

declare(strict_types=1);

namespace App\Modules\DeviceManager\Barcode;

use App\Modules\DeviceManager\Contracts\BarcodeScannerDriver;
use App\Modules\DeviceManager\Data\ScanResult;
use App\Modules\DeviceManager\Support\AbstractDeviceDriver;
use RuntimeException;

/**
 * Shared scanner behaviour: control-character cleanup, symbology inference from
 * the code's own shape, and a simulator that runs the real `decode()` path over
 * a brand-formatted synthetic frame.
 *
 * That last point is what makes Simulator mode worth trusting — a mock scan
 * exercises the same prefix stripping and symbology detection the counter will.
 */
abstract class AbstractBarcodeDriver extends AbstractDeviceDriver implements BarcodeScannerDriver
{
    /** Sample codes the simulator cycles through when none is supplied. */
    protected const SAMPLE_CODES = [
        '8901234567890',   // EAN-13, the usual Indian retail barcode
        '036000291452',    // UPC-A
        'SKU-TSHIRT-M-BLK', // Code 128 internal SKU
        '5901234123457',   // EAN-13
    ];

    public function type(): string
    {
        return 'barcode';
    }

    /** Wrap a raw frame the brand way — used by the simulator. */
    abstract protected function wrapFrame(string $code, string $symbology): string;

    public function poll(): ?ScanResult
    {
        if ($this->simulated()) {
            return $this->simulateScan();
        }

        $response = $this->agent()->send($this->device(), 'scan.poll', [
            'com_port' => $this->setting('com_port'),
            'baud_rate' => $this->setting('baud_rate'),
        ]);

        $raw = $response['data'] ?? $response['raw'] ?? null;
        if ($raw === null || $raw === '') {
            return null;
        }

        return $this->decode((string) $raw);
    }

    public function simulateScan(?string $code = null): ScanResult
    {
        $code = $code !== null && trim($code) !== ''
            ? trim($code)
            : self::SAMPLE_CODES[random_int(0, count(self::SAMPLE_CODES) - 1)];

        $symbology = $this->guessSymbology($code);
        $result = $this->decode($this->wrapFrame($code, $symbology));

        return new ScanResult($result->code, $result->symbology ?? $symbology, $result->raw, simulated: true);
    }

    /**
     * Strip transport noise: control characters, the trailing Enter/Tab the
     * wedge sends, and stray whitespace.
     */
    protected function clean(string $raw): string
    {
        $clean = preg_replace('/[\x00-\x08\x0A-\x1F\x7F]/', '', $raw) ?? $raw;

        return trim($clean);
    }

    /** Reject frames too short to be a real code — usually a stray keypress. */
    protected function assertLength(string $code): string
    {
        $min = (int) $this->setting('min_length', 1);
        if ($min > 0 && mb_strlen($code) < $min) {
            throw new RuntimeException("Scan discarded: \"{$code}\" is shorter than the {$min}-character minimum.");
        }

        return $code;
    }

    /** Best-effort symbology from the payload's own shape. */
    protected function guessSymbology(string $code): string
    {
        $len = strlen($code);

        if (ctype_digit($code)) {
            return match ($len) {
                8 => 'EAN-8',
                12 => 'UPC-A',
                13 => 'EAN-13',
                14 => 'ITF-14',
                default => 'CODE-128',
            };
        }

        if (str_starts_with($code, 'http://') || str_starts_with($code, 'https://') || str_contains($code, "\n")) {
            return 'QR';
        }

        return 'CODE-128';
    }
}
