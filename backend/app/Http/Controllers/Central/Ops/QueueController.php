<?php

namespace App\Http\Controllers\Central\Ops;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Ops panel — queue monitoring (DB queue driver: jobs / failed_jobs tables).
 * Reads are gated by ops-queues.view; retry/forget/flush by ops-queues.manage.
 */
class QueueController extends Controller
{
    /** Pending backlog grouped by queue + the most recent failed jobs. */
    public function index()
    {
        $pendingByQueue = DB::table('jobs')
            ->selectRaw('queue, count(*) as jobs, min(created_at) as oldest_created_at')
            ->groupBy('queue')
            ->orderByDesc('jobs')
            ->get()
            ->map(fn ($row) => [
                'queue' => $row->queue,
                'jobs' => (int) $row->jobs,
                'oldest_created_at' => $row->oldest_created_at
                    ? date('Y-m-d H:i:s', (int) $row->oldest_created_at)
                    : null,
            ]);

        $failed = DB::table('failed_jobs')
            ->orderByDesc('failed_at')
            ->limit(50)
            ->get()
            ->map(fn ($row) => [
                'uuid' => $row->uuid,
                'connection' => $row->connection,
                'queue' => $row->queue,
                'job' => $this->displayName($row->payload),
                'exception' => strtok($row->exception, "\n"),
                'failed_at' => $row->failed_at,
            ]);

        return response()->json([
            'data' => [
                'pending_total' => (int) DB::table('jobs')->count(),
                'failed_total' => (int) DB::table('failed_jobs')->count(),
                'pending_by_queue' => $pendingByQueue,
                'failed' => $failed,
            ],
        ]);
    }

    /** Full exception trace for one failed job (kept out of the list payload). */
    public function failedJob(string $uuid)
    {
        $row = DB::table('failed_jobs')->where('uuid', $uuid)->first();
        if (! $row) {
            abort(404, 'Failed job not found.');
        }

        return response()->json([
            'data' => [
                'uuid' => $row->uuid,
                'job' => $this->displayName($row->payload),
                'exception' => $row->exception,
                'failed_at' => $row->failed_at,
            ],
        ]);
    }

    public function retry(string $uuid)
    {
        Artisan::call('queue:retry', ['id' => [$uuid]]);

        return response()->json(['message' => 'Job pushed back onto the queue.']);
    }

    public function retryAll()
    {
        Artisan::call('queue:retry', ['id' => ['all']]);

        return response()->json(['message' => 'All failed jobs pushed back onto the queue.']);
    }

    public function forget(string $uuid)
    {
        Artisan::call('queue:forget', ['id' => $uuid]);

        return response()->json(['message' => 'Failed job deleted.']);
    }

    public function flush()
    {
        Artisan::call('queue:flush');

        return response()->json(['message' => 'All failed jobs deleted.']);
    }

    private function displayName(string $payload): string
    {
        $decoded = json_decode($payload, true);

        return $decoded['displayName'] ?? 'unknown';
    }
}
