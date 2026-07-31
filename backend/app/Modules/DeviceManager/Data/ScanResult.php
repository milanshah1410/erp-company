<?php

declare(strict_types=1);

namespace App\Modules\DeviceManager\Data;

/**
 * A normalised barcode/QR scan. Brand adapters strip their own prefix/suffix
 * wrapping and AIM symbology identifiers before producing this, so the ERP only
 * ever sees the clean payload.
 */
class ScanResult
{
    public function __construct(
        public readonly string $code,
        public readonly ?string $symbology = null,
        public readonly ?string $raw = null,
        public readonly bool $simulated = false,
    ) {}

    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'symbology' => $this->symbology,
            'raw' => $this->raw,
            'simulated' => $this->simulated,
        ];
    }
}
