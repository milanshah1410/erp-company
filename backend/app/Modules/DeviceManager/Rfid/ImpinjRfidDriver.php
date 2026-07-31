<?php

declare(strict_types=1);

namespace App\Modules\DeviceManager\Rfid;

use App\Modules\DeviceManager\Data\ConnectionResult;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Impinj Speedway readers (R420, R700).
 *
 * The IoT interface streams `tag_reads` rows using snake_case-ish keys —
 * `epc`, `antennaPort`, `peakRssi` — and reports RSSI in centi-dBm on some
 * firmware builds, which is normalised here so the shared RSSI floor means the
 * same thing across brands.
 */
class ImpinjRfidDriver extends AbstractRfidDriver
{
    protected function reportRows(array $payload): array
    {
        return $payload['tag_reads'] ?? $payload['tagReads'] ?? [];
    }

    protected function mapRow(array $row): ?array
    {
        $epc = (string) ($row['epc'] ?? '');
        if ($epc === '') {
            return null;
        }

        $rssi = isset($row['peakRssi']) ? (float) $row['peakRssi'] : null;
        // Values below -200 are centi-dBm (e.g. -6350 = -63.5 dBm).
        if ($rssi !== null && $rssi < -200) {
            $rssi = round($rssi / 100, 1);
        }

        return [
            'epc' => $epc,
            'antenna' => isset($row['antennaPort']) ? (int) $row['antennaPort'] : null,
            'rssi' => $rssi,
            'count' => max(1, (int) ($row['tagSeenCount'] ?? $row['readCount'] ?? 1)),
            'tid' => $row['tid'] ?? null,
        ];
    }

    protected function buildReport(array $rows): array
    {
        return [
            'tag_reads' => array_map(fn (array $r) => [
                'epc' => $r['epc'],
                'antennaPort' => $r['antenna'],
                // Emit centi-dBm so the simulator exercises the normalisation above.
                'peakRssi' => (int) round(((float) $r['rssi']) * 100),
                'readCount' => $r['count'],
            ], $rows),
        ];
    }

    protected function fetchReport(int $durationMs): array
    {
        $base = $this->baseUrl();

        $response = $this->http()->post("{$base}/api/v1/profiles/inventory/presets/default/start", [
            'duration_ms' => $durationMs,
            'antennas' => $this->antennaPorts(),
            'transmitPower' => (int) $this->setting('power_dbm', 30),
        ]);

        if ($response->failed()) {
            return [];
        }

        $stream = $this->http()->get("{$base}/api/v1/data/stream", ['duration_ms' => $durationMs]);

        return (array) ($stream->json() ?: []);
    }

    public function testConnection(): ConnectionResult
    {
        if ($this->simulated()) {
            return ConnectionResult::ok(
                'Impinj simulator ready — virtual tag reads available.',
                ['mode' => 'simulator', 'antennas' => $this->antennaPorts()],
                latencyMs: 0,
                simulated: true,
            );
        }

        $startedAt = microtime(true);

        try {
            $response = $this->http()->get($this->baseUrl() . '/api/v1/system');

            if ($response->failed()) {
                return ConnectionResult::fail("Speedway answered HTTP {$response->status()}.");
            }

            $body = (array) $response->json();

            return ConnectionResult::ok(
                'Impinj reader reachable.',
                [
                    'host' => $this->setting('reader_host'),
                    'model' => $body['model'] ?? null,
                    'firmware' => $body['softwareVersion'] ?? $body['version'] ?? null,
                ],
                latencyMs: (int) round((microtime(true) - $startedAt) * 1000),
            );
        } catch (Throwable $e) {
            return ConnectionResult::fail($e->getMessage());
        }
    }

    private function baseUrl(): string
    {
        $host = rtrim($this->requireSetting('reader_host'), '/');

        return str_starts_with($host, 'http') ? $host : "http://{$host}";
    }

    private function http()
    {
        $token = $this->credential('api_token');

        return Http::acceptJson()->asJson()->timeout(15)
            ->when($token !== null, fn ($client) => $client->withToken($token));
    }
}
