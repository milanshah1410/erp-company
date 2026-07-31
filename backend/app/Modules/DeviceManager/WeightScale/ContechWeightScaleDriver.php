<?php

declare(strict_types=1);

namespace App\Modules\DeviceManager\WeightScale;

use App\Modules\DeviceManager\Data\WeightReading;

/**
 * Contech scales (CI-201, CH and TS series) — very common on Indian counters.
 *
 * Simple signed fixed-width frame with a real decimal point and no stability
 * flag at all:
 *
 *     +0012.345 kg<CR><LF>
 *
 * With no flag to read, the adapter cannot claim stability it does not know
 * about, so it samples twice and only reports `stable` when both frames agree.
 */
class ContechWeightScaleDriver extends AbstractWeightScaleDriver
{
    public function parseFrame(string $frame): WeightReading
    {
        $clean = trim($frame);

        if (! preg_match('/([+-]?)\s*(\d+\.?\d*)\s*([a-zA-Z]{1,2})?/', $clean, $m)) {
            $this->fail($frame);
        }

        $weight = (float) $m[2];
        if (($m[1] ?? '') === '-') {
            $weight = -$weight;
        }

        return new WeightReading(
            weight: round($weight, 3),
            unit: ($m[3] ?? '') !== '' ? strtolower($m[3]) : $this->unit(),
            stable: true,   // refined by read() below, which cross-checks two samples
            tare: null,
            raw: $clean,
        );
    }

    /**
     * Two samples, ~120 ms apart: equal means settled. Without a stability bit
     * this is the only honest way to know the pan has stopped moving.
     */
    public function read(): WeightReading
    {
        $first = parent::read();

        if ($this->simulated()) {
            return $first;
        }

        usleep(120_000);
        $second = parent::read();

        return new WeightReading(
            weight: $second->weight,
            unit: $second->unit,
            stable: abs($second->weight - $first->weight) < 0.0005,
            tare: $second->tare,
            raw: $second->raw,
        );
    }

    protected function buildFrame(float $weight, bool $stable): string
    {
        // An unstable simulation wobbles the last digit, exactly as the pan would.
        $value = $stable ? $weight : $weight + (random_int(1, 9) / 1000);

        return sprintf("%s%08.3f %s\r\n", $weight < 0 ? '-' : '+', abs($value), $this->unit());
    }

    public function simulateRead(?float $weight = null, bool $stable = true): WeightReading
    {
        $reading = parent::simulateRead($weight, $stable);

        // parseFrame() cannot see stability in this format, so carry the
        // caller's intent through — an unstable simulation must read unstable.
        return new WeightReading(
            weight: $reading->weight,
            unit: $reading->unit,
            stable: $stable,
            tare: $reading->tare,
            raw: $reading->raw,
            simulated: true,
        );
    }
}
