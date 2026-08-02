<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasCustomFields;
use App\Models\Concerns\LogsAuditActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Scout\Searchable;

class Product extends Model
{
    use BelongsToTenant, HasCustomFields, LogsAuditActivity, Searchable, SoftDeletes;

    protected $fillable = [
        'sku', 'barcode', 'rfid_tag', 'name', 'description',
        'category_id', 'brand_id', 'unit_id', 'hsn_code',
        'cost_price', 'selling_price', 'tax_percent',
        'min_stock_level', 'max_stock_level', 'reorder_level', 'reorder_quantity', 'preferred_supplier_id',
        'image', 'attributes', 'track_expiry', 'track_batch', 'track_serial',
        'has_variants', 'is_service', 'is_active',
        // The warranty *policy* — "six months on the sole". A property of the
        // article, copied onto each card at issue rather than referenced, so
        // shortening next season's terms cannot cancel cover already given.
        'warranty_months', 'warranty_terms',
        // Retail plugin (garment & footwear): the style this SKU was cut from.
        // A style is thirty products once it is cut in six sizes and five
        // colours, so the link points this way; the relation itself is attached
        // by RetailPlugin::boot() so core never depends on the vertical.
        'style_id',
        // Pharmacy (Medical Store): drug schedule + the fields a chemist
        // actually substitutes and reorders on.
        'drug_schedule', 'composition', 'manufacturer',
        // Jewellery: an ornament has no fixed price — it is worth its metal at
        // today's rate plus making, so these replace selling_price entirely.
        'metal', 'purity', 'gross_weight', 'net_weight', 'stone_weight',
        'stone_value', 'making_charge_type', 'making_charge_value',
        'wastage_percent',
        // The hallmark the piece carries. `huid` is the mark itself; the centre
        // and date are its provenance, and the charge is a real line on the bill.
        'huid', 'hallmark_centre', 'hallmarked_on', 'hallmark_charge',
        // Optical: lens specification and the power range it can be made in.
        'lens_type', 'lens_material', 'lens_index', 'lens_coating',
        'min_sph', 'max_sph', 'max_cyl', 'is_frame',
        /**
         * Optical plugin: what the frame actually *is*. `is_frame` above only
         * says which slot of the order form an article belongs in; these are
         * what a customer asks for and a fitter needs — the shape, the rim, the
         * material, and the boxed marking stamped inside the temple
         * (52▫18-140: lens width, bridge, temple length in millimetres).
         */
        'frame_shape', 'frame_rim', 'frame_material', 'frame_gender', 'frame_colour',
        'frame_eye_size', 'frame_bridge_size', 'frame_temple_length', 'frame_tray',
        // Restaurant: which station cooks it, and how long it takes.
        'kitchen_station', 'prep_minutes',
        // Food & Beverage plugin: the menu card's own attributes. `is_available`
        // is the sold-out switch and is distinct from `is_active` — a dish off
        // tonight is not a dish withdrawn from the menu.
        'food_type', 'is_available', 'menu_sort_order',
        // A meal deal. The bundle's contents live in menu_combos; this flag is on
        // the product so the menu card and the POS grid can badge it without a
        // join for a boolean.
        'is_combo',
        // Agriculture plugin: what a farmer asks for. A pesticide is bought by
        // its technical name, a fertiliser by its grade and a seed by its crop and
        // germination — none of which is expressible as a brand or an SKU.
        'agri_input_type', 'crop', 'variety',
        'technical_name', 'nutrient_content', 'germination_percent',
        // Grocery plugin, supermarket trade: alcohol, tobacco, blades. The only
        // thing an unattended lane can decide it is not competent to sell, so a
        // scan of one parks the basket until a supervisor has looked at the
        // customer. Meaningless at a manned till, and harmless there.
        'is_age_restricted',
        /**
         * Toys plugin: who the article is for, and what is dangerous about it.
         * Distinct from `is_age_restricted` above, which is about the *buyer*
         * being old enough (spirits, blades) — these two are about the user being
         * young enough, and they are what the counter's safety check reads. The
         * relations behind them are attached by ToysPlugin::boot().
         */
        'toy_age_grade_id', 'toy_safety_category_id',
        /**
         * Cosmetics plugin: which shade this article is, and how long it lasts
         * once opened. The shade also lives on the variant — both shop styles are
         * real, one SKU per shade or one range with forty variants — and
         * CosmeticsPlugin::shadeFor() is the one place that precedence is
         * resolved. `pao_months` is the "6M" in the open-jar symbol, copied onto
         * a unit as it is opened rather than read live, so correcting it here
         * never moves the death date of a tester already on the counter.
         */
        'cosmetic_shade_id', 'pao_months',
        /**
         * Book Store plugin: what the article *is*, as opposed to what this shop
         * calls it. The ISBN is the one identifier here the tenant does not
         * issue — it is assigned by the publisher, is the same in every shop on
         * earth, and is also the EAN barcode printed on the back cover, which is
         * why BookStorePlugin's observer mirrors it onto `barcode`.
         *
         * `book_printed_price` is deliberately distinct from `selling_price`: in
         * this trade the price is printed on the cover by somebody else, and the
         * shop's margin is a discount off *that*.
         */
        'book_isbn13', 'book_isbn10', 'book_publisher_id', 'book_language_id',
        'book_binding', 'book_edition', 'book_pages', 'book_published_on',
        'book_series', 'book_volume', 'book_printed_price',
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
        'hallmarked_on' => 'date',
        'hallmark_charge' => 'decimal:2',
        'lens_index' => 'decimal:2',
        'min_sph' => 'decimal:2',
        'max_sph' => 'decimal:2',
        'max_cyl' => 'decimal:2',
        'is_frame' => 'boolean',
        // Optical plugin: the boxed marking, in millimetres.
        'frame_eye_size' => 'integer',
        'frame_bridge_size' => 'integer',
        'frame_temple_length' => 'integer',
        'is_age_restricted' => 'boolean',
        'is_active' => 'boolean',
        'warranty_months' => 'integer',
        // Food & Beverage plugin.
        'is_available' => 'boolean',
        'prep_minutes' => 'integer',
        'menu_sort_order' => 'integer',
        'is_combo' => 'boolean',
        // Agriculture plugin.
        'germination_percent' => 'decimal:2',
        // Book Store plugin.
        'book_pages' => 'integer',
        'book_published_on' => 'date',
        'book_printed_price' => 'decimal:2',
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

    /** The stones set into this ornament, each with its own grades and certificate. */
    public function stones(): HasMany
    {
        return $this->hasMany(ProductStone::class);
    }

    /**
     * Roll the stone register up onto the product.
     *
     * `stone_value` and `stone_weight` are what the pricing service and the
     * weight slip read, and neither of them knows the register exists — so the
     * two numbers are recomputed here whenever a line moves. The register is the
     * source of truth *only while it has lines*: emptying it leaves the last
     * totals alone rather than zeroing a piece whose stones were only ever
     * described as a lump sum, which is still a legitimate way to enter one.
     */
    public function syncStoneTotals(): void
    {
        $stones = $this->stones()->get();

        if ($stones->isEmpty()) {
            return;
        }

        $this->forceFill([
            'stone_value' => round((float) $stones->sum('value'), 2),
            'stone_weight' => round($stones->sum(fn (ProductStone $s) => $s->gramWeight()), 3),
        ])->save();
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

    /** Cards issued against sales of this article. */
    public function warranties(): HasMany
    {
        return $this->hasMany(ProductWarranty::class);
    }

    /**
     * Does this article carry cover? Zero and null are both "no", but they mean
     * different things upstream — null is "nobody has said", 0 is "explicitly
     * sold without cover" — so the column keeps them apart and only this
     * question collapses them.
     */
    public function hasWarrantyPolicy(): bool
    {
        return (int) $this->warranty_months > 0;
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
