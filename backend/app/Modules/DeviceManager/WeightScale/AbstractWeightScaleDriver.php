<?php

declare(strict_types=1);

namespace App\Modules\DeviceManager\WeightScale;

use App\Modules\DeviceManager\Contracts\WeightScaleDriver;
use App\Modules\DeviceManager\Data\WeightReading;
use App\Modules\DeviceManager\Support\AbstractDeviceDriver;
use RuntimeException;

/**
 * Shared scale behaviour.
 *
 * The live path asks the agent for one raw frame and hands it to the brand's
 * `parseFrame()`. The simulator builds a frame in the brand's own layout with
 * `buildFrame()` and parses that — so the parser, not a shortcut, produces the
 * fake reading. A malformed brand frame therefore fails in the simulator too,
 * which is the whole point of testing without hardware.
 */
abstract class AbstractWeightScaleDriver extends AbstractDeviceDriver implements WeightScaleDriver
{
    public function type(): string
    {
        return 'weight_scale';
    }

    /** Render a weight the way this brand's indicator would transmit it. */
    abstract protected function buildFrame(float $weight, bool $stable): string;

    public function read(): WeightReading
    {
        if ($this->simulated()) {
            return $this->simulateRead();
        }

        $response = $this->agent()->send($this->device(), 'scale.read', [
            'com_port' => $this->setting('com_port'),
            'baud_rate' => $this->setting('baud_rate'),
        ]);

        $frame = $response['frame'] ?? $response['data'] ?? null;
        if ($frame === null || $frame === '') {
            throw new RuntimeException("{$this->brand()} sent no weight frame. Check the cable and baud rate.");
        }

        return $this->parseFrame((string) $frame);
    }

    public function tare(): WeightReading
    {
        if ($this->simulated()) {
            // A tare zeroes the pan: report a stable zero, as the indicator would.
            return $this->parseFrame($this->buildFrame(0.0, true));
        }

        $this->agent()->send($this->device(), 'scale.tare', [
            'com_port' => $this->setting('com_port'),
        ]);

        return $this->read();
    }

    public function simulateRead(?float $weight = null, bool $stable = true): WeightReading
    {
        $weight = $weight ?? $this->plausibleWeight();
        $reading = $this->parseFrame($this->buildFrame($weight, $stable));

        return new WeightReading(
            weight: $reading->weight,
            unit: $reading->unit,
            stable: $reading->stable,
            tare: $reading->tare,
            raw: $reading->raw,
            simulated: true,
        );
    }

    /** A believable weight for the configured capacity. */
    protected function plausibleWeight(): float
    {
        $capacity = max(1.0, (float) $this->setting('capacity', 30));
        $raw = random_int(1, (int) round($capacity * 900)) / 1000;

        return round(min($raw, $capacity), 3);
    }

    protected function unit(): string
    {
        return (string) $this->setting('unit', 'kg');
    }

    /** Guard against a frame the brand parser could not make sense of. */
    protected function fail(string $frame): never
    {
        throw new RuntimeException(sprintf(
            '%s frame not understood: "%s".',
            $this->brand(),
            addcslashes(mb_substr($frame, 0, 40), "\0..\37"),
        ));
    }
}
