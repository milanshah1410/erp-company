<?php

declare(strict_types=1);

namespace App\Modules\DeviceManager\Support;

use App\Models\Device;
use App\Modules\DeviceManager\Contracts\DeviceDriver;
use App\Modules\DeviceManager\Data\ConnectionResult;
use RuntimeException;
use Throwable;

/**
 * Shared plumbing for every brand adapter: settings/credential access, the
 * agent transport, and the simulator short-circuit.
 *
 * Subclasses only implement what is genuinely brand-specific — the frame
 * layout, the page language, the field names in the punch log — which is what
 * keeps a new brand a ~60-line file rather than a new integration.
 */
abstract class AbstractDeviceDriver implements DeviceDriver
{
    public function __construct(
        protected readonly Device $model,
        protected readonly DeviceAgentClient $agent,
    ) {}

    public function device(): Device
    {
        return $this->model;
    }

    public function key(): string
    {
        return $this->model->driver;
    }

    /** Human label for messages, taken from the registry. */
    protected function brand(): string
    {
        return DeviceRegistry::driver($this->type(), $this->key())['label'] ?? $this->key();
    }

    protected function simulated(): bool
    {
        return $this->model->isSimulated();
    }

    protected function setting(string $key, mixed $default = null): mixed
    {
        return $this->model->setting($key, $default);
    }

    protected function credential(string $key): ?string
    {
        return $this->model->credential($key);
    }

    /** Fail loudly and specifically when a required option was left blank. */
    protected function requireSetting(string $key): string
    {
        $value = (string) $this->setting($key, '');
        if ($value === '') {
            throw new RuntimeException("{$this->brand()} needs \"{$key}\" configured before it can be used.");
        }

        return $value;
    }

    protected function agent(): DeviceAgentClient
    {
        return $this->agent;
    }

    /**
     * Default Test Connection: instant success in simulator mode, an agent
     * health probe otherwise. Adapters that can ask the hardware something more
     * meaningful (firmware, model, paper status) override this.
     */
    public function testConnection(): ConnectionResult
    {
        if ($this->simulated()) {
            return ConnectionResult::ok(
                "{$this->brand()} simulator ready — no hardware required.",
                ['mode' => 'simulator', 'driver' => $this->key()],
                latencyMs: 0,
                simulated: true,
            );
        }

        $startedAt = microtime(true);

        try {
            $health = $this->agent->health($this->model);
        } catch (Throwable $e) {
            return ConnectionResult::fail($e->getMessage(), ['driver' => $this->key()]);
        }

        return ConnectionResult::ok(
            "{$this->brand()} reachable via the device agent.",
            array_intersect_key($health, array_flip(['agent', 'version', 'port', 'model', 'firmware', 'status'])),
            latencyMs: (int) round((microtime(true) - $startedAt) * 1000),
        );
    }
}
