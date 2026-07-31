<?php

declare(strict_types=1);

namespace App\Modules\DeviceManager\Rfid;

use App\Modules\DeviceManager\Contracts\RfidReaderDriver;
use App\Modules\DeviceManager\Data\TagRead;
use App\Modules\DeviceManager\Support\AbstractDeviceDriver;

/**
 * Shared RFID behaviour: the inventory round, the RSSI floor, and EPC
 * de-duplication.
 *
 * De-duplication is not cosmetic — one carton passing a gate produces dozens of
 * reads of the same EPC, and a stock count that trusted the raw stream would
 * multiply every item. The strongest read per EPC wins and its sightings are
 * summed into `readCount`.
 */
abstract class AbstractRfidDriver extends AbstractDeviceDriver implements RfidReaderDriver
{
    public function type(): string
    {
        return 'rfid';
    }

    /** Where the reader's report lives in its response, and under which keys. */
    abstract protected function reportRows(array $payload): array;

    /** Map one brand row onto the normalised shape. @return array{epc:string,antenna:?int,rssi:?float,count:int,tid:?string}|null */
    abstract protected function mapRow(array $row): ?array;

    /** Wrap simulated rows the way this brand's reader reports them. */
    abstract protected function buildReport(array $rows): array;

    /** @return TagRead[] */
    public function inventory(int $durationMs = 1000): array
    {
        if ($this->simulated()) {
            return $this->simulateInventory(count: 3);
        }

        $payload = $this->fetchReport($durationMs);

        return $this->parseReport($payload);
    }

    /** @return TagRead[] */
    public function parseReport(array $payload): array
    {
        $floor = (float) $this->setting('rssi_floor', -75);
        $best = [];

        foreach ($this->reportRows($payload) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $mapped = $this->mapRow($row);
            if ($mapped === null || $mapped['epc'] === '') {
                continue;
            }
            // Weak reads are reflections and neighbouring stock, not this pass.
            if ($mapped['rssi'] !== null && $mapped['rssi'] < $floor) {
                continue;
            }

            $epc = strtoupper($mapped['epc']);
            $existing = $best[$epc] ?? null;

            if ($existing === null) {
                $best[$epc] = $mapped;

                continue;
            }

            $best[$epc] = [
                'epc' => $epc,
                'antenna' => ($mapped['rssi'] ?? -999) > ($existing['rssi'] ?? -999) ? $mapped['antenna'] : $existing['antenna'],
                'rssi' => max($mapped['rssi'] ?? -999, $existing['rssi'] ?? -999),
                'count' => $existing['count'] + $mapped['count'],
                'tid' => $existing['tid'] ?? $mapped['tid'],
            ];
        }

        return array_values(array_map(
            fn (array $t) => new TagRead(
                epc: strtoupper($t['epc']),
                antenna: $t['antenna'],
                rssi: $t['rssi'],
                readCount: $t['count'],
                tid: $t['tid'],
            ),
            $best,
        ));
    }

    /** @return TagRead[] */
    public function simulateInventory(?array $epcs = null, int $count = 3): array
    {
        $epcs = $epcs !== null && $epcs !== []
            ? array_values(array_filter(array_map('trim', $epcs)))
            : $this->randomEpcs(max(1, $count));

        $ports = $this->antennaPorts();
        $rows = [];

        foreach ($epcs as $i => $epc) {
            $rows[] = [
                'epc' => strtoupper($epc),
                'antenna' => $ports[$i % count($ports)],
                // Comfortably above the floor, with per-tag variation.
                'rssi' => round((float) $this->setting('rssi_floor', -75) + 8 + random_int(0, 2000) / 100, 1),
                'count' => random_int(2, 18),
                'tid' => null,
            ];
        }

        $reads = $this->parseReport($this->buildReport($rows));

        return array_map(
            fn (TagRead $t) => new TagRead($t->epc, $t->antenna, $t->rssi, $t->readCount, $t->tid, simulated: true),
            $reads,
        );
    }

    /** Configured antenna ports. @return int[] */
    protected function antennaPorts(): array
    {
        $raw = (string) $this->setting('antennas', '1');
        $ports = array_values(array_filter(array_map('intval', explode(',', $raw))));

        return $ports !== [] ? $ports : [1];
    }

    /**
     * SGTIN-96-shaped EPCs, so simulated reads look like the tags a
     * product/variant `rfid_tag` field actually holds.
     *
     * @return string[]
     */
    protected function randomEpcs(int $count): array
    {
        $epcs = [];
        for ($i = 0; $i < $count; $i++) {
            $epcs[] = strtoupper('3034' . bin2hex(random_bytes(10)));
        }

        return $epcs;
    }

    /** Pull the reader's report. Brands with a real endpoint override this. */
    protected function fetchReport(int $durationMs): array
    {
        return $this->agent()->send($this->device(), 'rfid.inventory', [
            'duration_ms' => $durationMs,
            'antennas' => $this->antennaPorts(),
        ]);
    }
}
