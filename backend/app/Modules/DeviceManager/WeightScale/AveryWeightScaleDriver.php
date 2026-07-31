<?php

declare(strict_types=1);

namespace App\Modules\DeviceManager\WeightScale;

use App\Modules\DeviceManager\Data\WeightReading;

/**
 * Avery Weigh-Tronix indicators (ZM201, ZM303) — typically the weighbridge head.
 *
 * The ZM series' default transmit string is fixed-width and puts its flags at the
 * end rather than the start:
 *
 *     <STX>+0012345kg G S<CR><LF>
 *          │└──┬───┘└┬┘ │ └ stability: S = stable, M = motion
 *          │   │     unit
 *          │   weight, no decimal point — scaled by the division count
 *          sign                                    G = gross, N = net
 *
 * Because the decimal point is implied, the divisor comes from the configured
 * decimals — get that wrong and a 12.345 t load reads as 12345 t, so it is
 * deliberately explicit rather than guessed.
 */
class AveryWeightScaleDriver extends AbstractWeightScaleDriver
{
    private const STX = "\x02";

    public function parseFrame(string $frame): WeightReading
    {
        $clean = trim(str_replace([self::STX, "\x03"], '', $frame));

        if (! preg_match('/^([+-])\s*(\d+(?:\.\d+)?)\s*([a-zA-Z]{1,2})?\s*([GN])?\s*([SM])?/', $clean, $m)) {
            $this->fail($frame);
        }

        // Trailing groups are optional, so backfill the ones PCRE left out.
        [, $sign, $digits, $unit, $mode, $stability] = $m + [3 => '', 4 => '', 5 => ''];

        // No decimal point in the wire format → apply the configured scale.
        $weight = str_contains($digits, '.')
            ? (float) $digits
            : (float) $digits / (10 ** (int) $this->setting('decimals', 3));

        if ($sign === '-') {
            $weight = -$weight;
        }

        return new WeightReading(
            weight: round($weight, 3),
            unit: $unit !== '' ? strtolower($unit) : $this->unit(),
            // Absent flag means the indicator is not configured to send motion
            // status; treat that as stable rather than blocking every weighment.
            stable: $stability !== 'M',
            tare: $mode === 'N' ? 0.0 : null,
            raw: $clean,
        );
    }

    protected function buildFrame(float $weight, bool $stable): string
    {
        $decimals = (int) $this->setting('decimals', 3);
        $scaled = (int) round(abs($weight) * (10 ** $decimals));

        return sprintf(
            "%s%s%07d%s G %s\r\n",
            self::STX,
            $weight < 0 ? '-' : '+',
            $scaled,
            $this->unit(),
            $stable ? 'S' : 'M',
        );
    }
}
