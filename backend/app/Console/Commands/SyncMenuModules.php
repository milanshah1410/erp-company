<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\MenuItem;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Push the global menu catalogue's gating tags (`module`, `feature`) onto every
 * tenant's cloned menu rows.
 *
 * Each tenant owns a copy of the catalogue (ProvisionTenant::cloneMenuForTenant),
 * and MenuSeeder only maintains the NULL-tenant originals — so retagging the
 * catalogue (e.g. adding the textile/tailoring/logistics modules) would otherwise
 * never reach existing tenants. Rows are matched by walking both trees in
 * parallel and pairing children by label, so tenant-specific rows are left alone.
 */
class SyncMenuModules extends Command
{
    protected $signature = 'menu:sync-modules
                            {--tenant= : Only sync this tenant id}
                            {--dry-run : Report what would change without writing}';

    protected $description = "Copy module/feature tags from the global menu catalogue onto tenants' cloned menu rows";

    private int $updated = 0;

    private bool $dry = false;

    public function handle(): int
    {
        $this->dry = (bool) $this->option('dry-run');

        $global = MenuItem::whereNull('tenant_id')->orderBy('sort_order')->get();
        if ($global->isEmpty()) {
            $this->error('The global menu catalogue is empty — run `php artisan db:seed --class=MenuSeeder` first.');

            return self::FAILURE;
        }

        $tenantIds = MenuItem::whereNotNull('tenant_id')
            ->when($this->option('tenant'), fn ($q) => $q->where('tenant_id', (int) $this->option('tenant')))
            ->distinct()
            ->orderBy('tenant_id')
            ->pluck('tenant_id');

        if ($tenantIds->isEmpty()) {
            $this->info('No tenant-owned menu rows found — nothing to sync.');

            return self::SUCCESS;
        }

        $globalByParent = $global->groupBy('parent_id');

        foreach ($tenantIds as $tenantId) {
            $before = $this->updated;
            $tenantByParent = MenuItem::where('tenant_id', $tenantId)->get()->groupBy('parent_id');
            $this->walk($globalByParent, $tenantByParent, null, null);
            $this->line(sprintf('tenant %-4d %d row(s) %s', $tenantId, $this->updated - $before, $this->dry ? 'would change' : 'updated'));
        }

        if (! $this->dry) {
            // The permission-matrix map is derived from these tags — drop it so
            // the Roles screen reflects the retag immediately.
            Cache::forget('permission_group_modules');
        }

        $this->info(sprintf('%s %d row(s) across %d tenant(s).', $this->dry ? 'Would update' : 'Updated', $this->updated, $tenantIds->count()));

        return self::SUCCESS;
    }

    /**
     * @param  Collection<int|string, Collection<int, MenuItem>>  $globalByParent
     * @param  Collection<int|string, Collection<int, MenuItem>>  $tenantByParent
     */
    private function walk(Collection $globalByParent, Collection $tenantByParent, ?int $globalParent, ?int $tenantParent): void
    {
        /** @var Collection<int, MenuItem> $tenantSiblings */
        $tenantSiblings = $tenantByParent->get($tenantParent, collect());

        foreach ($globalByParent->get($globalParent, collect()) as $globalRow) {
            $match = $tenantSiblings->firstWhere('label', $globalRow->label);
            if (! $match) {
                continue; // tenant removed/renamed this row — leave its tree alone
            }

            if ($match->module !== $globalRow->module || $match->feature !== $globalRow->feature) {
                $this->updated++;
                if (! $this->dry) {
                    $match->module = $globalRow->module;
                    $match->feature = $globalRow->feature;
                    $match->save();
                }
            }

            $this->walk($globalByParent, $tenantByParent, $globalRow->id, $match->id);
        }
    }
}
