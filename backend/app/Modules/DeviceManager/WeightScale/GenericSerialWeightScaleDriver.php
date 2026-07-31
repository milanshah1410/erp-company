<?php

declare(strict_types=1);

namespace App\Modules\DeviceManager\WeightScale;

use App\Modules\DeviceManager\Data\WeightReading;

/**
 * Last-resort adapter for any indicator that streams a number.
 *
 * Pulls the first signed decimal out of the frame and honours ST/US or S/M flags
 * if they happen to be there. Use it to get a shop running today; move to the
 * brand adapter once the exact format is known.
 */
class GenericSerialWeightScaleDriver extends AbstractWeightScaleDriver
{
    public function parseFrame(string $frame): WeightReading
    {
        $clean = trim($frame);

        if (! preg_match('/([+-]?\d+(?:\.\d+)?)/', $clean, $m)) {
            $this->fail($frame);
        }

        $unstable = (bool) preg_match('/\b(US|M)\b/', $clean);

        return new WeightReading(
            weight: round((float) $m[1], 3),
            unit: $this->unit(),
            stable: ! $unstable,
            tare: null,
            raw: $clean,
        );
    }

    protected function buildFrame(float $weight, bool $stable): string
    {
        $decimals = max(0, min(4, (int) $this->setting('decimals', 3)));

        return sprintf(
            "%s %s %s\r\n",
            number_format($weight, $decimals, '.', ''),
            $this->unit(),
            $stable ? 'ST' : 'US',
        );
    }
}
