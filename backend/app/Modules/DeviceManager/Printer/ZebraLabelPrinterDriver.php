<?php

declare(strict_types=1);

namespace App\Modules\DeviceManager\Printer;

use App\Modules\DeviceManager\Data\PrintJob;

/**
 * Zebra label printers (ZD220/ZD230, GK420t, ZT411) — ZPL II.
 *
 * Label geometry is computed from the configured dpi and label size, so the same
 * template prints correctly on a 203 dpi desktop unit and a 300 dpi industrial
 * one. Barcode and price-tag layouts map onto the four §21 label templates.
 */
class ZebraLabelPrinterDriver extends AbstractPrinterDriver
{
    public function language(): string
    {
        return 'zpl';
    }

    public function compile(PrintJob $job): string
    {
        $width = $this->mmToDots((float) $this->setting('label_width_mm', 50));
        $height = $this->mmToDots((float) $this->setting('label_height_mm', 25));
        $darkness = (int) $this->setting('darkness', 10);

        $zpl = "^XA\n";
        $zpl .= "^PW{$width}\n";                        // print width
        $zpl .= "^LL{$height}\n";                       // label length
        $zpl .= '^MD' . max(0, min(30, $darkness)) . "\n"; // media darkness
        $zpl .= "^CI28\n";                              // UTF-8 input, so Gujarati/Hindi text survives
        $zpl .= '^PQ' . $job->copies . "\n";            // quantity

        $y = 10;

        if ($job->title !== null && $job->title !== '') {
            $zpl .= sprintf("^FO10,%d^A0N,%d,%d^FD%s^FS\n", $y, 28, 28, $this->escape($job->title));
            $y += 34;
        }

        foreach ($this->textRows($job) as $row) {
            $zpl .= sprintf("^FO10,%d^A0N,%d,%d^FD%s^FS\n", $y, 20, 20, $this->escape($row));
            $y += 24;
        }

        $barcode = (string) $job->field('barcode', '');
        if ($barcode !== '') {
            $barHeight = max(30, (int) round($height * 0.35));
            $zpl .= sprintf("^FO10,%d^BY2,3,%d\n", $y, $barHeight);
            $zpl .= $this->barcodeCommand($barcode);
            $zpl .= '^FD' . $this->escape($barcode) . "^FS\n";
            $y += $barHeight + 24;
        }

        $price = $job->field('price');
        if ($price !== null && $price !== '') {
            // Price tags want the number big and unmissable.
            $zpl .= sprintf("^FO10,%d^A0N,%d,%d^FD%s^FS\n", $y, 44, 44, $this->escape((string) $price));
        }

        $zpl .= "^XZ\n";

        return $zpl;
    }

    /** ^BE for EAN-13, ^BU for UPC-A, ^BQ for QR, ^BC for Code 128. */
    protected function barcodeCommand(string $code): string
    {
        if (ctype_digit($code) && strlen($code) === 13) {
            return "^BEN,60,Y,N\n";
        }
        if (ctype_digit($code) && strlen($code) === 12) {
            return "^BUN,60,Y,N,Y\n";
        }
        if (strlen($code) > 24 || str_contains($code, ' ')) {
            return "^BQN,2,6\n";
        }

        return "^BCN,60,Y,N,N\n";
    }

    /** Field rows for a label, in reading order. @return string[] */
    protected function textRows(PrintJob $job): array
    {
        $rows = $job->lines;

        foreach ([['sku', 'SKU'], ['variant', ''], ['batch', 'Batch'], ['mrp', 'MRP'], ['expiry', 'Exp'], ['qty', 'Qty']] as [$key, $label]) {
            $value = $job->field($key);
            if ($value === null || $value === '') {
                continue;
            }
            $rows[] = $label === '' ? (string) $value : "{$label}: {$value}";
        }

        return $rows;
    }

    /** ZPL treats ^ ~ \ as control characters inside field data. */
    protected function escape(string $text): string
    {
        return str_replace(['\\', '^', '~'], ['\\\\', '\\5E', '\\7E'], $text);
    }
}
