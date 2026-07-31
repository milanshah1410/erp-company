<?php

declare(strict_types=1);

namespace App\Modules\DeviceManager\Barcode;

use App\Modules\DeviceManager\Data\ScanResult;

/**
 * Zebra scanners (DS2208, DS8178, LI4278).
 *
 * Zebra's default is a single-letter Symbol Code ID rather than the three-byte
 * AIM identifier, and cordless models can also be driven through their cradle
 * over the network — hence the two code-id modes this adapter understands.
 */
class ZebraBarcodeDriver extends AbstractBarcodeDriver
{
    /** Symbol Code ID characters (Zebra's own scheme). */
    private const SYMBOL_IDS = [
        'A' => 'UPC-A',
        'B' => 'CODE-39',
        'C' => 'CODE-128',
        'E' => 'EAN-13',
        'F' => 'EAN-8',
        'I' => 'ITF-14',
        'Y' => 'CODE-93',
        's' => 'QR',
        'u' => 'DATAMATRIX',
    ];

    /** AIM identifiers, used when the scanner is set to AIM mode instead. */
    private const AIM = [
        ']E0' => 'EAN-13',
        ']E4' => 'EAN-8',
        ']C1' => 'CODE-128',
        ']A0' => 'CODE-39',
        ']Q1' => 'QR',
    ];

    public function decode(string $raw): ScanResult
    {
        $code = $this->clean($raw);
        $symbology = null;
        $mode = (string) $this->setting('code_id', 'symbol');

        if ($mode === 'symbol' && strlen($code) > 1 && isset(self::SYMBOL_IDS[$code[0]])) {
            $symbology = self::SYMBOL_IDS[$code[0]];
            $code = substr($code, 1);
        } elseif ($mode === 'aim' && strlen($code) > 3 && $code[0] === ']') {
            $id = substr($code, 0, 3);
            if (isset(self::AIM[$id])) {
                $symbology = self::AIM[$id];
                $code = substr($code, 3);
            }
        }

        $code = $this->assertLength(trim($code));

        return new ScanResult($code, $symbology ?? $this->guessSymbology($code), $raw);
    }

    protected function wrapFrame(string $code, string $symbology): string
    {
        return match ((string) $this->setting('code_id', 'symbol')) {
            'symbol' => (string) (array_search($symbology, self::SYMBOL_IDS, true) ?: 'C') . $code . "\r",
            'aim' => (string) (array_search($symbology, self::AIM, true) ?: ']C1') . $code . "\r",
            default => $code . "\r",
        };
    }

    /** Cordless models are polled at the cradle, corded ones at the local port. */
    public function poll(): ?ScanResult
    {
        if ($this->simulated()) {
            return $this->simulateScan();
        }

        $host = (string) $this->setting('cradle_host', '');
        $response = $this->agent()->send($this->device(), 'scan.poll', array_filter([
            'cradle_host' => $host !== '' ? $host : null,
        ]));

        $raw = $response['data'] ?? $response['raw'] ?? null;

        return ($raw === null || $raw === '') ? null : $this->decode((string) $raw);
    }
}
