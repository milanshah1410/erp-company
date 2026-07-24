<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A tenant (workspace) in the shared database. Business data across all tables
 * is scoped to a tenant by its `tenant_id` column (see BelongsToTenant); this
 * record itself is landlord-owned and unscoped.
 */
class Tenant extends Model
{
    protected $fillable = [
        'company_name',
        'email',
        'phone',
        'status',
        'suspended_by',
    ];

    /**
     * Suspended by a SaaS-operator action (hard lock: no login, no API access),
     * as opposed to a billing lapse (read-only grace so the tenant can pay).
     */
    public function isOperatorSuspended(): bool
    {
        return $this->status === 'suspended' && $this->suspended_by === 'operator';
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
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
}
