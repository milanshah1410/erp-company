<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\MenuItem;
use App\Support\TenantCache;
use App\Support\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Bring every tenant's cloned menu tree back in line with the global catalogue:
 * push the gating tags (`module`, `feature`) onto rows the tenant already has,
 * and insert the rows it is missing entirely.
 *
 * Each tenant owns a copy of the catalogue (ProvisionTenant::cloneMenuForTenant),
 * and MenuSeeder only maintains the NULL-tenant originals — so neither retagging
 * the catalogue (e.g. adding the textile/tailoring/logistics modules) nor adding
 * new screens to it (e.g. the Import & Export Logistics group) would otherwise
 * ever reach a tenant provisioned before the change. Rows are matched by walking
 * both trees in parallel and pairing children by label, so tenant-specific rows
 * are left alone.
 *
 * Inserting is additive only: a global row absent from the tenant is created
 * (with its whole subtree), never deleted or renamed. The tradeoff is that a row
 * an admin deliberately deleted from a tenant's menu comes back — pass
 * --no-insert to sync tags alone.
 */
class SyncMenuModules extends Command
{
    protected $signature = 'menu:sync-modules
                            {--tenant= : Only sync this tenant id}
                            {--no-insert : Only retag existing rows; do not add missing ones}
                            {--dry-run : Report what would change without writing}';

    protected $description = "Sync the global menu catalogue onto tenants' cloned menu rows (retag + add missing screens)";

    private int $updated = 0;

    private int $inserted = 0;

    private bool $dry = false;

    private bool $insert = true;

    public function handle(): int
    {
        $this->dry = (bool) $this->option('dry-run');
        $this->insert = ! $this->option('no-insert');
        $this->updated = 0;
        $this->inserted = 0;

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
            $retagged = $this->updated;
            $added = $this->inserted;
            $tenantByParent = MenuItem::where('tenant_id', $tenantId)->get()->groupBy('parent_id');
            $this->walk($globalByParent, $tenantByParent, null, null, $tenantId);

            $this->line(sprintf(
                'tenant %-4d %d retagged, %d added%s',
                $tenantId,
                $this->updated - $retagged,
                $this->inserted - $added,
                $this->dry ? ' (dry run)' : '',
            ));

            // The MenuController caches each tenant's catalogue under that
            // tenant's own key, and the model's own invalidation hook bumps
            // whatever tenant is active — which in a console run is none. Bump
            // it explicitly per tenant so the new rows show up on the next page
            // load instead of after the 10-minute TTL.
            if (! $this->dry && ($this->updated > $retagged || $this->inserted > $added)) {
                app(TenantContext::class)->runWith($tenantId, fn () => TenantCache::bump('menu_items'));
            }
        }

        if (! $this->dry) {
            // The permission-matrix map is derived from these tags — drop it so
            // the Roles screen reflects the retag immediately.
            Cache::forget('permission_group_modules');
        }

        $this->info(sprintf(
            '%s %d row(s) and %s %d row(s) across %d tenant(s).',
            $this->dry ? 'Would retag' : 'Retagged',
            $this->updated,
            $this->dry ? 'would add' : 'added',
            $this->inserted,
            $tenantIds->count(),
        ));

        return self::SUCCESS;
    }

    /**
     * @param  Collection<int|string, Collection<int, MenuItem>>  $globalByParent
     * @param  Collection<int|string, Collection<int, MenuItem>>  $tenantByParent
     */
    private function walk(Collection $globalByParent, Collection $tenantByParent, ?int $globalParent, ?int $tenantParent, int $tenantId): void
    {
        /** @var Collection<int, MenuItem> $tenantSiblings */
        $tenantSiblings = $tenantByParent->get($tenantParent, collect());

        foreach ($globalByParent->get($globalParent, collect()) as $globalRow) {
            $match = $tenantSiblings->firstWhere('label', $globalRow->label);

            if (! $match) {
                // The tenant is missing this row: either the catalogue grew
                // since it was provisioned, or an admin removed the row here.
                if ($this->insert) {
                    $this->copySubtree($globalByParent, $globalRow, $tenantParent, $tenantId);
                }

                continue;
            }

            if ($match->module !== $globalRow->module || $match->feature !== $globalRow->feature) {
                $this->updated++;
                if (! $this->dry) {
                    $match->module = $globalRow->module;
                    $match->feature = $globalRow->feature;
                    $match->save();
                }
            }

            $this->walk($globalByParent, $tenantByParent, $globalRow->id, $match->id, $tenantId);
        }
    }

    /**
     * Copy a global row and everything under it into the tenant's tree beneath
     * $tenantParent, mirroring ProvisionTenant::cloneMenuForTenant. Under
     * --dry-run nothing is written and the recursion only counts rows, so the
     * parent id it threads down is meaningless (there is no new row yet).
     *
     * @param  Collection<int|string, Collection<int, MenuItem>>  $globalByParent
     */
    private function copySubtree(Collection $globalByParent, MenuItem $globalRow, ?int $tenantParent, int $tenantId): void
    {
        $this->inserted++;

        $newParentId = null;
        if (! $this->dry) {
            /** @var MenuItem $copy */
            $copy = $globalRow->replicate();
            $copy->tenant_id = $tenantId;
            $copy->parent_id = $tenantParent;
            $copy->save();
            $newParentId = $copy->id;
        }

        foreach ($globalByParent->get($globalRow->id, collect()) as $child) {
            $this->copySubtree($globalByParent, $child, $newParentId, $tenantId);
        }
    }
}
