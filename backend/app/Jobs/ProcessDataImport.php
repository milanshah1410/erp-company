<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\DataImportService;
use App\Support\TenantCache;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Runs a large data import off the request cycle (§17 Performance — Queue).
 *
 * Small imports still run inline for an instant summary; only files above the
 * controller's row threshold reach this job, so a 10k-row upload never ties up
 * a web worker or hits the request timeout. Re-establishes the tenant context
 * first (queue tenancy convention, mirroring GenerateDemoData) so every created
 * row is tenant-stamped, then writes the per-row summary to the tenant cache
 * under $statusKey where DataImportController@status can poll for it.
 *
 * @param  array<int,array<string,mixed>>  $rows
 */
class ProcessDataImport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 900;

    /** How long a finished result stays pollable (seconds). */
    private const RESULT_TTL = 3600;

    public function __construct(
        public int $tenantId,
        public string $statusKey,
        public string $module,
        public array $rows,
    ) {}

    public function handle(TenantContext $context, DataImportService $imports): void
    {
        $context->runWith($this->tenantId, function () use ($imports) {
            try {
                $result = $imports->import($this->module, $this->rows);

                TenantCache::put($this->statusKey, [
                    'status' => 'completed',
                    'message' => "Imported {$result['created']} record(s), {$result['failed']} failed.",
                    ...$result,
                ], self::RESULT_TTL);
            } catch (Throwable $e) {
                TenantCache::put($this->statusKey, [
                    'status' => 'failed',
                    'message' => 'The import could not be completed: '.$e->getMessage(),
                    'created' => 0,
                    'failed' => count($this->rows),
                    'errors' => [],
                ], self::RESULT_TTL);

                throw $e;
            }
        });
    }

    /** Mark the import as failed if the job exhausts retries / errors out. */
    public function failed(Throwable $e): void
    {
        TenantCache::put($this->statusKey, [
            'status' => 'failed',
            'message' => 'The import failed to process.',
            'created' => 0,
            'failed' => count($this->rows),
            'errors' => [],
        ], self::RESULT_TTL);
    }
}
