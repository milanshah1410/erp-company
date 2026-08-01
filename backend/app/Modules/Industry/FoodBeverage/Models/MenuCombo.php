<?php

declare(strict_types=1);

namespace App\Modules\Industry\FoodBeverage\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsAuditActivity;
use App\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A meal deal: one price, several dishes.
 *
 * The combo itself is a product — it is priced, taxed and put on the menu card
 * like anything else — and this is the statement of what it breaks into. Keeping
 * the two apart is what lets POS, GST and the menu screen stay ignorant of
 * combos entirely: they see a product, and only the consumption pass at settle
 * time needs to know there are three dishes behind it.
 *
 * Components are dishes, not ingredients. "One burger" stays one burger, and
 * what a burger is made of remains the burger's own recipe — so changing the bun
 * updates every combo that contains one.
 */
class MenuCombo extends Model
{
    use BelongsToTenant, LogsAuditActivity;

    protected $fillable = [
        'product_id', 'name', 'component_value', 'is_active', 'notes', 'created_by',
    ];

    protected $casts = [
        'component_value' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    /** The sellable combo. */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(MenuComboItem::class, 'menu_combo_id');
    }

    /**
     * What the components would have cost bought separately, rolled back up onto
     * the header.
     *
     * Snapshotted rather than computed on read so the advertised saving is the
     * saving that was advertised — a component price rise should not quietly
     * change what the menu claims the guest saved last week.
     */
    public function revalue(): void
    {
        $this->loadMissing('items.product:id,selling_price');

        $total = $this->items->sum(
            fn (MenuComboItem $item) => (float) ($item->product?->selling_price ?? 0) * (float) $item->quantity,
        );

        $this->forceFill(['component_value' => round($total, 2)])->save();
    }

    /**
     * What the guest saves against buying the components separately.
     *
     * Can legitimately be negative — a shop is free to price a combo above the
     * sum of its parts for convenience — so this reports the number rather than
     * flooring it at zero and hiding a pricing mistake.
     *
     * Named `savingAmount` and not `saving`: Eloquent already owns a static
     * `saving()` for the model event, and declaring an instance method of that
     * name is a fatal error at class-load time.
     */
    public function savingAmount(): float
    {
        return round((float) $this->component_value - (float) ($this->product?->selling_price ?? 0), 2);
    }
}
