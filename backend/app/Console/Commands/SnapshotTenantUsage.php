<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Support\UsageMeter;
use Illuminate\Console\Command;

/**
 * Records today's reading of every snapshot metric — storage, products,
 * customers — for every tenant (Volume 11 §"Tenant Usage").
 *
 * The panel recomputes products and customers live, so this is not what makes
 * those numbers correct; it is what gives them a history. Storage is the
 * exception: walking every tenant's folder is too slow to do on a list render,
 * so the table shows the last snapshot and the per-tenant screen can force a
 * fresh read.
 *
 * Runs nightly, after the counters have settled.
 */
class SnapshotTenantUsage extends Command
{
    protected $signature = 'usage:snapshot {--fresh-storage : Recompute storage instead of using the cached figure}';

    protected $description = 'Record a daily snapshot of tenant storage, product and customer counts';

    public function handle(): int
    {
        if ($this->option('fresh-storage')) {
            // Drop the cached folder walk so the snapshot is a true reading
            // rather than up to an hour old.
            foreach (Tenant::query()->pluck('id') as $tenantId) {
                UsageMeter::storageBytes((int) $tenantId, fresh: true);
            }
        }

        $rows = UsageMeter::snapshot();

        $this->info("Recorded {$rows} usage snapshot row(s).");

        return self::SUCCESS;
    }
}
