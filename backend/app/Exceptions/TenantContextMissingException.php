<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a tenant-scoped model is queried while no tenant is set in the
 * TenantContext. We fail closed rather than silently returning every tenant's
 * rows: an unscoped query on shared-DB business data is a cross-tenant leak.
 *
 * Legitimate tenant-less access (login lookups, provisioning, cross-tenant
 * reporting) must go through withoutGlobalScope(TenantScope::class) /
 * TenantContext::runWith() / runWithoutScope().
 */
class TenantContextMissingException extends RuntimeException
{
    public static function for(string $model): self
    {
        return new self(
            "No tenant is set in the current context while querying [{$model}]. "
            .'Set a tenant (ResolveTenant middleware / TenantContext::runWith) or '
            .'use withoutGlobalScope(TenantScope::class) for an intentional cross-tenant query.'
        );
    }
}
