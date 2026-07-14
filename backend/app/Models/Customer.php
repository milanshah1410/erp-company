<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsAuditActivity;
use Illuminate\Auth\Authenticatable as AuthenticatableTrait;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * A customer is also a B2B portal account (SRS §14): with `portal_enabled` and
 * a `portal_password` set by staff, they authenticate against the /portal API
 * as a Sanctum tokenable and place their own orders.
 */
class Customer extends Model implements AuthenticatableContract
{
    use AuthenticatableTrait, BelongsToTenant, HasApiTokens, LogsAuditActivity, Notifiable;

    protected $fillable = [
        'code', 'name', 'company_name', 'email', 'phone', 'type', 'customer_category_id',
        'date_of_birth', 'anniversary_date', 'referred_by_customer_id',
        'gst_number', 'gst_registration_type', 'pan', 'place_of_supply',
        'address', 'city', 'state', 'country', 'pincode',
        'credit_limit', 'opening_balance', 'default_price_level', 'is_active',
        'portal_enabled',
    ];

    protected $hidden = [
        'portal_password',
    ];

    protected $casts = [
        'credit_limit' => 'decimal:2',
        'opening_balance' => 'decimal:2',
        'date_of_birth' => 'date',
        'anniversary_date' => 'date',
        'is_active' => 'boolean',
        'portal_enabled' => 'boolean',
        'portal_last_login_at' => 'datetime',
    ];

    /** Portal login authenticates against the dedicated portal password column. */
    public function getAuthPassword(): string
    {
        return (string) $this->portal_password;
    }

    /** Whether this customer can currently sign in to the B2B portal. */
    public function canUsePortal(): bool
    {
        return $this->is_active && $this->portal_enabled && ! empty($this->portal_password);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(CustomerCategory::class, 'customer_category_id');
    }

    public function salesOrders(): HasMany
    {
        return $this->hasMany(SalesOrder::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function salesReturns(): HasMany
    {
        return $this->hasMany(SalesReturn::class);
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(CustomerAddress::class);
    }

    public function priceListItems(): HasMany
    {
        return $this->hasMany(CustomerPriceList::class);
    }

    public function loyaltyTransactions(): HasMany
    {
        return $this->hasMany(LoyaltyTransaction::class);
    }

    public function followUps(): MorphMany
    {
        return $this->morphMany(FollowUp::class, 'followupable');
    }

    public function visits(): HasMany
    {
        return $this->hasMany(CustomerVisit::class);
    }

    /** The customer who referred this one (if any). */
    public function referredBy(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'referred_by_customer_id');
    }

    /** Referrals this customer has made. */
    public function referralsMade(): HasMany
    {
        return $this->hasMany(Referral::class, 'referrer_customer_id');
    }

    /** Current redeemable loyalty-point balance (signed ledger sum). */
    public function loyaltyPoints(): int
    {
        return (int) $this->loyaltyTransactions()->sum('points');
    }

    /** Every payment ever recorded against this customer's invoices. */
    public function payments(): HasManyThrough
    {
        return $this->hasManyThrough(Payment::class, Invoice::class);
    }

    public function defaultBillingAddress(): ?CustomerAddress
    {
        return $this->addresses()
            ->where('type', 'billing')
            ->orderByDesc('is_default')->orderBy('id')
            ->first();
    }

    public function defaultShippingAddress(): ?CustomerAddress
    {
        return $this->addresses()
            ->where('type', 'shipping')
            ->orderByDesc('is_default')->orderBy('id')
            ->first();
    }

    /** Pricing tier for this customer: own level, else the category's, else 'retail'. */
    public function effectivePriceLevel(): string
    {
        return $this->default_price_level
            ?? $this->category?->default_price_level
            ?? 'retail';
    }

    /**
     * Resolve the unit price for a product/variant at a given quantity:
     * customer-specific override → product price-list at the effective tier
     * → the product's base selling price.
     */
    public function priceFor(Product $product, ?int $variantId = null, float $qty = 1): float
    {
        $override = $this->priceListItems()
            ->where('product_id', $product->id)
            ->where('variant_id', $variantId)
            ->where('min_quantity', '<=', $qty)
            ->orderByDesc('min_quantity')
            ->value('price');

        if ($override !== null) {
            return (float) $override;
        }

        $tierPrice = PriceList::query()
            ->where('product_id', $product->id)
            ->where('variant_id', $variantId)
            ->where('level', $this->effectivePriceLevel())
            ->where('min_quantity', '<=', $qty)
            ->orderByDesc('min_quantity')
            ->value('price');

        return (float) ($tierPrice ?? $product->selling_price);
    }

    /** Credit still available before the limit is hit (0 limit = unlimited). */
    public function availableCredit(): ?float
    {
        $limit = (float) $this->credit_limit;
        if ($limit <= 0) {
            return null; // unlimited
        }

        return round($limit - $this->outstandingBalance(), 2);
    }

    /** Opening balance plus every unpaid, uncredited rupee across invoices. */
    public function outstandingBalance(): float
    {
        $unpaid = (float) $this->invoices()
            ->whereIn('status', ['unpaid', 'partial'])
            ->selectRaw('COALESCE(SUM(total - paid_amount - credited_amount), 0) as due')
            ->value('due');

        return round((float) $this->opening_balance + $unpaid, 2);
    }
}
