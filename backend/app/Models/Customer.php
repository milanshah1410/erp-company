<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsAuditActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Customer extends Model
{
    use BelongsToTenant, LogsAuditActivity;

    protected $fillable = [
        'code', 'name', 'company_name', 'email', 'phone', 'type', 'customer_category_id',
        'gst_number', 'gst_registration_type', 'pan', 'place_of_supply',
        'address', 'city', 'state', 'country', 'pincode',
        'credit_limit', 'opening_balance', 'default_price_level', 'is_active',
    ];

    protected $casts = [
        'credit_limit' => 'decimal:2',
        'opening_balance' => 'decimal:2',
        'is_active' => 'boolean',
    ];

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
