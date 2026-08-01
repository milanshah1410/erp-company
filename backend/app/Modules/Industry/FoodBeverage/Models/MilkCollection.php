<?php

declare(strict_types=1);

namespace App\Modules\Industry\FoodBeverage\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasDocumentNumber;
use App\Models\Concerns\LogsAuditActivity;
use App\Models\DeliveryRoute;
use App\Models\PurchaseInvoice;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One can of milk, from one farmer, on one shift.
 *
 * The farmer is a *supplier*. That is the load-bearing decision: a collection
 * settles into the same payables ledger as a feed bill, the dairy's ageing report
 * includes its farmers, and none of the accounting is re-implemented here. What
 * this model adds is the quality reading a supplier ledger has no vocabulary for.
 *
 * `rate_per_litre` and `amount` are stored, never derived on read. The chart will
 * be edited — a dairy revises its fat rate with the season — and what the farmer
 * was paid for Tuesday's can must not change when it is.
 */
class MilkCollection extends Model
{
    use BelongsToTenant, HasDocumentNumber, LogsAuditActivity;

    protected static string $numberSettingKey = 'milk_collection_number_prefix';

    protected $fillable = [
        'collection_number', 'supplier_id', 'collection_date', 'shift', 'milk_type',
        'quantity_litres', 'fat_percent', 'snf_percent', 'clr',
        'milk_rate_chart_id', 'rate_per_litre', 'amount',
        'delivery_route_id', 'purchase_invoice_id', 'settled_at', 'remarks', 'created_by',
    ];

    protected $casts = [
        'collection_date' => 'date',
        'quantity_litres' => 'decimal:3',
        'fat_percent' => 'decimal:2',
        'snf_percent' => 'decimal:2',
        'clr' => 'decimal:2',
        'rate_per_litre' => 'decimal:4',
        'amount' => 'decimal:2',
        'settled_at' => 'datetime',
    ];

    /** The farmer. */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function rateChart(): BelongsTo
    {
        return $this->belongsTo(MilkRateChart::class, 'milk_rate_chart_id');
    }

    public function route(): BelongsTo
    {
        return $this->belongsTo(DeliveryRoute::class, 'delivery_route_id');
    }

    /** The fortnightly bill this can was paid on, once it has been. */
    public function purchaseInvoice(): BelongsTo
    {
        return $this->belongsTo(PurchaseInvoice::class);
    }

    /**
     * Not yet paid for.
     *
     * Tested on `purchase_invoice_id` rather than `settled_at`: the invoice is the
     * fact, the timestamp is a convenience for reporting, and a settlement run
     * that filtered on the timestamp could double-bill a row whose invoice was
     * written but whose stamp failed.
     */
    public function scopeUnsettled(Builder $query): Builder
    {
        return $query->whereNull('purchase_invoice_id');
    }

    /** Kilograms of fat in the can — what a chilling plant actually contracts on. */
    public function fatKg(): float
    {
        return round((float) $this->quantity_litres * (float) $this->fat_percent / 100, 4);
    }

    /**
     * Price the can from a chart and write the money onto it.
     *
     * Kept on the model rather than in the service because it is also what the
     * entry screen previews while the counter is still typing, and two
     * implementations of the same arithmetic is how the preview stops matching
     * the slip.
     */
    public function applyRate(MilkRateChart $chart): self
    {
        $rate = $chart->rateFor((float) $this->fat_percent, (float) $this->snf_percent);

        $this->milk_rate_chart_id = $chart->id;
        $this->rate_per_litre = $rate;
        $this->amount = round((float) $this->quantity_litres * $rate, 2);

        return $this;
    }
}
