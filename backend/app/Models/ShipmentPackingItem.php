<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsAuditActivity;
use App\Support\TradeCatalogue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a shipment's packing list (SRS §24, export chain) — a group of
 * like packages: so many cartons of one item, under one set of shipping marks.
 *
 * The line is what customs, the carrier and the buyer all read the cargo from,
 * so it carries the physical facts (package type and count, quantity inside,
 * net and gross weight, per-package dimensions, HS code) rather than money;
 * value stays on the commercial invoice.
 *
 * `cbm` is derived on every write from the per-package dimensions × the package
 * count, and only accepted as entered when the dimensions are not known — the
 * same "never trust the client with a derived column" rule ShipmentExpense
 * follows for base_amount. The parent shipment's packages / weight / CBM header
 * columns are then the roll-up of these lines (Shipment::syncCargoTotals).
 */
class ShipmentPackingItem extends Model
{
    use BelongsToTenant, LogsAuditActivity;

    protected $fillable = [
        'shipment_id', 'shipment_container_id',
        'sales_item_id', 'purchase_item_id', 'product_id',
        'description', 'marks_numbers', 'package_type', 'package_count',
        'quantity', 'uom', 'net_weight_kg', 'gross_weight_kg',
        'length_cm', 'width_cm', 'height_cm', 'cbm',
        'hs_code', 'remarks', 'sort_order',
    ];

    protected $casts = [
        'package_count' => 'integer',
        'quantity' => 'decimal:3',
        'net_weight_kg' => 'decimal:3',
        'gross_weight_kg' => 'decimal:3',
        'length_cm' => 'decimal:2',
        'width_cm' => 'decimal:2',
        'height_cm' => 'decimal:2',
        'cbm' => 'decimal:3',
        'sort_order' => 'integer',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $item): void {
            $measured = $item->measuredCbm();
            if ($measured !== null) {
                $item->cbm = $measured;
            }
        });
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    /** The box this line is stuffed into, once stuffing is planned. */
    public function container(): BelongsTo
    {
        return $this->belongsTo(ShipmentContainer::class, 'shipment_container_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** The export order line this packs. */
    public function salesItem(): BelongsTo
    {
        return $this->belongsTo(SalesItem::class);
    }

    /** The import order line this packs. */
    public function purchaseItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseItem::class);
    }

    public function packageLabel(): string
    {
        return TradeCatalogue::packageLabel($this->package_type);
    }

    /**
     * Volume from the measured box: L × W × H in centimetres is cubic
     * centimetres, ÷ 1,000,000 is one package's CBM, × the count is the line's.
     * Null when any dimension is missing, which means "keep what was entered".
     */
    public function measuredCbm(): ?float
    {
        $l = (float) $this->length_cm;
        $w = (float) $this->width_cm;
        $h = (float) $this->height_cm;

        if ($l <= 0 || $w <= 0 || $h <= 0) {
            return null;
        }

        return round($l * $w * $h / 1_000_000 * max(1, (int) $this->package_count), 3);
    }
}
