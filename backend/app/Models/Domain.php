<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A host that resolves to a tenant workspace. Every tenant gets one primary
 * row (`{subdomain}.{root domain}`) at provisioning; additional non-primary
 * rows are the seam for tenant-supplied custom domains.
 *
 * Landlord-owned like Tenant, so it carries no tenant scope.
 */
class Domain extends Model
{
    protected $fillable = [
        'tenant_id',
        'domain',
        'is_primary',
    ];

    protected function casts(): array
    {
        return ['is_primary' => 'boolean'];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
