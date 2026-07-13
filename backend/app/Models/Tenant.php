<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Database\Concerns\HasDatabase;
use Stancl\Tenancy\Database\Concerns\HasDomains;
use Stancl\Tenancy\Database\Models\Tenant as BaseTenant;

/**
 * Central-DB tenant record. Business data lives in a dedicated per-tenant
 * database (HasDatabase); the subdomain(s) are tracked via HasDomains.
 *
 * Columns listed in getCustomColumns() are real table columns; everything
 * else set on the model is transparently stored in the `data` JSON column
 * by stancl's VirtualColumn.
 */
class Tenant extends BaseTenant implements TenantWithDatabase
{
    use HasDatabase, HasDomains;

    public static function getCustomColumns(): array
    {
        return [
            'id',
            'company_name',
            'email',
            'phone',
            'status',
        ];
    }

    public function subscription(): HasOne
    {
        return $this->hasOne(Subscription::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function onboardingProgress(): HasOne
    {
        return $this->hasOne(OnboardingProgress::class);
    }

    public function primaryDomain(): ?string
    {
        return $this->domains()->orderBy('id')->value('domain');
    }
}
