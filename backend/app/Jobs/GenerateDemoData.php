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
    ) {}

    public function handle(TenantContext $context): void
    {
        $context->runWith($this->tenantId, function () {
            (new DemoDataGenerator($this->userId))->generate($this->modules, $this->count);
        });
    }
}
