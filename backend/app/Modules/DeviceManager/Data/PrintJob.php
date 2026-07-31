<?php

declare(strict_types=1);

namespace App\Modules\DeviceManager\Data;

/**
 * A device-independent print request. The ERP describes *what* to print — a
 * receipt, a barcode label, a price tag — and each printer adapter compiles it
 * into its own page language (ESC/POS, ZPL, TSPL…).
 */
class PrintJob
{
    public function __construct(
        /** receipt | label | price_tag | test */
        public readonly string $document,
        /** Free-form title line (shop name, product name…). */
        public readonly ?string $title = null,
        /** @var array<int,string> Plain text lines, for receipts and test pages. */
        public readonly array $lines = [],
        /** @var array<string,mixed> Structured label fields: barcode, sku, price, mrp, qty… */
        public readonly array $fields = [],
        public readonly int $copies = 1,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            document: $data['document'] ?? 'test',
            title: $data['title'] ?? null,
            lines: array_values(array_map('strval', $data['lines'] ?? [])),
            fields: $data['fields'] ?? [],
            copies: max(1, (int) ($data['copies'] ?? 1)),
        );
    }

    public function field(string $key, mixed $default = null): mixed
    {
        return $this->fields[$key] ?? $default;
    }

    public function toArray(): array
    {
        return [
            'document' => $this->document,
            'title' => $this->title,
            'lines' => $this->lines,
            'fields' => $this->fields,
            'copies' => $this->copies,
        ];
    }
}
