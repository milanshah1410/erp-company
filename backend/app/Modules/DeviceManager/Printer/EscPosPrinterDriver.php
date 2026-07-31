<?php

declare(strict_types=1);

namespace App\Modules\DeviceManager\Printer;

use App\Modules\DeviceManager\Data\PrintJob;
use App\Modules\DeviceManager\Data\PrintResult;

/**
 * Generic ESC/POS thermal printer (Epson TM series, Rugtek, and the long tail of
 * 58/80 mm counter printers).
 *
 * Emits the ESC/POS command stream directly: initialise, centred bold header,
 * the body lines, an optional barcode, feed, cut, and the drawer-kick pulse used
 * after a cash sale.
 */
class EscPosPrinterDriver extends AbstractPrinterDriver
{
    protected const ESC = "\x1b";

    protected const GS = "\x1d";

    public function language(): string
    {
        return 'escpos';
    }

    public function compile(PrintJob $job): string
    {
        $out = $this->initSequence();

        if ($job->title !== null && $job->title !== '') {
            $out .= self::ESC . 'a1';          // centre
            $out .= self::ESC . 'E1';          // bold on
            $out .= self::GS . '!' . "\x11";   // double width + height
            $out .= $job->title . "\n";
            $out .= self::GS . '!' . "\x00";   // normal size
            $out .= self::ESC . 'E0';          // bold off
            $out .= self::ESC . 'a0';          // left
            $out .= str_repeat('-', $this->columns()) . "\n";
        }

        foreach ($job->lines as $line) {
            $out .= $line . "\n";
        }

        // Label-style jobs carry structured fields rather than free lines.
        if ($job->document !== 'receipt') {
            $out .= $this->fieldBlock($job);
        }

        $barcode = (string) $job->field('barcode', '');
        if ($barcode !== '') {
            $out .= "\n" . $this->barcodeBlock($barcode);
        }

        $out .= "\n\n\n";

        if ($this->setting('cut_paper', true)) {
            $out .= self::GS . 'V' . "\x42" . "\x00";  // partial cut with feed
        }

        return $out;
    }

    public function openDrawer(): PrintResult
    {
        $pin = ((string) $this->setting('drawer_pin', '0')) === '1' ? "\x01" : "\x00";
        // ESC p m t1 t2 — on/off pulse widths in 2 ms units.
        $payload = self::ESC . 'p' . $pin . "\x19" . "\xfa";

        if ($this->simulated()) {
            return new PrintResult(
                ok: true,
                language: $this->language(),
                preview: $this->previewOf($payload),
                bytes: strlen($payload),
                message: 'Simulated cash-drawer kick — no pulse sent.',
                simulated: true,
            );
        }

        $response = $this->agent()->sendRaw($this->device(), 'drawer', $payload, ['pin' => $pin === "\x01" ? 1 : 0]);

        return new PrintResult(
            ok: (bool) ($response['ok'] ?? true),
            language: $this->language(),
            preview: $this->previewOf($payload),
            bytes: strlen($payload),
            message: $response['message'] ?? 'Drawer pulse sent.',
        );
    }

    /** Reset, set the code page, left-align. Brands override to add their own init. */
    protected function initSequence(): string
    {
        $codepage = match ((string) $this->setting('codepage', 'cp437')) {
            'cp850' => "\x02",
            'utf8' => "\xff",
            default => "\x00",
        };

        return self::ESC . '@'                    // initialise
            . self::ESC . 't' . $codepage         // select character code table
            . self::ESC . 'a0';                   // left align
    }

    /** Print structured label fields as aligned text — the ESC/POS way. */
    protected function fieldBlock(PrintJob $job): string
    {
        $out = '';
        $map = [
            'sku' => 'SKU',
            'name' => 'Item',
            'variant' => 'Variant',
            'batch' => 'Batch',
            'mrp' => 'MRP',
            'price' => 'Price',
            'qty' => 'Qty',
            'expiry' => 'Expiry',
        ];

        foreach ($map as $key => $label) {
            $value = $job->field($key);
            if ($value === null || $value === '') {
                continue;
            }
            $out .= $this->twoColumn($label, (string) $value) . "\n";
        }

        return $out;
    }

    /** CODE128 via GS k, with the human-readable line under it. */
    protected function barcodeBlock(string $code): string
    {
        $out = self::ESC . 'a1';                     // centre
        $out .= self::GS . 'h' . "\x50";             // height 80 dots
        $out .= self::GS . 'w' . "\x02";             // module width
        $out .= self::GS . 'H' . "\x02";             // HRI below barcode
        $out .= self::GS . 'k' . "\x49" . chr(strlen($code) + 2) . '{B' . $code;
        $out .= "\n" . self::ESC . 'a0';

        return $out;
    }
}
