<?php

declare(strict_types=1);

namespace App\Modules\DeviceManager\Printer;

use App\Modules\DeviceManager\Data\PrintJob;

/**
 * TSC label printers (TE244, TTP-244 Pro, TDP-225) — TSPL.
 *
 * TSPL is line-oriented and millimetre-based, so unlike ZPL the label size and
 * inter-label gap are declared in mm and the printer works the dots out. The gap
 * must match the physical stock or the sensor mis-feeds a label per print.
 */
class TscLabelPrinterDriver extends AbstractPrinterDriver
{
    public function language(): string
    {
        return 'tspl';
    }

    public function compile(PrintJob $job): string
    {
        $width = (float) $this->setting('label_width_mm', 50);
        $height = (float) $this->setting('label_height_mm', 25);
        $gap = (float) $this->setting('gap_mm', 2);
        $density = max(0, min(15, (int) $this->setting('density', 8)));

        $out = sprintf("SIZE %.1f mm,%.1f mm\n", $width, $height);
        $out .= sprintf("GAP %.1f mm,0 mm\n", $gap);
        $out .= "DIRECTION 1\n";
        $out .= "DENSITY {$density}\n";
        $out .= "CODEPAGE UTF-8\n";
        $out .= "CLS\n";

        $y = 10;

        if ($job->title !== null && $job->title !== '') {
            $out .= sprintf("TEXT 10,%d,\"3\",0,1,1,\"%s\"\n", $y, $this->escape($job->title));
            $y += 34;
        }

        foreach ($this->textRows($job) as $row) {
            $out .= sprintf("TEXT 10,%d,\"2\",0,1,1,\"%s\"\n", $y, $this->escape($row));
            $y += 24;
        }

        $barcode = (string) $job->field('barcode', '');
        if ($barcode !== '') {
            $barHeight = max(30, $this->mmToDots($height * 0.35));
            $out .= sprintf(
                "BARCODE 10,%d,\"%s\",%d,1,0,2,4,\"%s\"\n",
                $y,
                $this->symbology($barcode),
                $barHeight,
                $this->escape($barcode),
            );
            $y += $barHeight + 26;
        }

        $price = $job->field('price');
        if ($price !== null && $price !== '') {
            $out .= sprintf("TEXT 10,%d,\"4\",0,1,1,\"%s\"\n", $y, $this->escape((string) $price));
        }

        $out .= 'PRINT ' . $job->copies . ",1\n";

        return $out;
    }

    /** TSPL symbology names. */
    protected function symbology(string $code): string
    {
        if (ctype_digit($code) && strlen($code) === 13) {
            return 'EAN13';
        }
        if (ctype_digit($code) && strlen($code) === 12) {
            return 'UPCA';
        }

        return '128';
    }

    /** @return string[] */
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

    /** TSPL string literals are double-quoted, with backslash escaping. */
    protected function escape(string $text): string
    {
        return str_replace(['\\', '"'], ['\\\\', '\\"'], $text);
    }
}
