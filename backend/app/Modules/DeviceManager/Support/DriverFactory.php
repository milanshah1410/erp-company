<?php

declare(strict_types=1);

namespace App\Modules\DeviceManager\Support;

use App\Models\Device;
use App\Modules\DeviceManager\Contracts\BarcodeScannerDriver;
use App\Modules\DeviceManager\Contracts\BiometricDriver;
use App\Modules\DeviceManager\Contracts\DeviceDriver;
use App\Modules\DeviceManager\Contracts\PrinterDriver;
use App\Modules\DeviceManager\Contracts\RfidReaderDriver;
use App\Modules\DeviceManager\Contracts\WeightScaleDriver;
use InvalidArgumentException;

/**
 * Resolves the brand adapter for a configured device.
 *
 * The typed helpers exist so callers get a real contract back instead of the
 * base interface — a printer endpoint that accidentally received a scale is a
 * clear exception here rather than a "method not found" three frames deeper.
 */
class DriverFactory
{
    public function __construct(private readonly DeviceAgentClient $agent) {}

    public function make(Device $device): DeviceDriver
    {
        $meta = DeviceRegistry::driver($device->type, $device->driver);
        if (! $meta) {
            throw new InvalidArgumentException(
                "No adapter registered for {$device->type} / {$device->driver}."
            );
        }

        /** @var class-string<DeviceDriver> $class */
        $class = $meta['class'];

        return new $class($device, $this->agent);
    }

    public function barcode(Device $device): BarcodeScannerDriver
    {
        return $this->expect($device, BarcodeScannerDriver::class, 'barcode scanner');
    }

    public function printer(Device $device): PrinterDriver
    {
        return $this->expect($device, PrinterDriver::class, 'printer');
    }

    public function scale(Device $device): WeightScaleDriver
    {
        return $this->expect($device, WeightScaleDriver::class, 'weighing scale');
    }

    public function rfid(Device $device): RfidReaderDriver
    {
        return $this->expect($device, RfidReaderDriver::class, 'RFID reader');
    }

    public function biometric(Device $device): BiometricDriver
    {
        return $this->expect($device, BiometricDriver::class, 'biometric terminal');
    }

    /**
     * @template T of DeviceDriver
     *
     * @param  class-string<T>  $contract
     * @return T
     */
    private function expect(Device $device, string $contract, string $noun): DeviceDriver
    {
        $driver = $this->make($device);
        if (! $driver instanceof $contract) {
            throw new InvalidArgumentException("{$device->name} is not a {$noun}.");
        }

        return $driver;
    }
}
