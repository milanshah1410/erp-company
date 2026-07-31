<?php

declare(strict_types=1);

namespace App\Modules\DeviceManager\Biometric;

use App\Modules\DeviceManager\Contracts\BiometricDriver;
use App\Modules\DeviceManager\Data\ConnectionResult;
use App\Modules\DeviceManager\Data\PunchRecord;
use App\Modules\DeviceManager\Support\AbstractDeviceDriver;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Shared biometric-terminal behaviour.
 *
 * Attendance terminals are not directly addressable from a web server: the
 * ZK/eSSL protocol runs over a raw TCP socket on the LAN. So a bridge (the
 * vendor's ADMS/eTimeTrack service, or the Device Agent) exposes the terminal's
 * attendance log as JSON and this layer normalises it.
 *
 * Simulator mode routes brand-shaped synthetic rows through the same
 * `parseLogs()`, which is what lets the punch → attendance → payroll chain be
 * tested end to end with no terminal on the wall.
 */
abstract class AbstractBiometricDriver extends AbstractDeviceDriver implements BiometricDriver
{
    public function type(): string
    {
        return 'biometric';
    }

    /** Where the log rows sit in the bridge's response. */
    abstract protected function logRows(array $payload): array;

    /** Render simulated punches in this brand's own row shape. */
    abstract protected function buildRows(array $punches): array;

    /** @return PunchRecord[] */
    public function fetchPunches(?DateTimeInterface $since = null): array
    {
        $since = $since ? Carbon::instance(Carbon::parse($since)) : now()->subDay();

        if ($this->simulated()) {
            return $this->simulatePunches();
        }

        $payload = $this->fetchLog($since);

        return $this->parseLogs($this->logRows($payload));
    }

    /** @return PunchRecord[] */
    public function simulatePunches(?array $deviceUserIds = null, int $count = 2, string $direction = 'in'): array
    {
        $ids = $deviceUserIds !== null && $deviceUserIds !== []
            ? array_values(array_filter(array_map('trim', $deviceUserIds)))
            : array_map(fn (int $i) => (string) (1000 + $i), range(1, max(1, $count)));

        $base = now()->startOfMinute();
        $punches = [];

        foreach ($ids as $i => $id) {
            $punches[] = [
                'device_user_id' => $id,
                // Spread the punches like a real shift start: a minute or two apart.
                'punched_at' => $base->copy()->subMinutes($i * 2),
                'direction' => $direction,
                'verify_mode' => 'fingerprint',
            ];
        }

        $rows = $this->buildRows($punches);

        return array_map(
            fn (PunchRecord $p) => new PunchRecord($p->deviceUserId, $p->punchedAt, $p->direction, $p->verifyMode, simulated: true),
            $this->parseLogs($rows),
        );
    }

    public function testConnection(): ConnectionResult
    {
        if ($this->simulated()) {
            return ConnectionResult::ok(
                "{$this->brand()} simulator ready — virtual fingerprint events available.",
                ['mode' => 'simulator'],
                latencyMs: 0,
                simulated: true,
            );
        }

        $bridge = (string) $this->setting('bridge_url', '');
        if ($bridge === '') {
            return ConnectionResult::fail(
                "{$this->brand()} terminals speak a LAN socket protocol, so a bridge URL (ADMS / eTimeTrack / agent) is required. "
                    . 'Set one, or switch the device to Simulator mode.',
            );
        }

        $startedAt = microtime(true);

        try {
            $response = $this->http()->get(rtrim($bridge, '/') . '/status', array_filter([
                'device' => $this->setting('device_host'),
                'serial' => $this->setting('serial_no'),
            ]));

            if ($response->failed()) {
                return ConnectionResult::fail("Bridge answered HTTP {$response->status()} at {$bridge}.");
            }

            $body = (array) $response->json();

            return ConnectionResult::ok(
                "{$this->brand()} terminal reachable through the bridge.",
                array_filter([
                    'host' => $this->setting('device_host'),
                    'port' => $this->setting('device_port'),
                    'firmware' => $body['firmware'] ?? null,
                    'users' => $body['users'] ?? null,
                    'logs' => $body['logs'] ?? null,
                ], fn ($v) => $v !== null),
                latencyMs: (int) round((microtime(true) - $startedAt) * 1000),
            );
        } catch (Throwable $e) {
            return ConnectionResult::fail($e->getMessage());
        }
    }

    /** Ask the bridge for the attendance log since a timestamp. */
    protected function fetchLog(Carbon $since): array
    {
        $bridge = (string) $this->setting('bridge_url', '');

        if ($bridge === '') {
            // No vendor bridge configured — try the Device Agent instead.
            return $this->agent()->send($this->device(), 'biometric.logs', [
                'host' => $this->setting('device_host'),
                'port' => $this->setting('device_port'),
                'comm_key' => $this->credential('comm_key'),
                'since' => $since->toDateTimeString(),
            ]);
        }

        $response = $this->http()->get(rtrim($bridge, '/') . '/attendance', array_filter([
            'device' => $this->setting('device_host'),
            'serial' => $this->setting('serial_no'),
            'from' => $since->toDateTimeString(),
        ]));

        return $response->failed() ? [] : (array) $response->json();
    }

    /** Parse a timestamp in whatever the bridge sent, or skip the row. */
    protected function parseTime(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $value);
        } catch (Throwable) {
            return null;
        }
    }

    protected function http()
    {
        $token = $this->credential('api_token');

        return Http::acceptJson()->timeout(20)
            ->when($token !== null, fn ($client) => $client->withToken($token));
    }
}
