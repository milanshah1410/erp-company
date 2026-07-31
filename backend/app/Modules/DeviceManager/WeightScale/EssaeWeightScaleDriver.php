<?php

declare(strict_types=1);

namespace App\Modules\DeviceManager\WeightScale;

use App\Modules\DeviceManager\Data\WeightReading;

/**
 * Essae-Teraoka scales (DS-215, DS-852, SI-810).
 *
 * Continuous output in the Toledo-style format these indicators use:
 *
 *     ST,GS,+  12.345kg<CR><LF>
 *     └┬ └┬  └───┬───┘└┬┘
 *      │   │      │     unit
 *      │   │      signed weight
 *      │   GS = gross, NT = net
 *      ST = stable, US = unstable
 *
 * The ST/US flag is why this brand is safe for billing by weight: an unstable
 * reading is reported as such and callers refuse to commit it.
 */
class EssaeWeightScaleDriver extends AbstractWeightScaleDriver
{
    public function parseFrame(string $frame): WeightReading
    {
        $frame = trim($frame);

        if (! preg_match('/^(ST|US|OL)\s*,\s*(GS|NT)\s*,\s*([+-]?\s*[0-9]*\.?[0-9]+)\s*([a-zA-Z]*)/', $frame, $m)) {
            $this->fail($frame);
        }

        [, $stability, $mode, $value, $unit] = $m;

        if ($stability === 'OL') {
            $this->fail($frame . ' (overload)');
        }

        $weight = (float) str_replace(' ', '', $value);

        return new WeightReading(
            weight: round($weight, 3),
            unit: $unit !== '' ? strtolower($unit) : $this->unit(),
            stable: $stability === 'ST',
            // A net frame means the indicator already subtracted the tare.
            tare: $mode === 'NT' ? 0.0 : null,
            raw: $frame,
        );
    }

    protected function buildFrame(float $weight, bool $stable): string
    {
        return sprintf(
            "%s,GS,%s%7.3f%s\r\n",
            $stable ? 'ST' : 'US',
            $weight < 0 ? '-' : '+',
            abs($weight),
            $this->unit(),
        );
    }
}
