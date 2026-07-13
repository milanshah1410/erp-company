<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Scopes\TenantScope;
use App\Support\TenantContext;
use Spatie\Activitylog\Models\Activity;

/**
 * Tenant-aware activity log. The activity_log table lives in the shared
 * database, so entries are stamped with the current tenant on write and
 * queries are scoped to the current tenant on read.
 *
 * The scope is NOT fail-closed: activities are written in contexts with no
 * tenant (pre-auth login failures, console commands), which are simply recorded
 * with tenant_id = NULL and never surface in a tenant's audit log.
 */
class ActivityLog extends Activity
{
    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope(failClosed: false));

        static::creating(function (self $activity): void {
            if ($activity->tenant_id === null) {
                $context = app(TenantContext::class);
                if ($context->has()) {
                    $activity->tenant_id = $context->id();
                }
            }
        });
    }
}
