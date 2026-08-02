<?php

declare(strict_types=1);

namespace App\Modules\Industry\BookStore\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsAuditActivity;
use App\Models\Product;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A publishing house, and the terms the shop trades with it on.
 *
 * Not a brand, and the difference is commercial rather than cosmetic. A brand is
 * a mark on merchandise; a publisher decides the price printed on the cover,
 * sells at a discount off that price, and — uniquely in this trade — will often
 * take unsold copies back for credit. Those are terms of a relationship, and a
 * relationship has a counterparty. Core `brands` stays free for the pens and the
 * gift wrap the same shop sells.
 *
 * The two fields that earn this table its place:
 *
 *  - `trade_discount_percent` is the shop's entire margin. A book is sold at the
 *    price on the cover, so what the shop makes is decided the moment it chooses
 *    which houses to stock.
 *  - `is_returnable` decides whether slow stock is a deadline or a loss. On a
 *    sale-or-return house, a title that has not moved in five months should go
 *    back before the window shuts; on a firm-sale house it is the shop's problem
 *    forever. A buyer who cannot tell them apart writes off books that could have
 *    been credited.
 */
class Publisher extends Model
{
    use BelongsToTenant, LogsAuditActivity;

    protected $table = 'book_publishers';

    protected $fillable = [
        'code', 'name', 'imprint', 'supplier_id',
        'trade_discount_percent', 'is_returnable', 'return_window_days',
        'contact_person', 'phone', 'email', 'website', 'notes', 'is_active',
    ];

    protected $casts = [
        'trade_discount_percent' => 'decimal:2',
        'is_returnable' => 'boolean',
        'return_window_days' => 'integer',
        'is_active' => 'boolean',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'is_returnable' => false,
        'is_active' => true,
    ];

    /**
     * Titles from this house.
     *
     * Declared here rather than on Product so the dependency points plugin →
     * core and never back; the reverse relation is attached at boot by
     * BookStorePlugin.
     */
    public function titles(): HasMany
    {
        return $this->hasMany(Product::class, 'book_publisher_id');
    }

    /**
     * Who the purchase order actually goes to. Nullable, and routinely somebody
     * other than the publisher: most Indian bookshops buy through a distributor
     * and never deal with the house at all.
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /** "Penguin Books (Puffin)" — the house, and the imprint if there is one. */
    public function displayName(): string
    {
        return $this->imprint ? "{$this->name} ({$this->imprint})" : (string) $this->name;
    }

    /**
     * What a copy costs the shop at the printed price, given this house's trade
     * discount. Derived rather than stored, because the discount is renegotiated
     * and every copy already bought has its real cost on its purchase line.
     */
    public function costAtPrintedPrice(?float $printedPrice): ?float
    {
        if ($printedPrice === null || $this->trade_discount_percent === null) {
            return null;
        }

        return round($printedPrice * (1 - ((float) $this->trade_discount_percent / 100)), 2);
    }

    /**
     * The last day unsold copies can go back, counted from when they were
     * invoiced.
     *
     * Null for a house that does not take returns, and null for one whose
     * arrangement is open-ended — which are different answers to a buyer and both
     * mean "no date to watch", which is what this method is asked for.
     */
    public function returnDeadline(Carbon|string|null $purchasedOn): ?Carbon
    {
        if (! $this->is_returnable || ! $this->return_window_days || $purchasedOn === null) {
            return null;
        }

        return Carbon::parse($purchasedOn)->addDays((int) $this->return_window_days);
    }
}
