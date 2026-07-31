<?php

declare(strict_types=1);

namespace App\Modules\DeviceManager\Data;

/** One RFID tag observation from a reader inventory round. */
class TagRead
{
    public function __construct(
        public readonly string $epc,
        public readonly ?int $antenna = null,
        public readonly ?float $rssi = null,
        public readonly int $readCount = 1,
        public readonly ?string $tid = null,
        public readonly bool $simulated = false,
    ) {}

    public function toArray(): array
    {
        return [
            'epc' => $this->epc,
            'antenna' => $this->antenna,
            'rssi' => $this->rssi,
            'read_count' => $this->readCount,
            'tid' => $this->tid,
            'simulated' => $this->simulated,
        ];
    }
}
