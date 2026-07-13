<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Marks a model as tenant-owned in the shared database. Adds the row-level
 * TenantScope to every query and auto-stamps `tenant_id` from the current
 * TenantContext on create (unless already set explicitly).
 *
 * @property int $tenant_id
 *
 * @method static Builder allTenants() Query without the tenant scope (cross-tenant).
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope());

        static::creating(function (Model $model): void {
            if ($model->getAttribute('tenant_id') === null) {
                $context = app(TenantContext::class);
                if ($context->has()) {
                    $model->setAttribute('tenant_id', $context->id());
                }
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Local scope: query across all tenants, ignoring the tenant scope.
     * Guard call sites behind a platform/super-admin check.
     */
    public function scopeAllTenants(Builder $query): Builder
    {
        return $query->withoutGlobalScope(TenantScope::class);
    }
}
