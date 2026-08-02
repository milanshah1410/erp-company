<?php

declare(strict_types=1);

namespace App\Modules\Industry\Stationery\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a booklist: a product, how many of it, and what the school called
 * it.
 *
 * The `remark` is not a note. The parent is standing at the counter holding the
 * photocopied sheet the line was transcribed from, and they will compare — so the
 * school's own wording ("Ruled long book, 172 pages") is kept verbatim beside the
 * shop's catalogue name for the same thing. Losing it turns every substitution
 * into an argument the shop cannot win.
 */
class ClassKitItem extends Model
{
    use BelongsToTenant;

    protected $table = 'stationery_class_kit_items';

    protected $fillable = [
        'kit_id', 'product_id', 'variant_id', 'quantity',
        'is_optional', 'remark', 'sort_order',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'is_optional' => 'boolean',
        'sort_order' => 'integer',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'quantity' => 1,
        'is_optional' => false,
        'sort_order' => 0,
    ];

    public function kit(): BelongsTo
    {
        return $this->belongsTo(ClassKit::class, 'kit_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    /** What the school called it, falling back to what the shop calls it. */
    public function printedName(): string
    {
        return $this->remark ?: (string) ($this->product?->name ?? '');
    }
}
