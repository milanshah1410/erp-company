<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\UsageMeter;
use Illuminate\Console\Command;

/**
 * Drains the buffered usage counters into `tenant_usage_daily`
 * (Volume 11 §"Tenant Usage").
 *
 * Scheduled every five minutes. The platform panel also flushes on read, so an
 * operator never looks at a number that is five minutes stale — this command
 * exists so the history is written even when nobody is looking.
 */
class FlushUsageMeters extends Command
{
    protected $signature = 'usage:flush';

    protected $description = 'Persist buffered tenant usage counters (API and AI calls) to the daily usage table';

    public function handle(): int
    {
        $rows = UsageMeter::flush();

        $this->info("Flushed {$rows} usage counter row(s).");

        return self::SUCCESS;
    }
}
