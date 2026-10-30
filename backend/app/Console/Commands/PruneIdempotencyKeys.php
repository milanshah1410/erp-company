<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\IdempotencyKey;
use Illuminate\Console\Command;

/**
 * Sweeps expired replay receipts (mobile app §18).
 *
 * The row exists to answer a retry, and a retry that has not arrived within a
 * day is not going to. The invoice it protected is the permanent record; this is
 * only the receipt for having created it, so nothing is lost by dropping it —
 * whereas keeping every write attempt forever would grow a table the size of the
 * ledger for no reader.
 *
 * Deletes across all tenants deliberately: expiry is a clock, not a workspace
 * policy, and iterating tenants to apply the same cut-off would be slower and no
 * more correct.
 */
class PruneIdempotencyKeys extends Command
{
    protected $signature = 'idempotency:prune {--chunk=1000 : Rows to delete per pass}';

    protected $description = 'Delete expired idempotency keys';

    public function handle(): int
    {
        $chunk = max(100, (int) $this->option('chunk'));
        $deleted = 0;

        // Deleted in bounded passes rather than one statement: an unbounded
        // DELETE on a busy table holds locks for as long as it takes, and this
        // runs on a live database.
        do {
            $rows = IdempotencyKey::withoutGlobalScopes()->expired()->limit($chunk)->delete();
            $deleted += $rows;
        } while ($rows > 0);

        $this->info("Pruned {$deleted} expired idempotency key(s).");

        return self::SUCCESS;
    }
}
