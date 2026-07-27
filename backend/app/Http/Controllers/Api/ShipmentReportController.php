<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ExportsReports;
use App\Http\Controllers\Controller;
use App\Models\Shipment;
use App\Models\ShipmentContainer;
use App\Models\ShipmentExpense;
use App\Support\TradeCatalogue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * The Import & Export Logistics reports (SRS §24) — the eight exportable
 * registers the module's spec asks for:
 *
 *   Import Report · Export Report · Shipment Profit · Shipment Expense ·
 *   Customs Report · Container Report · Country-wise Sales · Port-wise Shipment
 *
 * These sit alongside the module's operational boards rather than replacing
 * them: a board is a work queue the desk acts on (open shipments, overdue
 * documents, boxes past their free days), while a report is a register over a
 * date range that leaves as CSV / Excel / PDF. Both read the same `shipments`
 * table and the same money rules, so the two can never tell different stories.
 *
 * Money is reported in the tenant's base currency throughout — foreign-currency
 * columns off the shipment header (`invoice_value`, `fob_value`, freight,
 * insurance) are multiplied by the shipment's `exchange_rate`, exactly as
 * Shipment's own helpers do. Duty, `goods_cost` and logistics costs are already
 * base currency.
 */
class ShipmentReportController extends Controller
{
    use ExportsReports;

    /** Cap on a single CSV / Excel / PDF download, as elsewhere in Reports. */
    private const EXPORT_LIMIT = 10000;

    // ------------------------------------------------------------ Import Report

    /**
     * Every import consignment over the range, with the buy-side figures: the
     * supplier and PO it came from, the lane and carrier it moved on, the cargo
     * declared, and what it cost to land (goods + duty + logistics).
     */
    public function importReport(Request $request)
    {
        $query = $this->shipmentScope($request, 'import')
            ->with([
                'supplier:id,name', 'purchaseOrder:id,po_number',
                'shippingLine:id,name', 'airline:id,name', 'chaAgent:id,name',
                'originCountry:id,code,name',
                'portOfLoading:id,code,name', 'portOfDischarge:id,code,name',
                'expenses:id,shipment_id,expense_type,base_amount,is_billable',
            ])
            ->orderByDesc('shipments.shipment_date')
            ->orderByDesc('shipments.id');

        $map = function (Shipment $s) {
            $cost = $s->landedCostComponents();

            return [
                'shipment_number' => $s->shipment_number,
                'shipment_date' => $s->shipment_date?->toDateString(),
                'status' => $s->status,
                'supplier' => $s->supplier?->name,
                'po_number' => $s->purchaseOrder?->po_number,
                'origin_country' => $s->originCountry?->name,
                'port_of_loading' => $s->portOfLoading?->code ?? $s->portOfLoading?->name,
                'port_of_discharge' => $s->portOfDischarge?->code ?? $s->portOfDischarge?->name,
                'mode' => $s->mode,
                'incoterm' => $s->incoterm,
                'carrier' => $s->carrierName(),
                'cha_agent' => $s->chaAgent?->name,
                'bl_awb_number' => $s->bl_awb_number,
                'etd' => $s->etd?->toDateString(),
                'eta' => $s->eta?->toDateString(),
                'ata' => $s->ata?->toDateString(),
                'eta_variance_days' => $s->etaVarianceDays(),
                'packages' => (int) $s->packages,
                'gross_weight_kg' => (float) $s->gross_weight_kg,
                'cbm' => (float) $s->cbm,
                'currency' => $s->currency,
                'invoice_value' => (float) $s->invoice_value,
                'invoice_value_base' => $s->invoiceValueBase(),
                'freight_insurance' => $cost['freight'],
                'duty' => $cost['duty'],
                'other_cost' => $cost['other'],
                // Same rule as the Shipment Profit board: for an import there is
                // no sale here, so the bottom line is what the cargo cost by the
                // time it reached the warehouse.
                'landed_cost' => round((float) $s->goods_cost + $cost['duty'] + $cost['freight'] + $cost['other'], 2),
                'customs_status' => $s->customs_status,
                'payment_status' => $s->payment_status,
                'outstanding' => $s->outstandingAmount(),
            ];
        };

        $columns = [
            'shipment_number' => 'Shipment #', 'shipment_date' => 'Date', 'status' => 'Status',
            'supplier' => 'Supplier', 'po_number' => 'PO', 'origin_country' => 'Origin',
            'port_of_loading' => 'POL', 'port_of_discharge' => 'POD', 'mode' => 'Mode',
            'incoterm' => 'Incoterm', 'carrier' => 'Carrier', 'cha_agent' => 'CHA',
            'bl_awb_number' => 'B/L — AWB', 'etd' => 'ETD', 'eta' => 'ETA', 'ata' => 'ATA',
            'eta_variance_days' => 'ETA Variance (days)', 'packages' => 'Packages',
            'gross_weight_kg' => 'Gross Wt (kg)', 'cbm' => 'CBM',
            'currency' => 'Currency', 'invoice_value' => 'Invoice Value',
            'invoice_value_base' => 'Goods Value', 'freight_insurance' => 'Freight + Insurance',
            'duty' => 'Duty', 'other_cost' => 'Other Logistics', 'landed_cost' => 'Landed Cost',
            'customs_status' => 'Customs', 'payment_status' => 'Payment', 'outstanding' => 'Outstanding',
        ];

        return $this->rowReport(
            $request, $query, $map, $columns, 'import-report',
            fn () => $this->legTotals($request, 'import'),
        );
    }

    // ------------------------------------------------------------ Export Report

    /**
     * Every export consignment over the range, with the sell-side figures: the
     * customer and SO it serves, the lane, the shipping bill it moved under, and
     * the incentives claimed on it (drawback / RoDTEP).
     */
    public function exportReport(Request $request)
    {
        $query = $this->shipmentScope($request, 'export')
            ->with([
                'customer:id,name', 'salesOrder:id,so_number',
                'shippingLine:id,name', 'airline:id,name', 'chaAgent:id,name',
                'destinationCountry:id,code,name',
                'portOfLoading:id,code,name', 'portOfDischarge:id,code,name',
            ])
            ->orderByDesc('shipments.shipment_date')
            ->orderByDesc('shipments.id');

        $map = fn (Shipment $s) => [
            'shipment_number' => $s->shipment_number,
            'shipment_date' => $s->shipment_date?->toDateString(),
            'status' => $s->status,
            'customer' => $s->customer?->name,
            'so_number' => $s->salesOrder?->so_number,
            'destination_country' => $s->destinationCountry?->name,
            'port_of_loading' => $s->portOfLoading?->code ?? $s->portOfLoading?->name,
            'port_of_discharge' => $s->portOfDischarge?->code ?? $s->portOfDischarge?->name,
            'final_place' => $s->final_place,
            'mode' => $s->mode,
            'incoterm' => $s->incoterm,
            'carrier' => $s->carrierName(),
            'cha_agent' => $s->chaAgent?->name,
            'bl_awb_number' => $s->bl_awb_number,
            'shipping_bill_number' => $s->customs_entry_type === 'shipping_bill' ? $s->customs_entry_number : null,
            'leo_date' => $s->leo_date?->toDateString(),
            'etd' => $s->etd?->toDateString(),
            'atd' => $s->atd?->toDateString(),
            'eta' => $s->eta?->toDateString(),
            'packages' => (int) $s->packages,
            'gross_weight_kg' => (float) $s->gross_weight_kg,
            'cbm' => (float) $s->cbm,
            'currency' => $s->currency,
            'invoice_value' => (float) $s->invoice_value,
            'invoice_value_base' => $s->invoiceValueBase(),
            'fob_value_base' => $s->fobValueBase(),
            'drawback_amount' => (float) $s->drawback_amount,
            'rodtep_amount' => (float) $s->rodtep_amount,
            'payment_status' => $s->payment_status,
            'amount_settled' => (float) $s->amount_settled,
            'outstanding' => $s->outstandingAmount(),
        ];

        $columns = [
            'shipment_number' => 'Shipment #', 'shipment_date' => 'Date', 'status' => 'Status',
            'customer' => 'Customer', 'so_number' => 'SO', 'destination_country' => 'Destination',
            'port_of_loading' => 'POL', 'port_of_discharge' => 'POD', 'final_place' => 'Final Place',
            'mode' => 'Mode', 'incoterm' => 'Incoterm', 'carrier' => 'Carrier', 'cha_agent' => 'CHA',
            'bl_awb_number' => 'B/L — AWB', 'shipping_bill_number' => 'Shipping Bill',
            'leo_date' => 'LEO Date', 'etd' => 'ETD', 'atd' => 'ATD', 'eta' => 'ETA',
            'packages' => 'Packages', 'gross_weight_kg' => 'Gross Wt (kg)', 'cbm' => 'CBM',
            'currency' => 'Currency', 'invoice_value' => 'Invoice Value',
            'invoice_value_base' => 'Invoice Value (base)', 'fob_value_base' => 'FOB Value',
            'drawback_amount' => 'Drawback', 'rodtep_amount' => 'RoDTEP',
            'payment_status' => 'Payment', 'amount_settled' => 'Realised', 'outstanding' => 'Outstanding',
        ];

        return $this->rowReport(
            $request, $query, $map, $columns, 'export-report',
            fn () => $this->legTotals($request, 'export'),
        );
    }

    // ----------------------------------------------------------- Shipment Profit

    /**
     * Per-shipment P&L, straight off Shipment::profitBreakdown() so the report
     * and the Shipment Profit board agree line for line. Exports carry revenue
     * and therefore a margin; imports read as a landed-cost breakdown, because
     * no sale happens on that leg.
     */
    public function shipmentProfit(Request $request)
    {
        $query = $this->shipmentScope($request)
            ->reportable()
            ->with([
                'supplier:id,name', 'customer:id,name',
                'expenses:id,shipment_id,expense_type,base_amount,is_billable',
            ])
            ->orderByDesc('shipments.shipment_date')
            ->orderByDesc('shipments.id');

        $map = fn (Shipment $s) => [
            'shipment_number' => $s->shipment_number,
            'shipment_date' => $s->shipment_date?->toDateString(),
            'direction' => $s->direction,
            'status' => $s->status,
            'party' => $s->partyName(),
            'currency' => $s->currency,
            'invoice_value' => (float) $s->invoice_value,
        ] + $s->profitBreakdown();

        $columns = [
            'shipment_number' => 'Shipment #', 'shipment_date' => 'Date', 'direction' => 'Leg',
            'status' => 'Status', 'party' => 'Party', 'currency' => 'Currency',
            'invoice_value' => 'Invoice Value', 'revenue' => 'Revenue', 'goods_cost' => 'Goods Cost',
            'duty' => 'Duty', 'logistics_cost' => 'Logistics', 'total_cost' => 'Total Cost',
            'billable_cost' => 'Recoverable', 'profit' => 'Profit', 'margin_percent' => 'Margin %',
            'landed_cost' => 'Landed Cost',
        ];

        return $this->rowReport($request, $query, $map, $columns, 'shipment-profit', function () use ($request) {
            // Exports and imports are totalled apart on purpose: pooling them
            // would report an import's landed cost as a loss against no revenue.
            $export = $this->costTotals($request, 'export');
            $import = $this->costTotals($request, 'import');
            $revenue = $export['revenue'];
            $exportCost = $export['total_cost'];

            return [
                'revenue' => $revenue,
                'goods_cost' => round($export['goods_cost'] + $import['goods_cost'], 2),
                'duty' => round($export['duty'] + $import['duty'], 2),
                'logistics_cost' => round($export['logistics_cost'] + $import['logistics_cost'], 2),
                'total_cost' => round($exportCost + $import['total_cost'], 2),
                'billable_cost' => round($export['billable_cost'] + $import['billable_cost'], 2),
                'profit' => round($revenue - $exportCost, 2),
                'margin_percent' => $revenue > 0 ? round(($revenue - $exportCost) / $revenue * 100, 2) : null,
                'import_landed_cost' => $import['total_cost'],
            ];
        });
    }

    // ---------------------------------------------------------- Shipment Expense

    /**
     * The logistics cost register: every charge booked against a shipment, with
     * the vendor who billed it and whether it is recovered from the counterparty.
     * `date_from` / `date_to` read the expense date here, not the shipment's.
     */
    public function shipmentExpense(Request $request)
    {
        $query = ShipmentExpense::query()
            ->join('shipments', 'shipments.id', '=', 'shipment_expenses.shipment_id')
            ->with(['shipment:id,shipment_number,direction,status', 'container:id,container_number'])
            ->select('shipment_expenses.*');

        foreach (['expense_type', 'vendor_type', 'payment_status', 'shipment_id'] as $filter) {
            if ($request->filled($filter)) {
                $query->where('shipment_expenses.'.$filter, $request->query($filter));
            }
        }
        if ($request->filled('direction')) {
            $query->where('shipments.direction', $request->query('direction'));
        }
        if ($request->filled('is_billable')) {
            $query->where('shipment_expenses.is_billable', $request->boolean('is_billable'));
        }
        if ($request->filled('date_from')) {
            $query->whereDate('shipment_expenses.expense_date', '>=', $request->query('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('shipment_expenses.expense_date', '<=', $request->query('date_to'));
        }
        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('shipments.shipment_number', 'like', "%{$search}%")
                    ->orWhere('shipment_expenses.vendor_name', 'like', "%{$search}%")
                    ->orWhere('shipment_expenses.invoice_number', 'like', "%{$search}%");
            });
        }

        $query->orderByDesc('shipment_expenses.expense_date')->orderByDesc('shipment_expenses.id');

        $map = fn (ShipmentExpense $e) => [
            'expense_date' => $e->expense_date?->toDateString(),
            'shipment_number' => $e->shipment?->shipment_number,
            'direction' => $e->shipment?->direction,
            'container_number' => $e->container?->container_number,
            'expense_type' => TradeCatalogue::expenseLabel($e->expense_type),
            'vendor_type' => TradeCatalogue::VENDOR_TYPES[$e->vendor_type] ?? $e->vendor_type,
            'vendor_name' => $e->vendor_name,
            'invoice_number' => $e->invoice_number,
            'currency' => $e->currency,
            'amount' => (float) $e->amount,
            'base_amount' => (float) $e->base_amount,
            'is_billable' => $e->is_billable ? 'Billable' : 'Absorbed',
            'payment_status' => $e->payment_status,
            'paid_amount' => (float) $e->paid_amount,
            'outstanding' => round((float) $e->base_amount - (float) $e->paid_amount, 2),
            'due_date' => $e->due_date?->toDateString(),
        ];

        $columns = [
            'expense_date' => 'Date', 'shipment_number' => 'Shipment #', 'direction' => 'Leg',
            'container_number' => 'Container', 'expense_type' => 'Cost Head',
            'vendor_type' => 'Vendor Type', 'vendor_name' => 'Vendor', 'invoice_number' => 'Bill #',
            'currency' => 'Currency', 'amount' => 'Amount', 'base_amount' => 'Amount (base)',
            'is_billable' => 'Recovery', 'payment_status' => 'Payment',
            'paid_amount' => 'Paid', 'outstanding' => 'Outstanding', 'due_date' => 'Due',
        ];

        return $this->rowReport($request, $query, $map, $columns, 'shipment-expenses', function () use ($request) {
            // Re-scope rather than reuse the paginated builder, so the footer and
            // the cost-head breakdown cover the whole filtered set.
            $scope = fn () => $this->expenseScope($request);

            // `lines` is reserved in MySQL, hence line_count.
            $agg = $scope()->selectRaw(
                'count(*) as line_count, sum(shipment_expenses.base_amount) as total,
                 sum(case when shipment_expenses.is_billable = 1 then shipment_expenses.base_amount else 0 end) as billable,
                 sum(shipment_expenses.paid_amount) as paid'
            )->first();

            $total = round((float) ($agg->total ?? 0), 2);
            $billable = round((float) ($agg->billable ?? 0), 2);
            $paid = round((float) ($agg->paid ?? 0), 2);

            $byHead = $scope()
                ->selectRaw('shipment_expenses.expense_type, sum(shipment_expenses.base_amount) as amount')
                ->groupBy('shipment_expenses.expense_type')
                ->orderByDesc('amount')
                ->get()
                ->map(fn ($row) => [
                    'expense_type' => $row->expense_type,
                    'label' => TradeCatalogue::expenseLabel($row->expense_type),
                    'amount' => round((float) $row->amount, 2),
                ])->all();

            return [
                'lines' => (int) ($agg->line_count ?? 0),
                'total_cost' => $total,
                'billable_cost' => $billable,
                // What the cargo actually carries once recoveries are taken out.
                'absorbed_cost' => round($total - $billable, 2),
                'paid_amount' => $paid,
                'outstanding' => round($total - $paid, 2),
                'by_head' => $byHead,
            ];
        });
    }

    // ----------------------------------------------------------- Customs Report

    /**
     * The customs register: the entry filed for each consignment, what it was
     * assessed on, the duty heads that came out of it and how long clearance
     * took. `date_from` / `date_to` read the customs entry date here.
     */
    public function customsReport(Request $request)
    {
        $query = $this->shipmentScope($request, null, 'customs_entry_date')
            ->reportable()
            ->with([
                'supplier:id,name', 'customer:id,name', 'chaAgent:id,name',
                'portOfDischarge:id,code,name',
            ])
            ->orderByDesc('shipments.customs_entry_date')
            ->orderByDesc('shipments.id');

        if ($request->filled('customs_entry_type')) {
            $query->where('shipments.customs_entry_type', $request->query('customs_entry_type'));
        }
        // The register is about filed entries; pass filed=0 to chase the ones
        // that still have no entry number against them.
        if ($request->filled('filed')) {
            $request->boolean('filed')
                ? $query->whereNotNull('shipments.customs_entry_number')
                : $query->whereNull('shipments.customs_entry_number');
        }

        $map = fn (Shipment $s) => [
            'shipment_number' => $s->shipment_number,
            'direction' => $s->direction,
            'party' => $s->partyName(),
            'entry_type' => $s->customs_entry_type,
            'entry_number' => $s->customs_entry_number,
            'entry_date' => $s->customs_entry_date?->toDateString(),
            'icegate_reference' => $s->icegate_reference,
            'port_of_discharge' => $s->portOfDischarge?->code ?? $s->portOfDischarge?->name,
            'cha_agent' => $s->chaAgent?->name,
            'assessable_value' => (float) $s->assessable_value,
            'duty_bcd' => (float) $s->duty_bcd,
            'duty_igst' => (float) $s->duty_igst,
            'duty_cess' => (float) $s->duty_cess,
            'duty_other' => (float) $s->duty_other,
            'duty_total' => (float) $s->duty_total,
            'drawback_amount' => (float) $s->drawback_amount,
            'rodtep_amount' => (float) $s->rodtep_amount,
            'duty_paid_at' => $s->duty_paid_at?->toDateString(),
            'cleared_at' => $s->customs_cleared_at?->toDateString(),
            // How long the entry sat with customs — the number the desk uses to
            // judge a CHA and to plan the next consignment's free days.
            'clearance_days' => $s->customs_entry_date && $s->customs_cleared_at
                ? (int) round($s->customs_entry_date->diffInDays($s->customs_cleared_at, false))
                : null,
            'customs_status' => $s->customs_status,
        ];

        $columns = [
            'shipment_number' => 'Shipment #', 'direction' => 'Leg', 'party' => 'Party',
            'entry_type' => 'Entry Type', 'entry_number' => 'Entry #', 'entry_date' => 'Entry Date',
            'icegate_reference' => 'ICEGATE Ref', 'port_of_discharge' => 'Port', 'cha_agent' => 'CHA',
            'assessable_value' => 'Assessable Value', 'duty_bcd' => 'BCD', 'duty_igst' => 'IGST',
            'duty_cess' => 'Cess', 'duty_other' => 'Other Duty', 'duty_total' => 'Total Duty',
            'drawback_amount' => 'Drawback', 'rodtep_amount' => 'RoDTEP',
            'duty_paid_at' => 'Duty Paid', 'cleared_at' => 'Cleared',
            'clearance_days' => 'Clearance (days)', 'customs_status' => 'Status',
        ];

        return $this->rowReport($request, $query, $map, $columns, 'customs-report', function () use ($request) {
            $agg = $this->shipmentScope($request, null, 'customs_entry_date')
                ->reportable()
                ->when($request->filled('customs_entry_type'),
                    fn ($q) => $q->where('shipments.customs_entry_type', $request->query('customs_entry_type')))
                ->selectRaw(
                    "count(*) as entries,
                     sum(case when customs_entry_number is not null then 1 else 0 end) as filed,
                     sum(case when customs_status = 'cleared' then 1 else 0 end) as cleared,
                     sum(assessable_value) as assessable_value,
                     sum(duty_bcd) as duty_bcd, sum(duty_igst) as duty_igst,
                     sum(duty_cess) as duty_cess, sum(duty_other) as duty_other,
                     sum(duty_total) as duty_total,
                     sum(drawback_amount) as drawback_amount, sum(rodtep_amount) as rodtep_amount,
                     avg(case when customs_cleared_at is not null and customs_entry_date is not null
                         then datediff(customs_cleared_at, customs_entry_date) end) as avg_clearance_days"
                )->first();

            return [
                'entries' => (int) ($agg->entries ?? 0),
                'filed' => (int) ($agg->filed ?? 0),
                'cleared' => (int) ($agg->cleared ?? 0),
                'assessable_value' => round((float) ($agg->assessable_value ?? 0), 2),
                'duty_bcd' => round((float) ($agg->duty_bcd ?? 0), 2),
                'duty_igst' => round((float) ($agg->duty_igst ?? 0), 2),
                'duty_cess' => round((float) ($agg->duty_cess ?? 0), 2),
                'duty_other' => round((float) ($agg->duty_other ?? 0), 2),
                'duty_total' => round((float) ($agg->duty_total ?? 0), 2),
                'drawback_amount' => round((float) ($agg->drawback_amount ?? 0), 2),
                'rodtep_amount' => round((float) ($agg->rodtep_amount ?? 0), 2),
                'avg_clearance_days' => $agg->avg_clearance_days !== null
                    ? round((float) $agg->avg_clearance_days, 1)
                    : null,
            ];
        });
    }

    // --------------------------------------------------------- Container Report

    /**
     * Every box moved over the range, with its cargo, its position in the
     * physical chain, damage, and the detention/demurrage exposure the free-days
     * window creates. `?detention=1` narrows it to boxes already accruing.
     */
    public function containerReport(Request $request)
    {
        $query = ShipmentContainer::query()
            ->join('shipments', 'shipments.id', '=', 'shipment_containers.shipment_id')
            ->with(['shipment:id,shipment_number,direction,status,supplier_id,customer_id',
                'shipment.supplier:id,name', 'shipment.customer:id,name'])
            ->select('shipment_containers.*');

        foreach (['status', 'container_type'] as $filter) {
            if ($request->filled($filter)) {
                $query->where('shipment_containers.'.$filter, $request->query($filter));
            }
        }
        if ($request->filled('direction')) {
            $query->where('shipments.direction', $request->query('direction'));
        }
        if ($request->filled('is_damaged')) {
            $query->where('shipment_containers.is_damaged', $request->boolean('is_damaged'));
        }
        // Mirrors ShipmentContainer::isAccruingDetention(): free days gone and
        // the box still not handed back.
        if ($request->boolean('detention')) {
            $query->whereNotNull('shipment_containers.detention_free_until')
                ->where('shipment_containers.detention_free_until', '<', now()->toDateString())
                ->where('shipment_containers.status', '!=', 'empty_returned');
        }
        if ($request->filled('date_from')) {
            $query->whereDate('shipments.shipment_date', '>=', $request->query('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('shipments.shipment_date', '<=', $request->query('date_to'));
        }
        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('shipment_containers.container_number', 'like', "%{$search}%")
                    ->orWhere('shipment_containers.seal_number', 'like', "%{$search}%")
                    ->orWhere('shipments.shipment_number', 'like', "%{$search}%");
            });
        }

        $query->orderByDesc('shipment_containers.id');

        $map = fn (ShipmentContainer $c) => [
            'container_number' => $c->container_number,
            'container_type' => $c->container_type,
            'seal_number' => $c->seal_number,
            'shipment_number' => $c->shipment?->shipment_number,
            'direction' => $c->shipment?->direction,
            'party' => $c->shipment?->partyName(),
            'status' => $c->status,
            'current_location' => $c->current_location,
            'status_updated_at' => $c->status_updated_at?->toDateString(),
            'packages' => (int) $c->packages,
            'gross_weight_kg' => (float) $c->gross_weight_kg,
            'cbm' => (float) $c->cbm,
            'free_days' => $c->free_days !== null ? (int) $c->free_days : null,
            'detention_free_until' => $c->detention_free_until?->toDateString(),
            'detention_days' => $c->detentionDays(),
            'empty_returned_on' => $c->empty_returned_on?->toDateString(),
            'is_damaged' => $c->is_damaged ? 'Yes' : 'No',
            'damage_remarks' => $c->damage_remarks,
        ];

        $columns = [
            'container_number' => 'Container #', 'container_type' => 'Type', 'seal_number' => 'Seal #',
            'shipment_number' => 'Shipment #', 'direction' => 'Leg', 'party' => 'Party',
            'status' => 'Status', 'current_location' => 'Location',
            'status_updated_at' => 'Status Updated', 'packages' => 'Packages',
            'gross_weight_kg' => 'Gross Wt (kg)', 'cbm' => 'CBM', 'free_days' => 'Free Days',
            'detention_free_until' => 'Free Until', 'detention_days' => 'Detention (days)',
            'empty_returned_on' => 'Empty Returned', 'is_damaged' => 'Damaged',
            'damage_remarks' => 'Damage Remarks',
        ];

        return $this->rowReport($request, $query, $map, $columns, 'container-report', function () use ($request) {
            $agg = $this->containerScope($request)->selectRaw(
                "count(*) as containers,
                 sum(case when shipment_containers.is_damaged = 1 then 1 else 0 end) as damaged,
                 sum(case when shipment_containers.status = 'empty_returned' then 1 else 0 end) as returned,
                 sum(case when shipment_containers.detention_free_until is not null
                     and shipment_containers.detention_free_until < ?
                     and shipment_containers.status <> 'empty_returned' then 1 else 0 end) as accruing_detention,
                 sum(shipment_containers.packages) as packages,
                 sum(shipment_containers.gross_weight_kg) as gross_weight_kg,
                 sum(shipment_containers.cbm) as cbm",
                [now()->toDateString()]
            )->first();

            return [
                'containers' => (int) ($agg->containers ?? 0),
                'damaged' => (int) ($agg->damaged ?? 0),
                'returned' => (int) ($agg->returned ?? 0),
                'accruing_detention' => (int) ($agg->accruing_detention ?? 0),
                'packages' => (int) ($agg->packages ?? 0),
                'gross_weight_kg' => round((float) ($agg->gross_weight_kg ?? 0), 3),
                'cbm' => round((float) ($agg->cbm ?? 0), 3),
            ];
        });
    }

    // ------------------------------------------------------- Country-wise Sales

    /**
     * Trade by country: for exports (the default) the destination country and
     * what was sold into it; pass `direction=import` to read the origin
     * countries and what was bought from them instead.
     *
     * Cost is assembled from the same components as Shipment::profitBreakdown()
     * — see expenseAggregate() — so a country's margin equals the sum of its
     * shipments' margins on the Shipment Profit report.
     */
    public function countryWiseSales(Request $request)
    {
        $direction = $request->query('direction') === 'import' ? 'import' : 'export';
        $countryColumn = $direction === 'import' ? 'origin_country_id' : 'destination_country_id';
        [$freight, $duty, $other] = $this->costExpressions();

        $rows = $this->shipmentScope($request, $direction)
            ->reportable()
            ->join('countries', 'countries.id', '=', "shipments.{$countryColumn}")
            ->leftJoinSub($this->expenseAggregate(), 'e', 'e.shipment_id', '=', 'shipments.id')
            ->selectRaw("countries.code as country_code, countries.name as country, countries.region as region,
                count(*) as shipment_count,
                sum(shipments.packages) as packages,
                sum(shipments.gross_weight_kg) as gross_weight_kg,
                sum(shipments.cbm) as cbm,
                sum(shipments.invoice_value * shipments.exchange_rate) as invoice_value,
                sum(shipments.fob_value * shipments.exchange_rate) as fob_value,
                sum(shipments.goods_cost) as goods_cost,
                sum({$duty}) as duty,
                sum({$freight} + {$other}) as logistics_cost,
                sum(shipments.amount_settled) as amount_settled")
            ->groupBy('countries.id', 'countries.code', 'countries.name', 'countries.region')
            ->orderByDesc('invoice_value')
            ->get()
            ->map(function ($r) use ($direction) {
                $value = round((float) $r->invoice_value, 2);
                $cost = round((float) $r->goods_cost + (float) $r->duty + (float) $r->logistics_cost, 2);
                // Only the export leg books revenue here; an import's "value" is
                // what was bought, so a margin against it would be meaningless.
                $profit = $direction === 'export' ? round($value - $cost, 2) : null;

                return [
                    'country_code' => $r->country_code,
                    'country' => $r->country,
                    'region' => $r->region,
                    'shipment_count' => (int) $r->shipment_count,
                    'packages' => (int) $r->packages,
                    'gross_weight_kg' => round((float) $r->gross_weight_kg, 3),
                    'cbm' => round((float) $r->cbm, 3),
                    'invoice_value' => $value,
                    'fob_value' => round((float) $r->fob_value, 2),
                    'goods_cost' => round((float) $r->goods_cost, 2),
                    'duty' => round((float) $r->duty, 2),
                    'logistics_cost' => round((float) $r->logistics_cost, 2),
                    'total_cost' => $cost,
                    'profit' => $profit,
                    'margin_percent' => $profit !== null && $value > 0 ? round($profit / $value * 100, 2) : null,
                    'amount_settled' => round((float) $r->amount_settled, 2),
                    'outstanding' => round(max(0, $value - (float) $r->amount_settled), 2),
                ];
            })->all();

        $columns = [
            'country_code' => 'Code', 'country' => 'Country', 'region' => 'Region',
            'shipment_count' => 'Shipments', 'packages' => 'Packages',
            'gross_weight_kg' => 'Gross Wt (kg)', 'cbm' => 'CBM',
            'invoice_value' => $direction === 'export' ? 'Sales Value' : 'Purchase Value',
            'fob_value' => 'FOB Value', 'goods_cost' => 'Goods Cost', 'duty' => 'Duty',
            'logistics_cost' => 'Logistics', 'total_cost' => 'Total Cost',
            'profit' => 'Profit', 'margin_percent' => 'Margin %',
            'amount_settled' => 'Settled', 'outstanding' => 'Outstanding',
        ];

        $value = round((float) collect($rows)->sum('invoice_value'), 2);
        $cost = round((float) collect($rows)->sum('total_cost'), 2);

        return $this->exportResponse($request, $rows, $columns, 'country-wise-sales', [
            'direction' => $direction,
            'countries' => count($rows),
            'shipment_count' => (int) collect($rows)->sum('shipment_count'),
            'invoice_value' => $value,
            'total_cost' => $cost,
            'profit' => $direction === 'export' ? round($value - $cost, 2) : null,
            'outstanding' => round((float) collect($rows)->sum('outstanding'), 2),
        ]);
    }

    // ------------------------------------------------------ Port-wise Shipment

    /**
     * Traffic per port. `?port_side=discharge` (the default) groups on the port
     * of discharge — the arrival side an importer watches; `loading` groups on
     * the port of loading, which is the side an exporter ships from.
     *
     * Transit time is measured on actuals only (ATD → ATA), so a lane's average
     * is not skewed by shipments still at sea.
     */
    public function portWiseShipments(Request $request)
    {
        $side = $request->query('port_side') === 'loading' ? 'loading' : 'discharge';
        $portColumn = $side === 'loading' ? 'port_of_loading_id' : 'port_of_discharge_id';

        $rows = $this->shipmentScope($request)
            ->reportable()
            ->join('ports', 'ports.id', '=', "shipments.{$portColumn}")
            ->leftJoin('countries', 'countries.id', '=', 'ports.country_id')
            ->selectRaw("ports.code as port_code, ports.name as port, ports.type as port_type,
                countries.name as country,
                count(*) as shipment_count,
                sum(case when shipments.direction = 'import' then 1 else 0 end) as import_count,
                sum(case when shipments.direction = 'export' then 1 else 0 end) as export_count,
                sum(shipments.packages) as packages,
                sum(shipments.gross_weight_kg) as gross_weight_kg,
                sum(shipments.cbm) as cbm,
                sum(shipments.invoice_value * shipments.exchange_rate) as invoice_value,
                sum(shipments.duty_total) as duty,
                avg(case when shipments.atd is not null and shipments.ata is not null
                    then datediff(shipments.ata, shipments.atd) end) as avg_transit_days,
                avg(case when shipments.eta is not null and shipments.ata is not null
                    then datediff(shipments.ata, shipments.eta) end) as avg_eta_variance_days")
            ->groupBy('ports.id', 'ports.code', 'ports.name', 'ports.type', 'countries.name')
            ->orderByDesc('shipment_count')
            ->get()
            ->map(fn ($r) => [
                'port_code' => $r->port_code,
                'port' => $r->port,
                'port_type' => $r->port_type,
                'country' => $r->country,
                'shipment_count' => (int) $r->shipment_count,
                'import_count' => (int) $r->import_count,
                'export_count' => (int) $r->export_count,
                'packages' => (int) $r->packages,
                'gross_weight_kg' => round((float) $r->gross_weight_kg, 3),
                'cbm' => round((float) $r->cbm, 3),
                'invoice_value' => round((float) $r->invoice_value, 2),
                'duty' => round((float) $r->duty, 2),
                'avg_transit_days' => $r->avg_transit_days !== null ? round((float) $r->avg_transit_days, 1) : null,
                'avg_eta_variance_days' => $r->avg_eta_variance_days !== null
                    ? round((float) $r->avg_eta_variance_days, 1)
                    : null,
            ])->all();

        $columns = [
            'port_code' => 'Code', 'port' => 'Port', 'port_type' => 'Type', 'country' => 'Country',
            'shipment_count' => 'Shipments', 'import_count' => 'Imports', 'export_count' => 'Exports',
            'packages' => 'Packages', 'gross_weight_kg' => 'Gross Wt (kg)', 'cbm' => 'CBM',
            'invoice_value' => 'Cargo Value', 'duty' => 'Duty',
            'avg_transit_days' => 'Avg Transit (days)', 'avg_eta_variance_days' => 'Avg ETA Variance (days)',
        ];

        return $this->exportResponse($request, $rows, $columns, 'port-wise-shipments', [
            'port_side' => $side,
            'ports' => count($rows),
            'shipment_count' => (int) collect($rows)->sum('shipment_count'),
            'packages' => (int) collect($rows)->sum('packages'),
            'gross_weight_kg' => round((float) collect($rows)->sum('gross_weight_kg'), 3),
            'invoice_value' => round((float) collect($rows)->sum('invoice_value'), 2),
            'duty' => round((float) collect($rows)->sum('duty'), 2),
        ]);
    }

    // ----------------------------------------------------------------- internals

    /**
     * Answer a row-level report: paginated JSON for the on-screen table, or the
     * whole filtered set (capped) for a CSV / Excel / PDF download. `$meta` is
     * resolved either way, so the footer totals always cover the full set rather
     * than the page in view.
     *
     * @param  callable(mixed): array<string, mixed>  $map
     * @param  array<string, string>  $columns
     * @param  callable(): array<string, mixed>  $meta
     */
    private function rowReport(
        Request $request,
        Builder $query,
        callable $map,
        array $columns,
        string $filename,
        callable $meta,
    ) {
        if ($request->query('format', 'json') === 'json') {
            $page = $query->paginate(min((int) $request->query('per_page', 25), 200));

            return response()->json([
                'data' => $page->getCollection()->map($map)->values()->all(),
                'meta' => [
                    'current_page' => $page->currentPage(),
                    'per_page' => $page->perPage(),
                    'total' => $page->total(),
                ] + $meta(),
            ]);
        }

        $rows = $query->limit(self::EXPORT_LIMIT)->get()->map($map)->values()->all();

        return $this->exportResponse($request, $rows, $columns, $filename, $meta());
    }

    /**
     * The filters every shipment-based report shares — the same set the
     * Active Shipments screens use (ShipmentController::index), so a report can
     * be reached from a board without the numbers shifting.
     *
     * `$direction` pins the leg for the reports that only make sense on one
     * side. `$dateColumn` is the date the range applies to: the shipment date
     * for most reports, the customs entry date for the customs register.
     *
     * Columns are qualified because several of these reports join `ports`,
     * `countries` or `shipment_expenses` on top of this scope.
     */
    private function shipmentScope(Request $request, ?string $direction = null, string $dateColumn = 'shipment_date'): Builder
    {
        $query = Shipment::query()->direction($direction ?? $request->query('direction'));

        foreach ([
            'status', 'customs_status', 'payment_status', 'mode', 'incoterm',
            'supplier_id', 'customer_id', 'shipping_line_id', 'airline_id',
            'freight_forwarder_id', 'cha_agent_id', 'transporter_id',
            'port_of_loading_id', 'port_of_discharge_id',
            'origin_country_id', 'destination_country_id',
        ] as $filter) {
            if ($request->filled($filter)) {
                $query->where('shipments.'.$filter, $request->query($filter));
            }
        }

        if (! $request->filled('status') && $request->query('state') === 'open') {
            $query->open();
        }
        if ($request->filled('date_from')) {
            $query->whereDate('shipments.'.$dateColumn, '>=', $request->query('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('shipments.'.$dateColumn, '<=', $request->query('date_to'));
        }
        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('shipments.shipment_number', 'like', "%{$search}%")
                    ->orWhere('shipments.bl_awb_number', 'like', "%{$search}%")
                    ->orWhere('shipments.invoice_number', 'like', "%{$search}%")
                    ->orWhere('shipments.customs_entry_number', 'like', "%{$search}%");
            });
        }

        return $query;
    }

    /** The Shipment Expense register's filters, re-applied for its footer totals. */
    private function expenseScope(Request $request): Builder
    {
        $query = ShipmentExpense::query()
            ->join('shipments', 'shipments.id', '=', 'shipment_expenses.shipment_id');

        foreach (['expense_type', 'vendor_type', 'payment_status', 'shipment_id'] as $filter) {
            if ($request->filled($filter)) {
                $query->where('shipment_expenses.'.$filter, $request->query($filter));
            }
        }
        if ($request->filled('direction')) {
            $query->where('shipments.direction', $request->query('direction'));
        }
        if ($request->filled('is_billable')) {
            $query->where('shipment_expenses.is_billable', $request->boolean('is_billable'));
        }
        if ($request->filled('date_from')) {
            $query->whereDate('shipment_expenses.expense_date', '>=', $request->query('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('shipment_expenses.expense_date', '<=', $request->query('date_to'));
        }
        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('shipments.shipment_number', 'like', "%{$search}%")
                    ->orWhere('shipment_expenses.vendor_name', 'like', "%{$search}%")
                    ->orWhere('shipment_expenses.invoice_number', 'like', "%{$search}%");
            });
        }

        return $query;
    }

    /** The Container Report's filters, re-applied for its footer totals. */
    private function containerScope(Request $request): Builder
    {
        $query = ShipmentContainer::query()
            ->join('shipments', 'shipments.id', '=', 'shipment_containers.shipment_id');

        foreach (['status', 'container_type'] as $filter) {
            if ($request->filled($filter)) {
                $query->where('shipment_containers.'.$filter, $request->query($filter));
            }
        }
        if ($request->filled('direction')) {
            $query->where('shipments.direction', $request->query('direction'));
        }
        if ($request->filled('is_damaged')) {
            $query->where('shipment_containers.is_damaged', $request->boolean('is_damaged'));
        }
        if ($request->boolean('detention')) {
            $query->whereNotNull('shipment_containers.detention_free_until')
                ->where('shipment_containers.detention_free_until', '<', now()->toDateString())
                ->where('shipment_containers.status', '!=', 'empty_returned');
        }
        if ($request->filled('date_from')) {
            $query->whereDate('shipments.shipment_date', '>=', $request->query('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('shipments.shipment_date', '<=', $request->query('date_to'));
        }
        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('shipment_containers.container_number', 'like', "%{$search}%")
                    ->orWhere('shipment_containers.seal_number', 'like', "%{$search}%")
                    ->orWhere('shipments.shipment_number', 'like', "%{$search}%");
            });
        }

        return $query;
    }

    /** Footer figures for the Import / Export Report. */
    private function legTotals(Request $request, string $direction): array
    {
        $costs = $this->costTotals($request, $direction, reportableOnly: false);

        $agg = $this->shipmentScope($request, $direction)->selectRaw(
            'count(*) as shipments, sum(packages) as packages,
             sum(gross_weight_kg) as gross_weight_kg, sum(cbm) as cbm,
             sum(invoice_value * exchange_rate) as invoice_value,
             sum(fob_value * exchange_rate) as fob_value,
             sum(drawback_amount) as drawback_amount, sum(rodtep_amount) as rodtep_amount,
             sum(amount_settled) as amount_settled'
        )->first();

        $invoiceValue = round((float) ($agg->invoice_value ?? 0), 2);

        return [
            'shipments' => (int) ($agg->shipments ?? 0),
            'packages' => (int) ($agg->packages ?? 0),
            'gross_weight_kg' => round((float) ($agg->gross_weight_kg ?? 0), 3),
            'cbm' => round((float) ($agg->cbm ?? 0), 3),
            'invoice_value' => $invoiceValue,
            'fob_value' => round((float) ($agg->fob_value ?? 0), 2),
            'freight_insurance' => $costs['freight'],
            'duty' => $costs['duty'],
            'other_cost' => $costs['other'],
            'landed_cost' => $costs['total_cost'],
            'drawback_amount' => round((float) ($agg->drawback_amount ?? 0), 2),
            'rodtep_amount' => round((float) ($agg->rodtep_amount ?? 0), 2),
            'amount_settled' => round((float) ($agg->amount_settled ?? 0), 2),
            'outstanding' => round(max(0, $invoiceValue - (float) ($agg->amount_settled ?? 0)), 2),
        ];
    }

    /**
     * Cost and revenue totalled over a whole filtered leg, component by
     * component, using the SQL mirror of Shipment::landedCostComponents().
     *
     * @return array<string, float>
     */
    private function costTotals(Request $request, string $direction, bool $reportableOnly = true): array
    {
        [$freight, $duty, $other] = $this->costExpressions();

        $agg = $this->shipmentScope($request, $direction)
            ->when($reportableOnly, fn ($q) => $q->reportable())
            ->leftJoinSub($this->expenseAggregate(), 'e', 'e.shipment_id', '=', 'shipments.id')
            ->selectRaw("sum(shipments.invoice_value * shipments.exchange_rate) as revenue,
                sum(shipments.goods_cost) as goods_cost,
                sum({$freight}) as freight,
                sum({$duty}) as duty,
                sum({$other}) as other,
                sum(coalesce(e.billable_cost, 0)) as billable_cost")
            ->first();

        $goodsCost = round((float) ($agg->goods_cost ?? 0), 2);
        $dutyTotal = round((float) ($agg->duty ?? 0), 2);
        $freightTotal = round((float) ($agg->freight ?? 0), 2);
        $otherTotal = round((float) ($agg->other ?? 0), 2);

        return [
            // Only exports book revenue; the caller decides whether to use it.
            'revenue' => $direction === 'export' ? round((float) ($agg->revenue ?? 0), 2) : 0.0,
            'goods_cost' => $goodsCost,
            'freight' => $freightTotal,
            'duty' => $dutyTotal,
            'other' => $otherTotal,
            'logistics_cost' => round($freightTotal + $otherTotal, 2),
            'billable_cost' => round((float) ($agg->billable_cost ?? 0), 2),
            'total_cost' => round($goodsCost + $dutyTotal + $freightTotal + $otherTotal, 2),
        ];
    }

    /**
     * Per-shipment logistics cost, split the way the row-level reports split it,
     * as a joinable subquery. Lets the aggregate reports total the same freight /
     * duty / other figures without pulling every expense row into memory.
     *
     * The is_billable test sits inside each CASE rather than in a WHERE, because
     * the same subquery has to report both the absorbed cost heads and the
     * billable total.
     */
    private function expenseAggregate(): Builder
    {
        $freightHeads = "'".implode("','", Shipment::FREIGHT_HEADS)."'";

        return ShipmentExpense::query()
            ->selectRaw("shipment_id,
                sum(case when is_billable = 0 and expense_type in ({$freightHeads}) then base_amount else 0 end) as booked_freight,
                sum(case when is_billable = 0 and expense_type = 'customs_duty' then base_amount else 0 end) as booked_duty,
                sum(case when is_billable = 0 and expense_type not in ({$freightHeads}, 'customs_duty') then base_amount else 0 end) as other_cost,
                sum(case when is_billable = 1 then base_amount else 0 end) as billable_cost")
            ->groupBy('shipment_id');
    }

    /**
     * SQL mirror of Shipment::landedCostComponents() — freight, duty and other,
     * as expressions over a query joined to expenseAggregate() as `e`.
     *
     * Freight and duty each reach a shipment two ways: declared on the header
     * (the CIF add-ons, the assessed duty) or booked as a logistics cost when
     * the carrier's or CHA's bill arrives. The header wins where it is filled in
     * and the booked cost stands in where it is not, so nothing is counted twice.
     *
     * Change the model method and this has to change with it — the two exist so
     * a row's landed cost and a footer's total can never disagree.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private function costExpressions(): array
    {
        return [
            'case when (shipments.freight_amount + shipments.insurance_amount) > 0
                then (shipments.freight_amount + shipments.insurance_amount) * shipments.exchange_rate
                else coalesce(e.booked_freight, 0) end',
            'case when shipments.duty_total > 0 then shipments.duty_total
                else coalesce(e.booked_duty, 0) end',
            'coalesce(e.other_cost, 0)',
        ];
    }
}
