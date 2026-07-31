<?php

declare(strict_types=1);

namespace App\Modules\DeviceManager\Contracts;

use App\Modules\DeviceManager\Data\TagRead;

/** UHF RFID readers (gate, packing-table and handheld inventory readers). */
interface RfidReaderDriver extends DeviceDriver
{
    /**
     * Run one inventory round and return every tag seen, de-duplicated by EPC
     * with the strongest RSSI kept.
     *
     * @return TagRead[]
     */
    public function inventory(int $durationMs = 1000): array;

    /**
     * Parse the brand's tag-report payload.
     *
     * @return TagRead[]
     */
    public function parseReport(array $payload): array;

    /**
     * Fake a set of tag reads — the Simulator's virtual gate pass.
     *
     * @param  string[]|null  $epcs
     * @return TagRead[]
     */
    public function simulateInventory(?array $epcs = null, int $count = 3): array;
}
