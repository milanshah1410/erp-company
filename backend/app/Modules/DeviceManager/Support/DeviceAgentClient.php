<?php

declare(strict_types=1);

namespace App\Modules\DeviceManager\Support;

use App\Models\Device;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Transport to the local Device Agent.
 *
 * Web PHP cannot touch a USB, serial or COM port on the operator's counter, so
 * every live device path goes through a small agent process running on that
 * machine which exposes one HTTP endpoint. This client is the only place that
 * knows the wire format; brand adapters just name an action and hand over an
 * already brand-specific payload.
 *
 * Agent contract:
 *   POST {agent_url}          { device, driver, type, action, payload }  → JSON
 *   GET  {agent_url}/health                                             → JSON
 */
class DeviceAgentClient
{
    /** @return array<string,mixed> */
    public function send(Device $device, string $action, array $payload = [], int $timeout = 15): array
    {
        $url = $this->agentUrl($device);

        $response = Http::acceptJson()
            ->asJson()
            ->timeout($timeout)
            ->withHeaders($this->headers($device))
            ->post($url, [
                'device' => $device->code,
                'driver' => $device->driver,
                'type' => $device->type,
                'action' => $action,
                'payload' => $payload,
            ]);

        if ($response->failed()) {
            throw new RuntimeException(sprintf(
                'Device agent rejected "%s" for %s (HTTP %d): %s',
                $action,
                $device->code,
                $response->status(),
                mb_substr((string) $response->body(), 0, 200),
            ));
        }

        return (array) $response->json();
    }

    /**
     * Raw bytes path — printers send a compiled page-language payload, which
     * must not be JSON-mangled.
     *
     * @return array<string,mixed>
     */
    public function sendRaw(Device $device, string $action, string $bytes, array $meta = [], int $timeout = 20): array
    {
        $url = $this->agentUrl($device);

        $response = Http::acceptJson()
            ->timeout($timeout)
            ->withHeaders($this->headers($device))
            ->post($url, [
                'device' => $device->code,
                'driver' => $device->driver,
                'type' => $device->type,
                'action' => $action,
                'encoding' => 'base64',
                'payload' => base64_encode($bytes),
                'meta' => $meta,
            ]);

        if ($response->failed()) {
            throw new RuntimeException(sprintf(
                'Device agent could not run "%s" on %s (HTTP %d).',
                $action,
                $device->code,
                $response->status(),
            ));
        }

        return (array) $response->json();
    }

    /** Lightweight reachability probe used by Test Connection. */
    public function health(Device $device, int $timeout = 8): array
    {
        $url = rtrim($this->agentUrl($device), '/') . '/health';

        $response = Http::acceptJson()->timeout($timeout)
            ->withHeaders($this->headers($device))
            ->get($url, ['device' => $device->code]);

        if ($response->failed()) {
            throw new RuntimeException("Device agent is not responding at {$url} (HTTP {$response->status()}).");
        }

        return (array) $response->json();
    }

    public function agentUrl(Device $device): string
    {
        $url = (string) $device->setting('agent_url', '');
        if ($url === '') {
            throw new RuntimeException(
                "{$device->name} has no agent URL configured. Set the Device Agent URL, or switch the device to Simulator mode."
            );
        }

        return $url;
    }

    /** @return array<string,string> */
    private function headers(Device $device): array
    {
        $token = $device->credential('agent_token');

        return $token ? ['X-Agent-Token' => $token] : [];
    }
}
