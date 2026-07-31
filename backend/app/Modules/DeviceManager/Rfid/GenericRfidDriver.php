<?php

declare(strict_types=1);

namespace App\Modules\DeviceManager\Rfid;

/**
 * Any reader the Device Agent can drive.
 *
 * The agent is expected to normalise on its side and return
 * `{tags: [{epc, antenna, rssi, count}]}` — the escape hatch for readers that do
 * not have their own adapter yet.
 */
class GenericRfidDriver extends AbstractRfidDriver
{
    protected function reportRows(array $payload): array
    {
        return $payload['tags'] ?? $payload['data'] ?? [];
    }

    protected function mapRow(array $row): ?array
    {
        $epc = (string) ($row['epc'] ?? '');
        if ($epc === '') {
            return null;
        }

        return [
            'epc' => $epc,
            'antenna' => isset($row['antenna']) ? (int) $row['antenna'] : null,
            'rssi' => isset($row['rssi']) ? (float) $row['rssi'] : null,
            'count' => max(1, (int) ($row['count'] ?? 1)),
            'tid' => $row['tid'] ?? null,
        ];
    }

    protected function buildReport(array $rows): array
    {
        return ['tags' => $rows];
    }
}
