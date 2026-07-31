<?php

declare(strict_types=1);

namespace App\Modules\DeviceManager\Rfid;

use App\Modules\DeviceManager\Data\ConnectionResult;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Honeywell IF-series readers (IF2, IF61) and the IH45 handheld.
 *
 * Basic reading mode returns terse rows — `id`, `ant`, `rssi` — and reports the
 * EPC with spaces every four hex characters, which has to be squeezed out or
 * every lookup against a stored `rfid_tag` misses.
 */
class HoneywellRfidDriver extends AbstractRfidDriver
{
    protected function reportRows(array $payload): array
    {
        return $payload['tags'] ?? $payload['tagReport'] ?? [];
    }

    protected function mapRow(array $row): ?array
    {
        $epc = (string) ($row['id'] ?? $row['epc'] ?? '');
        $epc = str_replace([' ', '-', ':'], '', $epc);
        if ($epc === '') {
            return null;
        }

        return [
            'epc' => $epc,
            'antenna' => isset($row['ant']) ? (int) $row['ant'] : null,
            'rssi' => isset($row['rssi']) ? (float) $row['rssi'] : null,
            'count' => max(1, (int) ($row['count'] ?? 1)),
            'tid' => $row['tid'] ?? null,
        ];
    }

    protected function buildReport(array $rows): array
    {
        return [
            'tags' => array_map(fn (array $r) => [
                // Grouped hex, as the IF series prints it — exercises the squeeze.
                'id' => trim(chunk_split($r['epc'], 4, ' ')),
                'ant' => $r['antenna'],
                'rssi' => $r['rssi'],
                'count' => $r['count'],
            ], $rows),
        ];
    }

    protected function fetchReport(int $durationMs): array
    {
        $response = $this->http()->post($this->baseUrl() . '/rfid/inventory', [
            'duration' => $durationMs,
            'antennas' => $this->antennaPorts(),
        ]);

        return $response->failed() ? [] : (array) $response->json();
    }

    public function testConnection(): ConnectionResult
    {
        if ($this->simulated()) {
            return ConnectionResult::ok(
                'Honeywell IF simulator ready — virtual tag reads available.',
                ['mode' => 'simulator'],
                latencyMs: 0,
                simulated: true,
            );
        }

        $startedAt = microtime(true);

        try {
            $response = $this->http()->get($this->baseUrl() . '/rfid/status');
            if ($response->failed()) {
                return ConnectionResult::fail("Reader answered HTTP {$response->status()}.");
            }

            return ConnectionResult::ok(
                'Honeywell reader reachable.',
                ['host' => $this->setting('reader_host')] + (array) $response->json(),
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
