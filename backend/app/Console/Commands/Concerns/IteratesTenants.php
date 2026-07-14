<?php

namespace App\Console\Commands\Concerns;

use App\Models\Tenant;
use App\Support\TenantContext;
use Closure;
use Spatie\Permission\PermissionRegistrar;

/**
 * Helper for scheduled/console commands that scan tenant-owned business data.
 *
 * The shared-database tenant scope (App\Models\Scopes\TenantScope) fails closed:
 * any business query with no tenant context throws. Spatie permissions also run
 * in "teams" mode keyed by tenant_id, so User::role(...) only resolves once the
 * permission team is set. A command running off the global scheduler has neither,
 * so it must establish both per tenant — this trait does that.
 */
trait IteratesTenants
{
    /**
     * Run $callback once per active (non-suspended) tenant, with that tenant's
     * TenantContext and Spatie permission team active so tenant-scoped queries
     * and User::role(...) resolve correctly. The global (context-less) state is
     * restored after each tenant, even on failure.
     *
     * @param  Closure(Tenant): void  $callback
     */
    protected function eachTenant(Closure $callback): void
    {
        $context = app(TenantContext::class);
        $registrar = app(PermissionRegistrar::class);

        Tenant::query()
            ->where('status', '!=', 'suspended')
            ->orderBy('id')
            ->get()
            ->each(function (Tenant $tenant) use ($context, $registrar, $callback): void {
                $registrar->setPermissionsTeamId($tenant->id);

                try {
                    $context->runWith($tenant->id, fn () => $callback($tenant));
                } finally {
                    $registrar->setPermissionsTeamId(null);
                }
            });
    }
}
