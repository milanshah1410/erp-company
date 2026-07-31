<?php

declare(strict_types=1);

namespace App\Modules\DeviceManager\Printer;

use App\Modules\DeviceManager\Contracts\PrinterDriver;
use App\Modules\DeviceManager\Data\PrintJob;
use App\Modules\DeviceManager\Data\PrintResult;
use App\Modules\DeviceManager\Support\AbstractDeviceDriver;
use RuntimeException;

/**
 * Shared printer behaviour: compile-then-send, and a readable preview of the
 * compiled payload.
 *
 * Simulator mode stops at compile — no bytes leave the server, the preview comes
 * back to the screen, and a label template can be proofread (or asserted on in a
 * test) before anyone buys a printer.
 */
abstract class AbstractPrinterDriver extends AbstractDeviceDriver implements PrinterDriver
{
    public function type(): string
    {
        return 'printer';
    }

    public function print(PrintJob $job): PrintResult
    {
        $payload = $this->compile($job);
        $preview = $this->previewOf($payload);

        if ($this->simulated()) {
            return new PrintResult(
                ok: true,
                language: $this->language(),
                preview: $preview,
                bytes: strlen($payload),
                copies: $job->copies,
                message: sprintf(
                    'Simulated %s print — %d copy(ies), %d bytes of %s compiled but not sent.',
                    $job->document,
                    $job->copies,
                    strlen($payload),
                    strtoupper($this->language()),
                ),
                simulated: true,
            );
        }

        $response = $this->agent()->sendRaw($this->device(), 'print', $payload, [
            'language' => $this->language(),
            'copies' => $job->copies,
            'document' => $job->document,
            'tcp_host' => $this->setting('tcp_host'),
        ]);

        $ok = (bool) ($response['ok'] ?? true);

        return new PrintResult(
            ok: $ok,
            language: $this->language(),
            preview: $preview,
            bytes: strlen($payload),
            copies: $job->copies,
            message: $response['message'] ?? ($ok ? 'Sent to printer.' : 'Printer reported a failure.'),
        );
    }

    /** Label printers have no drawer; receipt adapters override this. */
    public function openDrawer(): PrintResult
    {
        throw new RuntimeException("{$this->brand()} is a label printer — it has no cash drawer port.");
    }

    /**
     * Render the compiled payload for human eyes: control bytes become named
     * tokens so an ESC/POS stream is as readable as ZPL in the preview pane.
     */
    protected function previewOf(string $payload): string
    {
        $named = [
            "\x1b" => '<ESC>',
            "\x1d" => '<GS>',
            "\x1c" => '<FS>',
            "\x0a" => "\n",
            "\x0d" => '',
            "\x09" => "\t",
            "\x00" => '<NUL>',
        ];

        $out = strtr($payload, $named);

        // Anything still non-printable becomes a hex token rather than mojibake.
        return preg_replace_callback(
            '/[\x00-\x09\x0B-\x1F\x7F]/',
            fn (array $m) => sprintf('<%02X>', ord($m[0])),
            $out,
        ) ?? $out;
    }

    /** Dots per millimetre for the configured head resolution. */
    protected function dotsPerMm(): float
    {
        return ((float) $this->setting('dpi', 203)) / 25.4;
    }

    protected function mmToDots(float $mm): int
    {
        return (int) round($mm * $this->dotsPerMm());
    }

    /** Receipt column count for the configured roll width. */
    protected function columns(): int
    {
        return ((string) $this->setting('paper_width', '80')) === '58' ? 32 : 48;
    }

    /** "Item ................ 120.00" — the receipt workhorse. */
    protected function twoColumn(string $left, string $right): string
    {
        $width = $this->columns();
        $left = mb_substr($left, 0, max(1, $width - mb_strlen($right) - 1));
        $pad = max(1, $width - mb_strlen($left) - mb_strlen($right));

        return $left . str_repeat(' ', $pad) . $right;
    }
}
