<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\TenantCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Settings → System Tools → Performance (project-stack.md §17).
 *
 * A read-only diagnostics surface for the performance subsystem: which cache
 * and queue drivers are live, how deep the queue is, how many jobs have failed,
 * and how well the schema is indexed. Every probe is wrapped so a missing table
 * or unsupported driver degrades to a null/"unavailable" field rather than
 * failing the whole endpoint.
 */
class PerformanceController extends Controller
{
    /** Cache groups whose invalidation version is worth surfacing. */
    private const CACHE_GROUPS = ['settings', 'menu_items', 'dashboard'];

    public function metrics(): JsonResponse
    {
        return response()->json([
            'cache' => $this->cacheMetrics(),
            'queue' => $this->queueMetrics(),
            'database' => $this->databaseMetrics(),
            'generated_at' => now()->toIso8601String(),
        ]);
    }

    /** Active cache store + the current version of each tracked group. */
    private function cacheMetrics(): array
    {
        $versions = [];
        foreach (self::CACHE_GROUPS as $group) {
            try {
                $versions[$group] = TenantCache::version($group);
            } catch (Throwable) {
                $versions[$group] = null;
            }
        }

        return [
            'store' => config('cache.default'),
            'versions' => $versions,
        ];
    }

    /** Pending / failed job counts on the configured queue connection. */
    private function queueMetrics(): array
    {
        $connection = config('queue.default');
        $out = [
            'connection' => $connection,
            'driver' => config("queue.connections.$connection.driver"),
            'pending' => null,
            'failed' => null,
            'by_queue' => [],
        ];

        // Only the database driver exposes countable tables; redis/sqs report the
        // driver but leave the counts null (they need their own client to probe).
        if ($out['driver'] !== 'database') {
            return $out;
        }

        try {
            $db = config("queue.connections.$connection.connection") ?? config('database.default');
            $table = config("queue.connections.$connection.table", 'jobs');
            $conn = DB::connection($db);

            if (Schema::connection($db)->hasTable($table)) {
                $out['pending'] = $conn->table($table)->count();
                $out['by_queue'] = $conn->table($table)
                    ->selectRaw('queue, count(*) as total')
                    ->groupBy('queue')
                    ->pluck('total', 'queue')
                    ->all();
            }

            $failedDb = config('queue.failed.database', config('database.default'));
            $failedTable = config('queue.failed.table', 'failed_jobs');
            if (Schema::connection($failedDb)->hasTable($failedTable)) {
                $out['failed'] = DB::connection($failedDb)->table($failedTable)->count();
            }
        } catch (Throwable) {
            // Leave counts null on any probe failure.
        }

        return $out;
    }

    /** Driver + index coverage across the schema (indexes / tables and worst-covered tables). */
    private function databaseMetrics(): array
    {
        $out = [
            'driver' => config('database.default'),
            'connection_driver' => null,
            'tables' => null,
            'indexes' => null,
            'unindexed_tables' => [],
        ];

        try {
            $out['connection_driver'] = DB::connection()->getDriverName();
            $tables = collect(Schema::getTables())->pluck('name');
            $out['tables'] = $tables->count();

            $totalIndexes = 0;
            $unindexed = [];
            foreach ($tables as $table) {
                $count = count(Schema::getIndexes($table));
                $totalIndexes += $count;
                // A table with only its primary key (<=1 index) and a tenant_id
                // column is a candidate for a secondary index — surface it.
                if ($count <= 1 && Schema::hasColumn($table, 'tenant_id')) {
                    $unindexed[] = $table;
                }
            }
            $out['indexes'] = $totalIndexes;
            $out['unindexed_tables'] = array_values($unindexed);
        } catch (Throwable) {
            // Introspection unsupported — leave nulls.
        }

        return $out;
    }
}
