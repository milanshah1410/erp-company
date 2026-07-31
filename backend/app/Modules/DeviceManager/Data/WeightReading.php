<?php

declare(strict_types=1);

namespace App\Modules\DeviceManager\Data;

/**
 * A normalised weight reading. `stable` matters commercially: an unstable
 * reading must never be committed to a weighment slip, so callers check it
 * before writing the value onto a document.
 */
class WeightReading
{
    public function __construct(
        public readonly float $weight,
        public readonly string $unit = 'kg',
        public readonly bool $stable = true,
        public readonly ?float $tare = null,
        public readonly ?string $raw = null,
        public readonly bool $simulated = false,
    ) {}

    /** Weight net of the tare, when the scale reported one. */
    public function net(): float
    {
        return round($this->weight - (float) $this->tare, 3);
    }

    public function toArray(): array
    {
        return [
            'weight' => $this->weight,
            'net' => $this->net(),
            'unit' => $this->unit,
            'stable' => $this->stable,
            'tare' => $this->tare,
            'raw' => $this->raw,
            'simulated' => $this->simulated,
        ];
    }
}
