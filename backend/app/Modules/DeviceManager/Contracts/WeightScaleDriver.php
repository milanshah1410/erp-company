<?php

declare(strict_types=1);

namespace App\Modules\DeviceManager\Contracts;

use App\Modules\DeviceManager\Data\WeightReading;

/**
 * Weighing scales / weighbridges.
 *
 * Every brand streams a short ASCII frame over serial (or a serial-to-TCP
 * bridge) in its own layout. `parseFrame()` isolates that difference, and the
 * simulator feeds it a brand-formatted synthetic frame — so the parser under
 * test is the same code that runs against real hardware.
 */
interface WeightScaleDriver extends DeviceDriver
{
    /** Read the current weight from the scale. */
    public function read(): WeightReading;

    /** Parse one raw brand frame into a normalised reading. */
    public function parseFrame(string $frame): WeightReading;

    /** Zero the pan / capture tare, where the protocol supports it. */
    public function tare(): WeightReading;

    /** A believable reading for this brand — the Simulator's "Fake Weight". */
    public function simulateRead(?float $weight = null, bool $stable = true): WeightReading;
}
