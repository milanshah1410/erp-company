<?php

declare(strict_types=1);

namespace App\Modules\Industry\Grocery\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsAuditActivity;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A combo pack: one barcode, several items off the shelf.
 *
 * The pack itself is a product — priced, taxed and scanned like anything else —
 * and this is the statement of what it breaks into. Keeping the two apart is what
 * lets POS, GST and the stock ledger stay ignorant of packs: they see a product,
 * and only the stock pass at settle time needs to know there are three items
 * behind it.
 *
 * Components are ordinary saleable products, not a bill of materials. Nothing is
 * assembled and nothing is put on a shelf as a pack — the counter hand picks the
 * items up when the barcode is scanned, which is exactly why the stock movement
 * has to be the components' and not the pack's.
 */
class ComboPack extends Model
{
    use BelongsToTenant, LogsAuditActivity;

    /**
     * Stated because the class is `ComboPack` and the table is prefixed with the
     * vertical that owns it. Eloquent would otherwise look for `combo_packs`,
     * which is a name a future gift-shop or pharmacy pack register could
     * legitimately want — the prefix is what keeps them apart.
     */
    protected $table = 'grocery_combo_packs';

    protected $fillable = [
        'product_id', 'name', 'component_value', 'component_cost',
        'starts_on', 'ends_on', 'is_active', 'notes', 'created_by',
    ];

    protected $casts = [
        'component_value' => 'decimal:2',
        'component_cost' => 'decimal:2',
        'starts_on' => 'date',
        'ends_on' => 'date',
        'is_active' => 'boolean',
    ];

    /** The sellable pack. */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ComboPackItem::class, 'grocery_combo_pack_id');
    }

    /**
     * Packs the till should expand right now.
     *
     * A window that has not opened or has closed leaves the product sellable at
     * its own price and stops it breaking apart — which is the right way round
     * for a festival hamper in February: the shop keeps the SKU and its history,
     * and simply stops offering the deal.
     */
    public function scopeLive(Builder $query, ?Carbon $on = null): Builder
    {
        $on = ($on ?? Carbon::now())->toDateString();

        return $query->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('starts_on')->orWhere('starts_on', '<=', $on))
            ->where(fn (Builder $q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $on));
    }

    /** Is this pack's window open on the given day? */
    public function isLiveOn(?Carbon $on = null): bool
    {
        $on = ($on ?? Carbon::now())->startOfDay();

        return (bool) $this->is_active
            && ! ($this->starts_on && $on->lt($this->starts_on->copy()->startOfDay()))
            && ! ($this->ends_on && $on->gt($this->ends_on->copy()->startOfDay()));
    }

    /**
     * Roll the components' current prices and costs back up onto the header.
     *
     * Snapshotted rather than computed on read: the advertised saving should be
     * the saving that was advertised, and a component price rise should not
     * quietly rewrite what last week's shelf label claimed.
     */
    public function revalue(): void
    {
        $this->loadMissing('items.product:id,selling_price,cost_price');

        $value = 0.0;
        $cost = 0.0;

        foreach ($this->items as $item) {
            $quantity = (float) $item->quantity;
            $value += (float) ($item->product?->selling_price ?? 0) * $quantity;
            $cost += (float) ($item->product?->cost_price ?? 0) * $quantity;
        }

        $this->forceFill([
            'component_value' => round($value, 2),
            'component_cost' => round($cost, 2),
        ])->save();
    }

    /**
     * What the customer saves against buying the items separately.
     *
     * Can legitimately be negative — a shop may price a pack above the sum of its
     * parts for the convenience of it — so this reports the number rather than
     * flooring it at zero and hiding a pricing mistake.
     *
     * Named `savingAmount`, not `saving`: Eloquent owns a static `saving()` for
     * the model event, and an instance method of that name is a fatal error at
     * class-load time. (The same trap Food & Beverage's MenuCombo hit.)
     */
    public function savingAmount(): float
    {
        return round((float) $this->component_value - $this->price(), 2);
    }

    /**
     * Margin in rupees on the pack as priced.
     *
     * On a trade that works on two to eight percent this is the number that
     * matters, and it is why the cost is snapshotted at all: a pack sold below
     * what its contents cost is not a thin deal, it is a leak.
     */
    public function marginAmount(): float
    {
        return round($this->price() - (float) $this->component_cost, 2);
    }

    /** The pack's own shelf price. */
    public function price(): float
    {
        return round((float) ($this->product?->selling_price ?? 0), 2);
    }
}
