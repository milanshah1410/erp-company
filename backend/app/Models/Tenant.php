<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\GstStateCodes;
use App\Support\IndustryTemplates;
use App\Support\ShopProfiles;
use App\Support\Subdomain;
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
        'subdomain',
        'email',
        'phone',
        'industry',
        'business_type',
        'status',
        'suspended_by',
        'limit_overrides',
        // The tenant as the *platform's* customer — used to raise GST tax
        // invoices against it (see App\Services\Billing\PlatformTaxCalculator).
        'billing_legal_name',
        'billing_gstin',
        'billing_state_code',
        'billing_address',
    ];

    protected $casts = [
        'limit_overrides' => 'array',
    ];

    /**
     * Human label of the tenant's industry template, or null when unrecorded.
     */
    public function industryLabel(): ?string
    {
        return IndustryTemplates::find($this->industry)['label'] ?? null;
    }

    /**
     * Human label of the tenant's trade ("business type" in the platform panel).
     */
    public function businessTypeLabel(): ?string
    {
        return ShopProfiles::all()[$this->business_type ?? '']['label'] ?? null;
    }

    /** Browsable workspace address, e.g. `https://acme.yourapp.com`. */
    public function workspaceUrl(): ?string
    {
        return $this->subdomain ? Subdomain::url($this->subdomain) : null;
    }

    /**
     * Suspended by a SaaS-operator action (hard lock: no login, no API access),
     * as opposed to a billing lapse (read-only grace so the tenant can pay).
     */
    public function isOperatorSuspended(): bool
    {
        return $this->status === 'suspended' && $this->suspended_by === 'operator';
    }

    /**
     * The GST state code that decides CGST+SGST vs IGST on our invoices to this
     * tenant.
     *
     * The GSTIN wins when present — its first two digits *are* the state of
     * registration, and a registered customer's place of supply follows the
     * registration, not the postal address it typed. The hand-entered code is the
     * fallback for an unregistered customer.
     */
    public function placeOfSupply(): ?string
    {
        $fromGstin = $this->billing_gstin ? substr(trim($this->billing_gstin), 0, 2) : null;

        if ($fromGstin && GstStateCodes::name($fromGstin)) {
            return $fromGstin;
        }

        return $this->billing_state_code ?: null;
    }

    /** Who the invoice is addressed to: the legal name if given, else the brand. */
    public function billingName(): string
    {
        return $this->billing_legal_name ?: $this->company_name;
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function domains(): HasMany
    {
        return $this->hasMany(Domain::class);
    }

    public function primaryDomain(): HasOne
    {
        return $this->hasOne(Domain::class)->where('is_primary', true);
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
