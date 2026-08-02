<?php

declare(strict_types=1);

namespace App\Modules\Industry\Retail\Models;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsAuditActivity;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A style — the design number a garment shop actually trades in.
 *
 * "SS-2214" is what the buyer ordered, what the supplier invoiced, what the
 * salesman asks for on the floor and what gets marked down at the end of the
 * season. It is emphatically not a product: by the time it reaches the shelf it
 * is thirty products, one per size × colour, each with its own barcode and
 * stock. Modelling it as a product with variants and nothing above them loses
 * the level everybody's language lives at.
 *
 * The style therefore owns what is true of every piece cut from it — the fabric,
 * the fit, the season, the price — and the products it produced point back at
 * it (`products.style_id`).
 */
class GarmentStyle extends Model
{
    use BelongsToTenant, LogsAuditActivity;

    protected $fillable = [
        'code', 'name', 'category_id', 'brand_id', 'fabric_id', 'size_set_id',
        'gender', 'season', 'fit', 'sleeve', 'neck', 'pattern',
        'mrp', 'cost_price', 'image', 'description', 'is_active', 'created_by',
    ];

    protected $casts = [
        'mrp' => 'decimal:2',
        'cost_price' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saving(function (GarmentStyle $style) {
            if ($style->code) {
                $style->code = strtoupper(trim($style->code));
            }
        });
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function fabric(): BelongsTo
    {
        return $this->belongsTo(GarmentFabric::class, 'fabric_id');
    }

    public function sizeSet(): BelongsTo
    {
        return $this->belongsTo(GarmentSizeSet::class, 'size_set_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The products cut from this style.
     *
     * Declared here rather than as `Product::styles()` on the core model: the
     * dependency has to point plugin → core, so the plugin knows about Product
     * and core learns about the style only through the relation the plugin
     * attaches at boot (see RetailPlugin::boot).
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'style_id');
    }
}
