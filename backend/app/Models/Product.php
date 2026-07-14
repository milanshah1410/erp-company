<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsAuditActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use BelongsToTenant, LogsAuditActivity, SoftDeletes;

    protected $fillable = [
        'sku', 'barcode', 'rfid_tag', 'name', 'description',
        'category_id', 'brand_id', 'unit_id', 'hsn_code',
        'cost_price', 'selling_price', 'tax_percent',
        'min_stock_level', 'max_stock_level', 'reorder_level', 'reorder_quantity', 'preferred_supplier_id',
        'image', 'attributes', 'track_expiry', 'track_batch', 'track_serial',
        'has_variants', 'is_active',
    ];

    protected $casts = [
        'attributes' => 'array',
        'cost_price' => 'decimal:2',
        'selling_price' => 'decimal:2',
        'tax_percent' => 'decimal:2',
        'min_stock_level' => 'decimal:3',
        'max_stock_level' => 'decimal:3',
        'reorder_level' => 'decimal:3',
        'reorder_quantity' => 'decimal:3',
        'track_expiry' => 'boolean',
        'track_batch' => 'boolean',
        'track_serial' => 'boolean',
        'has_variants' => 'boolean',
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (Product $product) {
            if (empty($product->barcode) && ! empty($product->sku)) {
                $product->barcode = $product->sku;
            }
        });
    }

    public function preferredSupplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'preferred_supplier_id');
    }

    public function batches(): HasMany
    {
        return $this->hasMany(Batch::class);
    }

    public function serialNumbers(): HasMany
    {
        return $this->hasMany(SerialNumber::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function priceLists(): HasMany
    {
        return $this->hasMany(PriceList::class);
    }

    public function stocks(): HasMany
    {
        return $this->hasMany(WarehouseStock::class);
    }

    public function stockLedgers(): HasMany
    {
        return $this->hasMany(StockLedger::class);
    }

    /** Total on-hand quantity across all warehouses. */
    public function totalStock(): float
    {
        return (float) $this->stocks()->sum('quantity');
    }
}
