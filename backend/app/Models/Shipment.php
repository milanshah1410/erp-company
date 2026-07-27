<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsAuditActivity;
use App\Support\TradeCatalogue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An import or export consignment — the single document the whole Import &
 * Export Logistics module revolves around. `direction` picks the leg: an import
 * is bought from an overseas supplier, an export is sold to an overseas
 * customer, and every board in the module (ETA/ETD, Pending Documents, Pending
 * Customs, Pending Payments, Container Status, Shipment Profit) is a filtered
 * projection of this table plus its children.
 *
 * Ports, carriers, forwarders, CHA and transporters all come from the module's
 * masters rather than being typed in, so a lane can be reported on
 * (port-wise / country-wise) and a carrier's shipments found from either side.
 *
 * Lifecycle: draft → booked → in_transit → arrived → customs → cleared →
 * delivered → closed, with cancel available before the goods move. Customs and
 * payment run as their own status tracks on the header, because a shipment can
 * be physically delivered while duty is disputed or the L/C is unpaid.
 *
 * Money: `invoice_value`, `freight_amount` and `insurance_amount` are in the
 * shipment's foreign `currency`; `exchange_rate` converts them to the tenant's
 * base currency, which every roll-up uses. Duty, `goods_cost` and
 * `amount_settled` are already base currency.
 */
class Shipment extends Model
{
    use BelongsToTenant, LogsAuditActivity;

    /** Statuses where the shipment is still in play — the "active" boards. */
    public const OPEN_STATUSES = ['draft', 'booked', 'in_transit', 'arrived', 'customs', 'cleared'];

    /** Terminal statuses. */
    public const CLOSED_STATUSES = ['delivered', 'closed', 'cancelled'];

    /** Allowed forward moves; cancel is handled separately. */
    public const TRANSITIONS = [
        'draft' => ['booked', 'cancelled'],
        'booked' => ['in_transit', 'cancelled'],
        'in_transit' => ['arrived'],
        'arrived' => ['customs'],
        'customs' => ['cleared'],
        'cleared' => ['delivered'],
        'delivered' => ['closed'],
        'closed' => [],
        'cancelled' => [],
    ];

    /** Milestone logged automatically when the shipment reaches a status. */
    public const STATUS_MILESTONES = [
        'booked' => 'booking_confirmed',
        'in_transit' => 'departed',
        'arrived' => 'arrived',
        'customs' => 'customs_filed',
        'cleared' => 'customs_cleared',
        'delivered' => 'delivered',
    ];

    protected $fillable = [
        'shipment_number', 'direction', 'status', 'shipment_date',
        'supplier_id', 'customer_id', 'purchase_order_id', 'sales_order_id', 'warehouse_id',
        // ERP documents this shipment produced (see ShipmentIntegrationController).
        'purchase_invoice_id', 'goods_receipt_id', 'invoice_id', 'dispatch_plan_id',
        'mode', 'incoterm', 'shipping_line_id', 'airline_id', 'freight_forwarder_id',
        'cha_agent_id', 'transporter_id',
        'bl_awb_number', 'bl_awb_date', 'vessel_name', 'voyage_number',
        'origin_country_id', 'destination_country_id',
        'port_of_loading_id', 'port_of_discharge_id', 'final_place',
        'etd', 'eta', 'atd', 'ata',
        'goods_description', 'packages', 'gross_weight_kg', 'net_weight_kg', 'cbm',
        'currency', 'exchange_rate', 'invoice_number', 'invoice_date',
        'invoice_value', 'freight_amount', 'insurance_amount', 'goods_cost',
        'customs_status', 'customs_entry_type', 'customs_entry_number', 'customs_entry_date',
        'icegate_reference', 'assessable_value',
        'duty_bcd', 'duty_igst', 'duty_cess', 'duty_other', 'duty_total',
        'duty_paid_at', 'customs_cleared_at',
        'payment_status', 'amount_settled', 'payment_due_date', 'lc_number', 'lc_expiry',
        'remarks', 'created_by',
    ];

    protected $casts = [
        'shipment_date' => 'date',
        'bl_awb_date' => 'date',
        'etd' => 'date',
        'eta' => 'date',
        'atd' => 'date',
        'ata' => 'date',
        'invoice_date' => 'date',
        'customs_entry_date' => 'date',
        'payment_due_date' => 'date',
        'lc_expiry' => 'date',
        'duty_paid_at' => 'datetime',
        'customs_cleared_at' => 'datetime',
        'exchange_rate' => 'decimal:6',
        'invoice_value' => 'decimal:2',
        'freight_amount' => 'decimal:2',
        'insurance_amount' => 'decimal:2',
        'goods_cost' => 'decimal:2',
        'assessable_value' => 'decimal:2',
        'duty_bcd' => 'decimal:2',
        'duty_igst' => 'decimal:2',
        'duty_cess' => 'decimal:2',
        'duty_other' => 'decimal:2',
        'duty_total' => 'decimal:2',
        'amount_settled' => 'decimal:2',
        'gross_weight_kg' => 'decimal:3',
        'net_weight_kg' => 'decimal:3',
        'cbm' => 'decimal:3',
    ];

    /**
     * Next document number for a leg. Imports and exports run separate series
     * off their own configurable prefix; the sequence continues from the latest
     * row of that leg (tenant-scoped, like every other numbered document).
     */
    public static function nextNumber(string $direction): string
    {
        $prefix = Setting::numberPrefix(
            $direction === 'export' ? 'export_shipment_number_prefix' : 'import_shipment_number_prefix'
        );

        $last = static::query()->where('direction', $direction)->orderByDesc('id')->value('shipment_number');
        $seq = $last ? (int) substr((string) strrchr($last, '-'), 1) : 0;

        return $prefix.'-'.str_pad((string) ($seq + 1), 6, '0', STR_PAD_LEFT);
    }

    // ---------------------------------------------------------------- relations

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /** Import leg: the vendor bill booked against this consignment. */
    public function purchaseInvoice(): BelongsTo
    {
        return $this->belongsTo(PurchaseInvoice::class);
    }

    /** Import leg: the GRN that took the cargo into stock. */
    public function goodsReceipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class);
    }

    /** Export leg: the customer invoice raised for this consignment. */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** Export leg: the dispatch that moved the cargo out. */
    public function dispatchPlan(): BelongsTo
    {
        return $this->belongsTo(DispatchPlan::class);
    }

    public function shippingLine(): BelongsTo
    {
        return $this->belongsTo(ShippingLine::class);
    }

    public function airline(): BelongsTo
    {
        return $this->belongsTo(Airline::class);
    }

    public function freightForwarder(): BelongsTo
    {
        return $this->belongsTo(FreightForwarder::class);
    }

    public function chaAgent(): BelongsTo
    {
        return $this->belongsTo(ChaAgent::class);
    }

    public function transporter(): BelongsTo
    {
        return $this->belongsTo(Transporter::class);
    }

    public function originCountry(): BelongsTo
    {
        return $this->belongsTo(Country::class, 'origin_country_id');
    }

    public function destinationCountry(): BelongsTo
    {
        return $this->belongsTo(Country::class, 'destination_country_id');
    }

    public function portOfLoading(): BelongsTo
    {
        return $this->belongsTo(Port::class, 'port_of_loading_id');
    }

    public function portOfDischarge(): BelongsTo
    {
        return $this->belongsTo(Port::class, 'port_of_discharge_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function containers(): HasMany
    {
        return $this->hasMany(ShipmentContainer::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(ShipmentDocument::class);
    }

    /** Documents still to be chased — exists so lists can withCount() them. */
    public function pendingDocuments(): HasMany
    {
        return $this->documents()->whereIn('status', ShipmentDocument::OPEN_STATUSES);
    }

    public function events(): HasMany
    {
        return $this->hasMany(ShipmentEvent::class);
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(ShipmentExpense::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(ShipmentPayment::class);
    }

    // ------------------------------------------------------------------ scopes

    /** Shipments still in play (everything but delivered / closed / cancelled). */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', self::OPEN_STATUSES);
    }

    public function scopeDirection(Builder $query, ?string $direction): Builder
    {
        return $direction ? $query->where('direction', $direction) : $query;
    }

    // ----------------------------------------------------------------- helpers

    /** The counterparty's name, whichever side of the trade it sits on. */
    public function partyName(): ?string
    {
        return $this->direction === 'import'
            ? $this->supplier?->name
            : $this->customer?->name;
    }

    /** The carrier actually moving the cargo — a shipping line or an airline. */
    public function carrierName(): ?string
    {
        return $this->mode === 'air'
            ? $this->airline?->name
            : $this->shippingLine?->name;
    }

    /** Goods value converted to the tenant's base currency. */
    public function invoiceValueBase(): float
    {
        return round((float) $this->invoice_value * (float) $this->exchange_rate, 2);
    }

    /** Freight + insurance in base currency — the CIF add-ons. */
    public function freightInsuranceBase(): float
    {
        return round(((float) $this->freight_amount + (float) $this->insurance_amount) * (float) $this->exchange_rate, 2);
    }

    /** Goods value still to be received (export) or paid (import), base currency. */
    public function outstandingAmount(): float
    {
        return round(max(0, $this->invoiceValueBase() - (float) $this->amount_settled), 2);
    }

    /** Days late against ETA (negative = still early). Null without both dates. */
    public function etaVarianceDays(): ?int
    {
        if (! $this->eta) {
            return null;
        }
        $against = $this->ata ?: now()->startOfDay();

        return (int) round($this->eta->diffInDays($against, false));
    }

    /**
     * Append a timeline entry. Status/customs/container transitions call this
     * with source 'system' so the timeline reads as one continuous story
     * alongside the manually typed updates.
     */
    public function logEvent(string $type, array $attributes = []): ShipmentEvent
    {
        return $this->events()->create([
            'event_type' => $type,
            'event_at' => $attributes['event_at'] ?? now(),
            'location' => $attributes['location'] ?? null,
            'description' => $attributes['description'] ?? TradeCatalogue::milestoneLabel($type),
            'source' => $attributes['source'] ?? 'system',
            'created_by' => $attributes['created_by'] ?? auth()->id(),
        ]);
    }

    /**
     * Recompute payment_status from the settlements booked so far. Called after
     * every payment write so the Pending Payments board never drifts from the
     * ledger underneath it.
     */
    public function syncPaymentStatus(): void
    {
        $settled = round((float) $this->payments()->sum('base_amount'), 2);
        $value = $this->invoiceValueBase();

        $this->update([
            'amount_settled' => $settled,
            'payment_status' => match (true) {
                $settled <= 0 => 'unpaid',
                $value > 0 && $settled + 0.01 >= $value => 'paid',
                default => 'partial',
            },
        ]);
    }

    /**
     * Per-shipment P&L in base currency (Shipment Profit board).
     *
     * Revenue is the invoice value for exports; for imports there is no sale
     * here, so revenue is zero and the figures read as a landed-cost breakdown
     * (goods + duty + logistics) instead of a margin. Billable expenses are
     * recovered from the counterparty and so are excluded from cost.
     *
     * @return array<string, float|null>
     */
    public function profitBreakdown(): array
    {
        $expenses = $this->relationLoaded('expenses') ? $this->expenses : $this->expenses()->get();
        $absorbed = round((float) $expenses->where('is_billable', false)->sum('base_amount'), 2);
        $billable = round((float) $expenses->where('is_billable', true)->sum('base_amount'), 2);

        $isExport = $this->direction === 'export';
        $revenue = $isExport ? $this->invoiceValueBase() : 0.0;
        $goodsCost = (float) $this->goods_cost;
        $duty = (float) $this->duty_total;
        $totalCost = round($goodsCost + $duty + $absorbed, 2);
        $profit = round($revenue - $totalCost, 2);

        return [
            'revenue' => $revenue,
            'goods_cost' => $goodsCost,
            'duty' => $duty,
            'logistics_cost' => $absorbed,
            'billable_cost' => $billable,
            'total_cost' => $totalCost,
            'profit' => $isExport ? $profit : null,
            'margin_percent' => $isExport && $revenue > 0 ? round($profit / $revenue * 100, 2) : null,
            // For imports the useful number is what the goods actually cost by
            // the time they reach the warehouse.
            'landed_cost' => $isExport ? null : round($goodsCost + $duty + $absorbed, 2),
        ];
    }
}
