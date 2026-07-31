<?php

declare(strict_types=1);

namespace App\Modules\DeviceManager\Rfid;

use App\Modules\DeviceManager\Data\ConnectionResult;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Zebra FX readers (FX7500, FX9600, RFD40 handheld).
 *
 * Driven through the reader's own REST/IoT connector rather than the local agent —
 * these are network devices, so the server reaches them directly. Reports come
 * back as `tagList` rows keyed `epc`, `antennaID`, `peakRssi`, `tagSeenCount`.
 */
class ZebraRfidDriver extends AbstractRfidDriver
{
    protected function reportRows(array $payload): array
    {
        return $payload['tagList'] ?? $payload['tags'] ?? [];
    }

    protected function mapRow(array $row): ?array
    {
        $epc = (string) ($row['epc'] ?? $row['EPC'] ?? '');
        if ($epc === '') {
            return null;
        }

        return [
            'epc' => $epc,
            'antenna' => isset($row['antennaID']) ? (int) $row['antennaID'] : null,
            'rssi' => isset($row['peakRssi']) ? (float) $row['peakRssi'] : null,
            'count' => max(1, (int) ($row['tagSeenCount'] ?? 1)),
            'tid' => $row['tid'] ?? null,
        ];
    }

    protected function buildReport(array $rows): array
    {
        return [
            'tagList' => array_map(fn (array $r) => [
                'epc' => $r['epc'],
                'antennaID' => $r['antenna'],
                'peakRssi' => $r['rssi'],
                'tagSeenCount' => $r['count'],
            ], $rows),
        ];
    }

    protected function fetchReport(int $durationMs): array
    {
        $host = rtrim($this->requireSetting('reader_host'), '/');
        $base = str_starts_with($host, 'http') ? $host : "http://{$host}";

        $response = $this->http()->post("{$base}/cloud/inventory", [
            'duration_ms' => $durationMs,
            'antennas' => $this->antennaPorts(),
            'transmitPower' => (int) $this->setting('power_dbm', 27),
        ]);

        return $response->failed() ? [] : (array) $response->json();
    }

    public function testConnection(): ConnectionResult
    {
        if ($this->simulated()) {
            return ConnectionResult::ok(
                'Zebra FX simulator ready — virtual tag reads available.',
                ['mode' => 'simulator', 'antennas' => $this->antennaPorts()],
                latencyMs: 0,
                simulated: true,
            );
        }

        $startedAt = microtime(true);

        try {
            $host = rtrim($this->requireSetting('reader_host'), '/');
            $base = str_starts_with($host, 'http') ? $host : "http://{$host}";
            $response = $this->http()->get("{$base}/cloud/version");

            if ($response->failed()) {
                return ConnectionResult::fail("Reader answered HTTP {$response->status()} at {$base}.");
            }

            $body = (array) $response->json();

            return ConnectionResult::ok(
                'Zebra FX reader reachable.',
                [
                    'host' => $host,
                    'firmware' => $body['version'] ?? $body['firmware'] ?? null,
                    'antennas' => $this->antennaPorts(),
                ],
                latencyMs: (int) round((microtime(true) - $startedAt) * 1000),
            );
        } catch (Throwable $e) {
            return ConnectionResult::fail($e->getMessage());
        }
    }

    protected function http()
    {
        $token = $this->credential('api_token');

        return Http::acceptJson()->asJson()->timeout(15)
            ->when($token !== null, fn ($client) => $client->withToken($token));
    }
}
