<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsAuditActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Scout\Searchable;

class Product extends Model
{
    use BelongsToTenant, LogsAuditActivity, Searchable, SoftDeletes;

    protected $fillable = [
        'sku', 'barcode', 'rfid_tag', 'name', 'description',
        'category_id', 'brand_id', 'unit_id', 'hsn_code',
        'cost_price', 'selling_price', 'tax_percent',
        'min_stock_level', 'max_stock_level', 'reorder_level', 'reorder_quantity', 'preferred_supplier_id',
        'image', 'attributes', 'track_expiry', 'track_batch', 'track_serial',
        'has_variants', 'is_service', 'is_active',
        // Pharmacy (Medical Store): drug schedule + the fields a chemist
        // actually substitutes and reorders on.
        'drug_schedule', 'composition', 'manufacturer',
        // Jewellery: an ornament has no fixed price — it is worth its metal at
        // today's rate plus making, so these replace selling_price entirely.
        'metal', 'purity', 'gross_weight', 'net_weight', 'stone_weight',
        'stone_value', 'making_charge_type', 'making_charge_value',
        'wastage_percent', 'huid',
        // Optical: lens specification and the power range it can be made in.
        'lens_type', 'lens_material', 'lens_index', 'lens_coating',
        'min_sph', 'max_sph', 'max_cyl', 'is_frame',
        // Restaurant: which station cooks it, and how long it takes.
        'kitchen_station', 'prep_minutes',
    ];

    /** Schedules that may not be sold without a prescription. */
    public const PRESCRIPTION_SCHEDULES = ['h', 'h1', 'x'];

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
        'is_service' => 'boolean',
        'gross_weight' => 'decimal:3',
        'net_weight' => 'decimal:3',
        'stone_weight' => 'decimal:3',
        'stone_value' => 'decimal:2',
        'making_charge_value' => 'decimal:2',
        'wastage_percent' => 'decimal:3',
        'lens_index' => 'decimal:2',
        'min_sph' => 'decimal:2',
        'max_sph' => 'decimal:2',
        'max_cyl' => 'decimal:2',
        'is_frame' => 'boolean',
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

    /**
     * The `products` Meilisearch index is shared by every tenant; `tenant_id`
     * is emitted on each document and declared filterable (see config/scout.php)
     * so searches are constrained with ->where('tenant_id', ...).
     */
    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'sku' => $this->sku,
            'barcode' => $this->barcode,
            'name' => $this->name,
            'description' => $this->description,
            'hsn_code' => $this->hsn_code,
            'is_active' => (bool) $this->is_active,
        ];
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

    /**
     * Is this a scheduled medicine that may not leave the counter without a
     * prescription? Schedule G carries a caution label but needs no slip, so it
     * is deliberately not in the list.
     */
    public function requiresPrescription(): bool
    {
        return in_array($this->drug_schedule, self::PRESCRIPTION_SCHEDULES, true);
    }

    /**
     * Is this an ornament whose price comes from the day's metal rate rather
     * than a stored selling price?
     *
     * Both a metal and a net weight are required: a metal with no weight cannot
     * be valued, and quietly pricing it at zero would put a gold chain on a bill
     * for the making charge alone.
     */
    public function isRatePriced(): bool
    {
        return $this->metal !== null && (float) $this->net_weight > 0;
    }

    /** Is this a spectacle lens, made to a prescription rather than sold off a shelf? */
    public function isLens(): bool
    {
        return $this->lens_type !== null;
    }

    /** Does a sale of this drug have to be entered in a retained register? */
    public function needsRegisterEntry(): bool
    {
        return in_array($this->drug_schedule, PrescriptionDispense::REGISTERED_SCHEDULES, true);
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
