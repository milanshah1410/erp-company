<?php

namespace App\Services;

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\PngWriter;
use Picqer\Barcode\BarcodeGeneratorPNG;

class BarcodeService
{
    /** Raw PNG bytes for a CODE128 1D barcode encoding $value. */
    public function code128Png(string $value): string
    {
        $generator = new BarcodeGeneratorPNG();

        return $generator->getBarcode($value, $generator::TYPE_CODE_128, 2, 80);
    }

    /** Raw PNG bytes for a QR code encoding $value. */
    public function qrPng(string $value, int $size = 300): string
    {
        $result = (new Builder())->build(
            writer: new PngWriter(),
            data: $value,
            size: $size,
            margin: 10,
        );

        return $result->getString();
    }

    public function code128DataUri(string $value): string
    {
        return 'data:image/png;base64,'.base64_encode($this->code128Png($value));
    }

    public function qrDataUri(string $value, int $size = 300): string
    {
        return 'data:image/png;base64,'.base64_encode($this->qrPng($value, $size));
    }
}
