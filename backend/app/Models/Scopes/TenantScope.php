<?php

declare(strict_types=1);

namespace App\Models\Scopes;

use App\Exceptions\TenantContextMissingException;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Global scope that constrains every query on a tenant-owned model to the
 * current tenant. Fails closed: if no tenant is set (and the scope is not
 * explicitly bypassed) it throws rather than leaking all tenants' rows.
 *
 * Schema migrations use the Schema/DB query builders, which do not apply
 * Eloquent global scopes, so migrate:fresh is unaffected.
 */
class TenantScope implements Scope
{
    /**
     * @param  bool  $failClosed  When true (business tables), a query with no
     *   tenant set throws. When false (the User model, which is queried on the
     *   pre-auth Sanctum path before a tenant exists), it no-ops instead — so
     *   authenticated queries are still scoped, but token/login resolution works.
     */
    public function __construct(private bool $failClosed = true) {}

    public function apply(Builder $builder, Model $model): void
    {
        $context = app(TenantContext::class);

        if ($context->shouldBypassScope()) {
            return;
        }

        if (! $context->has()) {
            if ($this->failClosed) {
                throw TenantContextMissingException::for($model::class);
            }

            return;
        }

        // Qualify the column so joins across tenant tables never hit an
        // "ambiguous column" error.
        $builder->where($model->qualifyColumn('tenant_id'), $context->id());
    }
}
