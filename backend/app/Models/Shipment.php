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

    /**
     * Cost heads that are the same money as the header's freight_amount /
     * insurance_amount — see landedCostComponents(), which counts them once.
     */
    public const FREIGHT_HEADS = ['ocean_freight', 'air_freight', 'insurance'];

    /** Terminal statuses. */
    public const CLOSED_STATUSES = ['delivered', 'closed', 'cancelled'];

    /** Statuses that carry no reportable figures — see scopeReportable(). */
    public const NON_REPORTABLE = ['draft', 'cancelled'];

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
        // Carrier booking and the cut-offs the desk works backwards from.
        'booking_number', 'booking_date', 'si_cutoff', 'cargo_cutoff',
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
        // Shipping bill (export): what it was assessed on and what it claims.
        'fob_value', 'drawback_amount', 'rodtep_amount', 'leo_date',
        'payment_status', 'amount_settled', 'payment_due_date', 'lc_number', 'lc_expiry',
        'remarks', 'created_by',
    ];

    protected $casts = [
        'shipment_date' => 'date',
        'bl_awb_date' => 'date',
        'booking_date' => 'date',
        'si_cutoff' => 'date',
        'cargo_cutoff' => 'date',
        'leo_date' => 'date',
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
        'fob_value' => 'decimal:2',
        'drawback_amount' => 'decimal:2',
        'rodtep_amount' => 'decimal:2',
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

    /** The packing list — the cargo, package by package. */
    public function packingItems(): HasMany
    {
        return $this->hasMany(ShipmentPackingItem::class)->orderBy('sort_order')->orderBy('id');
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

    /**
     * Shipments whose figures are worth reporting on: a draft was never
     * committed and a cancellation never happened, so counting either would
     * overstate every lane and every margin. The Shipment Profit board and the
     * module's money reports all read through here.
     */
    public function scopeReportable(Builder $query): Builder
    {
        return $query->whereNotIn($query->qualifyColumn('status'), self::NON_REPORTABLE);
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

    /** FOB value off the shipping bill, converted to base currency. */
    public function fobValueBase(): float
    {
        return round((float) $this->fob_value * (float) $this->exchange_rate, 2);
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
     * Roll the packing list up onto the header's cargo columns, so the B/L, the
     * booking and every board read the same packages / weight / volume the
     * packing list declares.
     *
     * Called after every packing-line write. With no lines the header is left
     * alone: a shipment can be hand-keyed with totals and no packing list, and
     * removing the last line should not wipe what the desk typed.
     */
    public function syncCargoTotals(): void
    {
        $lines = $this->packingItems()->get();
        if ($lines->isEmpty()) {
            return;
        }

        $this->update([
            'packages' => (int) $lines->sum('package_count'),
            'gross_weight_kg' => round((float) $lines->sum('gross_weight_kg'), 3),
            'net_weight_kg' => round((float) $lines->sum('net_weight_kg'), 3),
            'cbm' => round((float) $lines->sum('cbm'), 3),
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
     * The export chain, step by step:
     *   Sales Order → Packing List → Container Booking → Shipping Bill → ETD →
     *   Customer Invoice → Dispatch
     *
     * Each step reports whether it is done and the reference that proves it, so
     * the Export Chain panel is a read of the shipment rather than seven
     * client-side rules that drift from the ones the actions enforce.
     *
     * @return list<array{key: string, label: string, done: bool, detail: ?string}>
     */
    public function exportChain(): array
    {
        $packed = $this->packingItemCount();
        $boxes = $this->relationLoaded('containers')
            ? $this->containers->count()
            : (int) ($this->containers_count ?? $this->containers()->count());

        $filed = $this->customs_entry_type === 'shipping_bill' && $this->customs_entry_number;

        $steps = [
            ['key' => 'sales_order', 'label' => 'Sales Order', 'done' => (bool) $this->sales_order_id,
                'detail' => $this->salesOrder?->so_number],
            ['key' => 'packing_list', 'label' => 'Packing List', 'done' => $packed > 0,
                'detail' => $packed > 0 ? "{$packed} line(s), {$this->packages} package(s)" : null],
            ['key' => 'booking', 'label' => 'Container Booking', 'done' => (bool) $this->booking_number,
                'detail' => $this->booking_number
                    ? trim($this->booking_number.($boxes ? " · {$boxes} box(es)" : ''))
                    : null],
            ['key' => 'shipping_bill', 'label' => 'Shipping Bill', 'done' => (bool) $filed,
                'detail' => $filed
                    ? $this->customs_entry_number.($this->leo_date ? ' · LEO '.$this->leo_date->toDateString() : '')
                    : null],
            ['key' => 'etd', 'label' => 'ETD', 'done' => (bool) $this->atd,
                'detail' => $this->atd
                    ? 'Departed '.$this->atd->toDateString()
                    : ($this->etd ? 'ETD '.$this->etd->toDateString() : null)],
            ['key' => 'customer_invoice', 'label' => 'Customer Invoice', 'done' => (bool) $this->invoice_id,
                'detail' => $this->invoice?->invoice_number ?? $this->invoice_number],
            ['key' => 'dispatch', 'label' => 'Dispatch', 'done' => (bool) $this->dispatch_plan_id,
                'detail' => $this->dispatchPlan?->dispatch_number],
        ];

        return $steps;
    }

    /**
     * The import chain, step by step:
     *   Purchase Order → Supplier Invoice → Bill of Lading → Container → Vessel →
     *   Freight → Customs → Warehouse Receipt
     *
     * The counterpart of exportChain(): each step reports whether it is done and
     * the reference that proves it, so the Import Chain panel is a read of the
     * shipment rather than eight client-side rules that drift from the ones
     * ShipmentIntegrationController enforces.
     *
     * @return list<array{key: string, label: string, done: bool, detail: ?string}>
     */
    public function importChain(): array
    {
        $isAir = $this->mode === 'air';

        $containers = $this->relationLoaded('containers') ? $this->containers : $this->containers()->get();
        $boxes = $containers->count();
        $overdue = $containers->filter(fn (ShipmentContainer $box) => $box->isAccruingDetention())->count();

        // Freight is the head the leg turns on; the rest of the logistics cost is
        // what the profit board will absorb, so both belong on the one step.
        $expenses = $this->relationLoaded('expenses') ? $this->expenses : $this->expenses()->get();
        $freight = round((float) $expenses
            ->whereIn('expense_type', ['ocean_freight', 'air_freight'])
            ->sum('base_amount'), 2);
        $absorbed = round((float) $expenses->where('is_billable', false)->sum('base_amount'), 2);

        $filed = $this->customs_entry_type === 'bill_of_entry' && $this->customs_entry_number;
        $sailing = match (true) {
            (bool) $this->atd => 'sailed '.$this->atd->toDateString(),
            (bool) $this->etd => 'ETD '.$this->etd->toDateString(),
            default => null,
        };

        return [
            ['key' => 'purchase_order', 'label' => 'Purchase Order', 'done' => (bool) $this->purchase_order_id,
                'detail' => $this->purchaseOrder?->po_number],
            ['key' => 'supplier_invoice', 'label' => 'Supplier Invoice', 'done' => (bool) $this->purchase_invoice_id,
                // Falls back to the number off the supplier's commercial invoice,
                // which the desk keys on the header before the bill is booked.
                'detail' => $this->purchaseInvoice?->invoice_number ?? $this->invoice_number],
            ['key' => 'bill_of_lading', 'label' => $isAir ? 'Air Waybill' : 'Bill of Lading',
                'done' => (bool) $this->bl_awb_number,
                'detail' => $this->bl_awb_number
                    ? $this->bl_awb_number.($this->bl_awb_date ? ' · '.$this->bl_awb_date->toDateString() : '')
                    : null],
            ['key' => 'containers', 'label' => $isAir ? 'ULDs' : 'Container', 'done' => $boxes > 0,
                'detail' => $boxes > 0
                    ? "{$boxes} box(es)".($overdue > 0 ? " · {$overdue} past free days" : '')
                    : null],
            ['key' => 'vessel', 'label' => $isAir ? 'Flight' : 'Vessel',
                'done' => (bool) ($this->vessel_name ?: $this->voyage_number),
                'detail' => collect([
                    $this->vessel_name,
                    $this->voyage_number,
                    $sailing,
                ])->filter()->implode(' · ') ?: null],
            ['key' => 'freight', 'label' => 'Freight', 'done' => $freight > 0,
                'detail' => $freight > 0
                    ? number_format($freight, 2).' booked'.($absorbed > $freight ? ' · '.number_format($absorbed, 2).' logistics cost' : '')
                    : ($absorbed > 0 ? number_format($absorbed, 2).' logistics cost booked' : null)],
            ['key' => 'customs', 'label' => 'Customs — Bill of Entry', 'done' => (bool) $filed,
                'detail' => $filed
                    ? $this->customs_entry_number.' · '.str_replace('_', ' ', $this->customs_status)
                        .((float) $this->duty_total > 0 ? ' · duty '.number_format((float) $this->duty_total, 2) : '')
                    : null],
            ['key' => 'warehouse_receipt', 'label' => 'Warehouse Receipt', 'done' => (bool) $this->goods_receipt_id,
                'detail' => $this->goodsReceipt?->grn_number],
        ];
    }

    /** Packing lines on this shipment, without re-querying what is already loaded. */
    public function packingItemCount(): int
    {
        if ($this->relationLoaded('packingItems')) {
            return $this->packingItems->count();
        }

        return (int) ($this->packing_items_count ?? $this->packingItems()->count());
    }

    /**
     * What it costs to land this cargo, split the way a goods receipt's landed
     * cost expects it: freight (incl. marine insurance), customs duty, and every
     * other logistics charge the desk booked.
     *
     * This is the single source for cost across the module — the Shipment Profit
     * board and the warehouse receipt both read it, so the stock's landed cost
     * and the shipment's P&L can never tell two different stories.
     *
     * Each component is counted once. Freight and duty each reach a shipment two
     * ways: declared on the header (the CIF add-ons, the assessed duty) and
     * booked as a logistics cost when the carrier's or CHA's bill arrives.
     * Summing both would overstate every consignment, so the header wins where
     * it is filled in and the booked cost stands in where it is not. Billable
     * costs are recovered from the counterparty, so they never load the cargo.
     *
     * @return array{freight: float, duty: float, other: float, billable: float}
     */
    public function landedCostComponents(): array
    {
        $expenses = $this->relationLoaded('expenses') ? $this->expenses : $this->expenses()->get();
        $own = $expenses->where('is_billable', false);
        $sum = fn (array $heads) => round((float) $own->whereIn('expense_type', $heads)->sum('base_amount'), 2);

        $headerFreight = $this->freightInsuranceBase();
        $headerDuty = (float) $this->duty_total;

        return [
            'freight' => $headerFreight > 0 ? $headerFreight : $sum(self::FREIGHT_HEADS),
            'duty' => $headerDuty > 0 ? $headerDuty : $sum(['customs_duty']),
            // Terminal handling, CFS, CHA, inland transport, warehousing,
            // detention, demurrage, documentation, bank charges …
            'other' => round((float) $own
                ->whereNotIn('expense_type', [...self::FREIGHT_HEADS, 'customs_duty'])
                ->sum('base_amount'), 2),
            'billable' => round((float) $expenses->where('is_billable', true)->sum('base_amount'), 2),
        ];
    }

    /**
     * Per-shipment P&L in base currency (Shipment Profit board).
     *
     * Revenue is the invoice value for exports; for imports there is no sale
     * here, so revenue is zero and the figures read as a landed-cost breakdown
     * (goods + duty + logistics) instead of a margin.
     *
     * @return array<string, float|null>
     */
    public function profitBreakdown(): array
    {
        $cost = $this->landedCostComponents();
        $logistics = round($cost['freight'] + $cost['other'], 2);

        $isExport = $this->direction === 'export';
        $revenue = $isExport ? $this->invoiceValueBase() : 0.0;
        $goodsCost = (float) $this->goods_cost;
        $totalCost = round($goodsCost + $cost['duty'] + $logistics, 2);
        $profit = round($revenue - $totalCost, 2);

        return [
            'revenue' => $revenue,
            'goods_cost' => $goodsCost,
            'duty' => $cost['duty'],
            'logistics_cost' => $logistics,
            'billable_cost' => $cost['billable'],
            'total_cost' => $totalCost,
            'profit' => $isExport ? $profit : null,
            'margin_percent' => $isExport && $revenue > 0 ? round($profit / $revenue * 100, 2) : null,
            // For imports the useful number is what the goods actually cost by
            // the time they reach the warehouse.
            'landed_cost' => $isExport ? null : $totalCost,
        ];
    }
}
