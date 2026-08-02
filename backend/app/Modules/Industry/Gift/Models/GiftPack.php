<?php

declare(strict_types=1);

namespace App\Modules\Industry\Gift\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsAuditActivity;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A hamper: one sellable product, and the list of things that go inside it.
 *
 * The pack is a bill of contents, not a price. What it costs to buy is
 * `product->selling_price`, because that is the number the till already uses and
 * a second copy here would be a second answer to the same question — with the
 * till reading the wrong one. What the pack owns is the *contents*, and therefore
 * what a batch of them consumes and what one is worth in stock.
 *
 * Deliberately not the Food & Beverage combo (`menu_combos`): that one prices a
 * menu and expands at sale time, because you cannot put a "meal deal" on a shelf.
 * A hamper is assembled, wrapped, counted at stock-take and scanned like any
 * other article — see GiftPackService for why the stock moves at build time.
 */
class GiftPack extends Model
{
    use BelongsToTenant, LogsAuditActivity;

    protected $fillable = [
        'code', 'name', 'product_id', 'occasion_tag',
        'wrap_option_id', 'notes', 'is_active', 'created_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected $attributes = [
        'is_active' => true,
    ];

    /** The hamper as it is sold: the SKU that goes on the shelf and is scanned. */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** How it is presented — a hamper is normally sold in the basket it was built in. */
    public function wrapOption(): BelongsTo
    {
        return $this->belongsTo(GiftWrapOption::class, 'wrap_option_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(GiftPackItem::class, 'pack_id')->orderBy('sort_order')->orderBy('id');
    }

    public function builds(): HasMany
    {
        return $this->hasMany(GiftPackBuild::class, 'pack_id');
    }

    /**
     * What one hamper costs to fill, at what its contents are valued at right
     * now. A quote for the next build, not a historical figure — every build
     * stores the cost it actually went in at (see GiftPackBuild::$unit_cost),
     * because component costs move and October's margin must not be rewritten in
     * December.
     */
    public function estimatedCost(): float
    {
        return round((float) $this->items->sum(
            fn (GiftPackItem $item) => (float) $item->quantity * $item->unitCost(),
        ), 4);
    }

    /**
     * What the contents would fetch if they were sold loose. The number the shop
     * argues about when pricing a hamper — a pack priced above it is a hard sell,
     * and one priced far below it is giving stock away by the basket.
     */
    public function contentsValue(): float
    {
        return round((float) $this->items->sum(
            fn (GiftPackItem $item) => (float) $item->quantity * (float) ($item->product?->selling_price ?? 0),
        ), 2);
    }
}
