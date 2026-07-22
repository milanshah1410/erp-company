<?php

namespace App\Http\Controllers\Central\Ops;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Ops panel — live service health + static system information (SaaS-owner).
 * Read-only: ops-health.view / ops-system.view.
 */
class SystemHealthController extends Controller
{
    /** Probe each infrastructure dependency and report per-service status. */
    public function health()
    {
        $checks = [
            $this->checkDatabase(),
            $this->checkCache(),
            $this->checkStorage(),
            $this->checkQueue(),
        ];

        if (config('scout.driver') === 'meilisearch') {
            $checks[] = $this->checkMeilisearch();
        }

        $statuses = array_column($checks, 'status');
        $overall = in_array('down', $statuses, true) ? 'down'
            : (in_array('warn', $statuses, true) ? 'warn' : 'ok');

        return response()->json([
            'data' => [
                'overall' => $overall,
                'checked_at' => now()->toDateTimeString(),
                'checks' => $checks,
            ],
        ]);
    }

    /** Static environment/runtime facts for the Ops "system" card. */
    public function system()
    {
        $dbVersion = null;
        try {
            $dbVersion = DB::connection()->getPdo()->getAttribute(\PDO::ATTR_SERVER_VERSION);
        } catch (\Throwable) {
            // Reported as null; the health endpoint surfaces DB outages.
        }

        $path = base_path();
        $diskTotal = @disk_total_space($path) ?: null;
        $diskFree = @disk_free_space($path) ?: null;

        return response()->json([
            'data' => [
                'app' => [
                    'laravel_version' => app()->version(),
                    'environment' => config('app.env'),
                    'debug' => (bool) config('app.debug'),
                    'timezone' => config('app.timezone'),
                    'url' => config('app.url'),
                ],
                'php' => [
                    'version' => PHP_VERSION,
                    'memory_limit' => ini_get('memory_limit'),
                    'upload_max_filesize' => ini_get('upload_max_filesize'),
                    'post_max_size' => ini_get('post_max_size'),
                    'max_execution_time' => ini_get('max_execution_time'),
                ],
                'drivers' => [
                    'database' => config('database.default'),
                    'database_version' => $dbVersion,
                    'cache' => config('cache.default'),
                    'queue' => config('queue.default'),
                    'session' => config('session.driver'),
                    'mail' => config('mail.default'),
                    'filesystem' => config('filesystems.default'),
                    'search' => config('scout.driver'),
                ],
                'server' => [
                    'os' => php_uname('s').' '.php_uname('r'),
                    'hostname' => php_uname('n'),
                    'disk_total_bytes' => $diskTotal,
                    'disk_free_bytes' => $diskFree,
                ],
            ],
        ]);
    }

    private function checkDatabase(): array
    {
        return $this->timed('database', 'Database connection', function () {
            DB::select('select 1');

            return null;
        });
    }

    private function checkCache(): array
    {
        return $this->timed('cache', 'Cache read/write', function () {
            $key = 'ops_health_'.Str::random(8);
            Cache::put($key, 'ok', 10);
            $value = Cache::pull($key);
            if ($value !== 'ok') {
                throw new \RuntimeException('Cache round-trip returned an unexpected value.');
            }

            return null;
        });
    }

    private function checkStorage(): array
    {
        return $this->timed('storage', 'Storage writable', function () {
            $disk = Storage::disk('local');
            $file = 'ops-health-probe-'.Str::random(8).'.txt';
            $disk->put($file, 'ok');
            $ok = $disk->get($file) === 'ok';
            $disk->delete($file);
            if (! $ok) {
                throw new \RuntimeException('Storage round-trip failed.');
            }

            return null;
        });
    }

    /** DB queue driver: backlog + failed counts. Failed jobs degrade to "warn", not "down". */
    private function checkQueue(): array
    {
        return $this->timed('queue', 'Queue backlog', function () {
            $pending = DB::table('jobs')->count();
            $failed = DB::table('failed_jobs')->count();

            return [
                'status' => $failed > 0 ? 'warn' : 'ok',
                'detail' => "{$pending} pending, {$failed} failed",
            ];
        });
    }

    private function checkMeilisearch(): array
    {
        return $this->timed('search', 'Meilisearch', function () {
            $host = rtrim((string) config('scout.meilisearch.host'), '/');
            $response = Http::timeout(2)->get($host.'/health');
            if (! $response->ok()) {
                throw new \RuntimeException('Meilisearch /health returned HTTP '.$response->status());
            }

            return ['detail' => $response->json('status') ?? 'available'];
        });
    }

    /**
     * Run a probe, measure latency, and normalize to
     * {key, label, status, latency_ms, detail}. The probe may return null (ok),
     * or an array overriding status/detail. Exceptions map to "down".
     */
    private function timed(string $key, string $label, \Closure $probe): array
    {
        $start = microtime(true);
        try {
            $result = $probe() ?? [];
            $status = $result['status'] ?? 'ok';
            $detail = $result['detail'] ?? null;
        } catch (\Throwable $e) {
            $status = 'down';
            $detail = $e->getMessage();
        }

        return [
            'key' => $key,
            'label' => $label,
            'status' => $status,
            'latency_ms' => round((microtime(true) - $start) * 1000, 1),
            'detail' => $detail,
        ];
    }
}
