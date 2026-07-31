<?php

declare(strict_types=1);

namespace App\Modules\DeviceManager\Data;

/**
 * Outcome of a print. `preview` is the compiled page-language payload in
 * printable-text form — it is what simulator mode returns instead of sending
 * bytes anywhere, which makes label templates reviewable (and diffable in
 * tests) without a printer attached.
 */
class PrintResult
{
    public function __construct(
        public readonly bool $ok,
        /** escpos | zpl | tspl | html */
        public readonly string $language,
        public readonly string $preview,
        public readonly int $bytes = 0,
        public readonly int $copies = 1,
        public readonly ?string $message = null,
        public readonly bool $simulated = false,
    ) {}

    public function toArray(): array
    {
        return [
            'ok' => $this->ok,
            'language' => $this->language,
            'preview' => $this->preview,
            'bytes' => $this->bytes,
            'copies' => $this->copies,
            'message' => $this->message,
            'simulated' => $this->simulated,
        ];
    }
}
