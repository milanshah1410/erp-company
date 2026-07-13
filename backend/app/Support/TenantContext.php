<?php

declare(strict_types=1);

namespace App\Support;

use Closure;

/**
 * Holds the current tenant id for the active request / job. Bound as a scoped
 * singleton (see AppServiceProvider) so each request and each queue job starts
 * with a clean, isolated context.
 *
 * The row-level tenant scope (App\Models\Scopes\TenantScope) reads this to
 * filter every business query. Requests set it in the ResolveTenant middleware
 * (from the authenticated user); jobs/console/seeders set it explicitly via
 * runWith() — the direct replacement for stancl's $tenant->run().
 */
class TenantContext
{
    private ?int $tenantId = null;

    /** When true, the tenant scope is bypassed entirely (cross-tenant admin/reporting). */
    private bool $bypassScope = false;

    public function set(int $tenantId): void
    {
        $this->tenantId = $tenantId;
    }

    public function id(): ?int
    {
        return $this->tenantId;
    }

    public function has(): bool
    {
        return $this->tenantId !== null;
    }

    public function forget(): void
    {
        $this->tenantId = null;
    }

    public function shouldBypassScope(): bool
    {
        return $this->bypassScope;
    }

    /**
     * Run $callback with $tenantId as the active tenant, restoring the previous
     * context afterwards. Use in jobs, console commands, and seeders.
     *
     * @template T
     * @param  Closure(): T  $callback
     * @return T
     */
    public function runWith(int $tenantId, Closure $callback)
    {
        $previous = $this->tenantId;
        $this->tenantId = $tenantId;

        try {
            return $callback();
        } finally {
            $this->tenantId = $previous;
        }
    }

    /**
     * Run $callback with the tenant scope disabled (sees every tenant's rows).
     * Guard call sites behind a platform/super-admin check.
     *
     * @template T
     * @param  Closure(): T  $callback
     * @return T
     */
    public function runWithoutScope(Closure $callback)
    {
        $previous = $this->bypassScope;
        $this->bypassScope = true;

        try {
            return $callback();
        } finally {
            $this->bypassScope = $previous;
        }
    }
}
