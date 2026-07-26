<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\DemoDataGenerator;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Generates demo records for a tenant off the request cycle (project-stack.md
 * §7). Re-establishes the tenant context first (queue tenancy convention), so
 * every generated row is tenant-stamped.
 *
 * @param  array<int,string>  $modules
 */
class GenerateDemoData implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(
        public int $tenantId,
        public int $userId,
        public array $modules,
        public int $count,
    ) {
        // Rows are created one model at a time (observers/workflow triggers have
        // to fire), so the bulk volumes need hours, not minutes. Scale the
        // worker timeout to the requested volume instead of failing mid-run.
        $perModule = match (true) {
            $count >= 1_000_000 => 21_600, // 6h
            $count >= 100_000 => 7_200,    // 2h
            default => 900,                // 15m
        };

        $this->timeout = $perModule * max(1, count($modules));
    }

    public function handle(TenantContext $context): void
    {
        $context->runWith($this->tenantId, function () {
            (new DemoDataGenerator($this->userId))->generate($this->modules, $this->count);
        });
    }
}
