<?php

declare(strict_types=1);

namespace App\Modules\DeviceManager\Data;

/** Outcome of a "Test Connection" against one device. */
class ConnectionResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly string $message,
        /** @var array<string,mixed> Firmware/model/port details worth showing. */
        public readonly array $details = [],
        public readonly ?int $latencyMs = null,
        public readonly bool $simulated = false,
    ) {}

    public static function ok(string $message, array $details = [], ?int $latencyMs = null, bool $simulated = false): self
    {
        return new self(true, $message, $details, $latencyMs, $simulated);
    }

    public static function fail(string $message, array $details = [], bool $simulated = false): self
    {
        return new self(false, $message, $details, null, $simulated);
    }

    public function toArray(): array
    {
        return [
            'ok' => $this->ok,
            'message' => $this->message,
            'details' => $this->details,
            'latency_ms' => $this->latencyMs,
            'simulated' => $this->simulated,
        ];
    }
}
