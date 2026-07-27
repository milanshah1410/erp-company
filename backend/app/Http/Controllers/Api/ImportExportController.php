<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ShipmentResource;
use App\Models\Shipment;
use App\Models\ShipmentContainer;
use App\Models\ShipmentDocument;
use App\Models\ShipmentExpense;
use App\Support\TradeCatalogue;
use Illuminate\Http\Request;

/**
 * The read-only boards of the Import & Export Logistics module (SRS §24):
 * the dashboard, the ETA/ETD schedule, Pending Customs, Pending Payments and
 * Shipment Profit.
 *
 * Every figure here is derived from the shipment header and its children — no
 * report tables, no stored aggregates — so the boards can never disagree with the
 * documents behind them. Money is base currency throughout (foreign values are
 * converted through the shipment's own exchange rate as they are read).
 *
 * Pending Documents and Container Status are the natural default views of their
 * own resources, so they are served by ShipmentDocumentController::index and
 * ShipmentContainerController::index rather than duplicated here.
 */
class ImportExportController extends Controller
{
    /**
     * Module dashboard. The payload is shaped as the eight panels the module spec
     * asks for — active imports, active exports, ETA/ETD, container status,
     * shipment profit, pending documents, pending customs clearance and logistics
     * expenses — so the screen renders one section per key rather than the client
     * re-deriving which figure belongs where.
     *
     * Deliberately uncached: the whole point is that the desk sees a container
     * move or a duty payment the moment it is booked.
     */
    public function dashboard(Request $request)
    {
        $today = now()->startOfDay();
        $horizon = (int) $request->query('days', 14);
        // Profit is a trailing figure, not a live one — one shipment's margin means
        // little, a quarter of them means something.
        $profitDays = (int) $request->query('profit_days', 90);
        $profitFrom = $today->copy()->subDays($profitDays)->toDateString();

        $counts = Shipment::query()
            ->selectRaw('direction, status, count(*) as total')
            ->groupBy('direction', 'status')
            ->get();

        $money = Shipment::query()->open()
            ->selectRaw('direction, sum(invoice_value * exchange_rate) as value, sum(amount_settled) as settled')
            ->groupBy('direction')
            ->get()
            ->keyBy('direction');

        $delayed = Shipment::query()->open()
            ->whereNotNull('eta')->whereNull('ata')
            ->whereDate('eta', '<', $today->toDateString())
            ->selectRaw('direction, count(*) as total')
            ->groupBy('direction')
            ->pluck('total', 'direction');

        $unpaid = Shipment::query()->open()
            ->whereIn('payment_status', ['unpaid', 'partial'])
            ->selectRaw('direction, count(*) as total')
            ->groupBy('direction')
            ->pluck('total', 'direction');

        // One entry per leg: how many are in flight, what they are worth, what is
        // still owed on them and how many are running late.
        $leg = fn (string $direction) => [
            'active' => (int) $counts->where('direction', $direction)->whereIn('status', Shipment::OPEN_STATUSES)->sum('total'),
            'value' => round((float) ($money->get($direction)->value ?? 0), 2),
            'outstanding' => $this->outstandingOf($money->get($direction)),
            'delayed' => (int) ($delayed[$direction] ?? 0),
            'unpaid' => (int) ($unpaid[$direction] ?? 0),
            'by_status' => $counts->where('direction', $direction)
                ->map(fn ($row) => ['status' => $row->status, 'total' => (int) $row->total])
                ->values(),
        ];

        // Arrivals due inside the horizon (or already overdue): what lands next.
        $arriving = Shipment::query()->open()
            ->with(['supplier:id,name', 'customer:id,name', 'portOfLoading:id,name', 'portOfDischarge:id,name'])
            ->whereNotNull('eta')
            ->whereNull('ata')
            ->whereDate('eta', '<=', $today->copy()->addDays($horizon)->toDateString())
            ->orderBy('eta')
            ->limit(10)
            ->get();

        $containerCounts = ShipmentContainer::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $customs = Shipment::query()
            ->whereNotIn('status', ['draft', 'cancelled', 'closed'])
            ->where('customs_status', '!=', 'cleared')
            ->selectRaw('count(*) as total, sum(duty_total) as duty')
            ->first();

        $expenseTotals = ShipmentExpense::query()
            ->selectRaw('sum(base_amount) as total, sum(paid_amount) as paid')
            ->first();

        $docs = ShipmentDocument::query()->outstanding();

        return response()->json([
            'import' => $leg('import'),
            'export' => $leg('export'),

            'eta_etd' => [
                'delayed' => (int) $delayed->sum(),
                'arriving_count' => $arriving->count(),
                'departing_soon' => Shipment::query()->open()
                    ->whereNotNull('etd')->whereNull('atd')
                    ->whereDate('etd', '<=', $today->copy()->addDays($horizon)->toDateString())
                    ->count(),
                'horizon_days' => $horizon,
                'arriving' => ShipmentResource::collection($arriving),
            ],

            'containers' => [
                'total' => (int) $containerCounts->sum(),
                'at_port' => (int) (($containerCounts['gated_in'] ?? 0) + ($containerCounts['discharged'] ?? 0)),
                'in_transit' => (int) (($containerCounts['loaded'] ?? 0) + ($containerCounts['in_transit'] ?? 0)),
                'delivered' => (int) (($containerCounts['delivered'] ?? 0) + ($containerCounts['empty_returned'] ?? 0)),
                'in_detention' => ShipmentContainer::query()
                    ->whereNotNull('detention_free_until')
                    ->whereDate('detention_free_until', '<', $today->toDateString())
                    ->where('status', '!=', 'empty_returned')
                    ->count(),
                'damaged' => ShipmentContainer::query()->where('is_damaged', true)->count(),
                'by_status' => collect(ShipmentContainer::STATUSES)
                    ->map(fn (string $status) => ['status' => $status, 'total' => (int) ($containerCounts[$status] ?? 0)])
                    ->values(),
            ],

            'profit' => $this->profitSnapshot($profitFrom) + ['since' => $profitFrom, 'days' => $profitDays],

            'documents' => [
                'outstanding' => (clone $docs)->count(),
                'overdue' => (clone $docs)->whereNotNull('due_date')->whereDate('due_date', '<', $today->toDateString())->count(),
                'due_this_week' => (clone $docs)
                    ->whereBetween('due_date', [$today->toDateString(), $today->copy()->addDays(7)->toDateString()])
                    ->count(),
                'by_type' => (clone $docs)
                    ->selectRaw('doc_type, count(*) as total')
                    ->groupBy('doc_type')
                    ->orderByDesc('total')
                    ->limit(6)
                    ->get()
                    ->map(fn ($row) => [
                        'doc_type' => $row->doc_type,
                        'label' => TradeCatalogue::documentLabel($row->doc_type),
                        'total' => (int) $row->total,
                    ]),
            ],

            'customs' => [
                'pending' => (int) ($customs->total ?? 0),
                'duty_outstanding' => round((float) ($customs->duty ?? 0), 2),
                'at_port_uncleared' => Shipment::query()
                    ->where('status', 'arrived')->where('customs_status', '!=', 'cleared')->count(),
                'on_hold' => Shipment::query()->where('customs_status', 'on_hold')->count(),
                'by_status' => Shipment::query()
                    ->whereNotIn('status', ['draft', 'cancelled', 'closed'])
                    ->where('customs_status', '!=', 'cleared')
                    ->selectRaw('customs_status, count(*) as total')
                    ->groupBy('customs_status')
                    ->get()
                    ->map(fn ($row) => ['status' => $row->customs_status, 'total' => (int) $row->total]),
            ],

            'expenses' => [
                'total' => round((float) ($expenseTotals->total ?? 0), 2),
                'paid' => round((float) ($expenseTotals->paid ?? 0), 2),
                'unpaid' => round((float) ($expenseTotals->total ?? 0) - (float) ($expenseTotals->paid ?? 0), 2),
                'by_type' => ShipmentExpense::query()
                    ->selectRaw('expense_type, sum(base_amount) as total')
                    ->groupBy('expense_type')
                    ->orderByDesc('total')
                    ->limit(8)
                    ->get()
                    ->map(fn ($row) => [
                        'expense_type' => $row->expense_type,
                        'label' => TradeCatalogue::expenseLabel($row->expense_type),
                        'total' => round((float) $row->total, 2),
                    ]),
            ],
        ]);
    }

    /**
     * Trailing profit figures for the dashboard's Shipment Profit panel. Exports
     * carry the margin; imports report what the goods landed at. Summed in SQL
     * against the same definitions the full profit board uses.
     *
     * @return array<string, float|null>
     */
    private function profitSnapshot(string $since): array
    {
        $scope = fn () => Shipment::query()
            ->whereNotIn('status', ['draft', 'cancelled'])
            ->whereDate('shipment_date', '>=', $since);

        $agg = $scope()->selectRaw(
            "sum(case when direction = 'export' then invoice_value * exchange_rate else 0 end) as revenue,
             sum(case when direction = 'export' then goods_cost + duty_total else 0 end) as export_cost,
             sum(goods_cost + duty_total) as all_cost,
             sum(case when direction = 'export' then 1 else 0 end) as export_count,
             sum(case when direction = 'import' then 1 else 0 end) as import_count"
        )->first();

        $logisticsFor = fn (?string $direction) => round((float) ShipmentExpense::query()
            ->where('is_billable', false)
            ->whereIn('shipment_id', $scope()->when($direction, fn ($q) => $q->where('direction', $direction))->select('id'))
            ->sum('base_amount'), 2);

        $revenue = round((float) ($agg->revenue ?? 0), 2);
        $exportCost = round((float) ($agg->export_cost ?? 0) + $logisticsFor('export'), 2);
        $allCost = round((float) ($agg->all_cost ?? 0) + $logisticsFor(null), 2);
        $profit = round($revenue - $exportCost, 2);

        return [
            'revenue' => $revenue,
            'export_cost' => $exportCost,
            'profit' => $profit,
            'margin_percent' => $revenue > 0 ? round($profit / $revenue * 100, 2) : null,
            'import_landed_cost' => round($allCost - $exportCost, 2),
            'logistics_cost' => $logisticsFor(null),
            'export_shipments' => (int) ($agg->export_count ?? 0),
            'import_shipments' => (int) ($agg->import_count ?? 0),
        ];
    }

    /**
     * ETA / ETD board: the sailing schedule with the delay against it. `late`
     * compares the estimate to the actual once it exists, and to today while the
     * shipment is still out there.
     */
    public function etaEtd(Request $request)
    {
        $query = Shipment::query()
            ->with(['supplier:id,name', 'customer:id,name'])
            ->direction($request->query('direction'));

        if ($request->query('state', 'open') === 'open') {
            $query->open();
        }
        if ($request->filled('mode')) {
            $query->where('mode', $request->query('mode'));
        }
        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('shipment_number', 'like', "%{$search}%")
                    ->orWhere('vessel_name', 'like', "%{$search}%")
                    ->orWhere('bl_awb_number', 'like', "%{$search}%");
            });
        }

        // leg=departure watches ETD (has it sailed?), leg=arrival watches ETA.
        $leg = $request->query('leg', 'arrival');
        if ($leg === 'departure') {
            $query->whereNotNull('etd')->orderBy('etd');
        } else {
            $query->whereNotNull('eta')->orderBy('eta');
        }

        if ($request->boolean('late_only')) {
            $today = now()->toDateString();
            $leg === 'departure'
                ? $query->whereNull('atd')->whereDate('etd', '<', $today)
                : $query->whereNull('ata')->whereDate('eta', '<', $today);
        }
        if ($request->filled('date_from')) {
            $query->whereDate($leg === 'departure' ? 'etd' : 'eta', '>=', $request->query('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate($leg === 'departure' ? 'etd' : 'eta', '<=', $request->query('date_to'));
        }

        $perPage = min((int) $request->query('per_page', 25), 100);
        $page = $query->paginate($perPage);

        return ShipmentResource::collection($page)->additional(['meta' => ['leg' => $leg]]);
    }

    /**
     * Pending Customs: shipments whose entry is not yet cleared, ordered so the
     * ones already at the port (accruing storage) come first.
     */
    public function pendingCustoms(Request $request)
    {
        $query = Shipment::query()
            ->with(['supplier:id,name', 'customer:id,name'])
            ->direction($request->query('direction'))
            ->whereNotIn('status', ['draft', 'cancelled', 'closed'])
            ->when(
                $request->filled('customs_status'),
                fn ($q) => $q->where('customs_status', $request->query('customs_status')),
                fn ($q) => $q->where('customs_status', '!=', 'cleared'),
            );

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('shipment_number', 'like', "%{$search}%")
                    ->orWhere('customs_entry_number', 'like', "%{$search}%")
                    ->orWhere('bl_awb_number', 'like', "%{$search}%");
            });
        }

        // Arrived-but-not-cleared is the expensive state, so it sorts to the top.
        $query->orderByRaw("field(status, 'arrived', 'customs', 'in_transit', 'booked', 'cleared', 'delivered')")
            ->orderBy('eta');

        $perPage = min((int) $request->query('per_page', 25), 100);
        $page = $query->paginate($perPage);

        $duty = Shipment::query()
            ->whereNotIn('status', ['draft', 'cancelled'])
            ->where('customs_status', '!=', 'cleared')
            // Held entries are the ones that stop moving on their own — a query,
            // a valuation dispute, a licence — so the board counts them apart.
            ->selectRaw("sum(duty_total) as duty, count(*) as total,
                sum(case when customs_status = 'on_hold' then 1 else 0 end) as on_hold,
                sum(case when customs_status = 'pending' then 1 else 0 end) as unfiled")
            ->first();

        return ShipmentResource::collection($page)->additional([
            'summary' => [
                'pending' => (int) ($duty->total ?? 0),
                'unfiled' => (int) ($duty->unfiled ?? 0),
                'on_hold' => (int) ($duty->on_hold ?? 0),
                'duty_outstanding' => round((float) ($duty->duty ?? 0), 2),
            ],
        ]);
    }

    /**
     * Pending Payments: what is still to be received on exports and remitted on
     * imports, plus the unpaid logistics bills, aged against their due dates.
     */
    public function pendingPayments(Request $request)
    {
        $query = Shipment::query()
            ->with(['supplier:id,name', 'customer:id,name'])
            ->direction($request->query('direction'))
            ->whereNotIn('status', ['draft', 'cancelled'])
            ->whereIn('payment_status', $request->filled('payment_status')
                ? [$request->query('payment_status')]
                : ['unpaid', 'partial'])
            ->whereRaw('invoice_value * exchange_rate > amount_settled');

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('shipment_number', 'like', "%{$search}%")
                    ->orWhere('invoice_number', 'like', "%{$search}%")
                    ->orWhere('lc_number', 'like', "%{$search}%");
            });
        }
        if ($request->boolean('overdue_only')) {
            $query->whereNotNull('payment_due_date')
                ->whereDate('payment_due_date', '<', now()->toDateString());
        }

        $query->orderByRaw('payment_due_date is null')->orderBy('payment_due_date')->orderByDesc('id');

        $perPage = min((int) $request->query('per_page', 25), 100);
        $page = $query->paginate($perPage);

        // Vendor side: forwarder / CHA / carrier bills still owed.
        $expenses = ShipmentExpense::query()
            ->unsettled()
            ->selectRaw('sum(base_amount - paid_amount) as outstanding, count(*) as total')
            ->first();

        $totals = Shipment::query()
            ->whereNotIn('status', ['draft', 'cancelled'])
            ->whereIn('payment_status', ['unpaid', 'partial'])
            ->selectRaw('direction, sum((invoice_value * exchange_rate) - amount_settled) as outstanding')
            ->groupBy('direction')
            ->get()
            ->keyBy('direction');

        return ShipmentResource::collection($page)->additional([
            'summary' => [
                'receivable' => round((float) ($totals->get('export')->outstanding ?? 0), 2),
                'payable' => round((float) ($totals->get('import')->outstanding ?? 0), 2),
                'vendor_bills_outstanding' => round((float) ($expenses->outstanding ?? 0), 2),
                'vendor_bills_count' => (int) ($expenses->total ?? 0),
                'overdue' => Shipment::query()
                    ->whereIn('payment_status', ['unpaid', 'partial'])
                    ->whereNotNull('payment_due_date')
                    ->whereDate('payment_due_date', '<', now()->toDateString())
                    ->count(),
            ],
        ]);
    }

    /**
     * Shipment Profit: per-shipment revenue, goods cost, duty and absorbed
     * logistics cost. Exports carry a margin; imports have no sale on this
     * document, so they report a landed cost instead (Shipment::profitBreakdown).
     */
    public function shipmentProfit(Request $request)
    {
        // One scope, reused for the page and for the footer totals, so the two can
        // never describe different sets of shipments.
        $scope = fn () => Shipment::query()
            ->direction($request->query('direction'))
            ->whereNotIn('status', ['draft', 'cancelled'])
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate('shipment_date', '>=', $request->query('date_from')))
            ->when($request->filled('date_to'), fn ($q) => $q->whereDate('shipment_date', '<=', $request->query('date_to')))
            ->when($request->filled('search'), fn ($q) => $q->where('shipment_number', 'like', '%'.$request->query('search').'%'));

        $perPage = min((int) $request->query('per_page', 25), 100);
        $page = $scope()
            ->with(['supplier:id,name', 'customer:id,name', 'expenses:id,shipment_id,base_amount,is_billable'])
            ->orderByDesc('shipment_date')->orderByDesc('id')
            ->paginate($perPage);

        $rows = $page->getCollection()->map(function (Shipment $shipment) {
            $breakdown = $shipment->profitBreakdown();

            return [
                'id' => $shipment->id,
                'shipment_number' => $shipment->shipment_number,
                'direction' => $shipment->direction,
                'status' => $shipment->status,
                'shipment_date' => $shipment->shipment_date?->toDateString(),
                'party' => $shipment->partyName(),
                'currency' => $shipment->currency,
                'invoice_value' => (float) $shipment->invoice_value,
            ] + $breakdown;
        });

        // Totals cover the whole filtered set, not just this page, and are summed
        // in SQL so a wide date range does not pull every shipment into memory.
        //
        // The two legs are kept apart on purpose: only exports carry revenue, so
        // pooling them would report an import's landed cost as a loss. Cost
        // columns still total across everything (that is what the table shows),
        // while profit covers exports and landed cost covers imports.
        $agg = $scope()->selectRaw(
            "sum(case when direction = 'export' then invoice_value * exchange_rate else 0 end) as revenue,
             sum(case when direction = 'export' then goods_cost else 0 end) as export_goods_cost,
             sum(case when direction = 'export' then duty_total else 0 end) as export_duty,
             sum(goods_cost) as goods_cost,
             sum(duty_total) as duty"
        )->first();

        $logisticsFor = fn (?string $direction) => round((float) ShipmentExpense::query()
            ->where('is_billable', false)
            ->whereIn('shipment_id', $scope()->when($direction, fn ($q) => $q->where('direction', $direction))->select('id'))
            ->sum('base_amount'), 2);

        $exportCost = round(
            (float) ($agg->export_goods_cost ?? 0) + (float) ($agg->export_duty ?? 0) + $logisticsFor('export'),
            2,
        );
        $revenue = round((float) ($agg->revenue ?? 0), 2);

        $totals = [
            'revenue' => $revenue,
            'goods_cost' => round((float) ($agg->goods_cost ?? 0), 2),
            'duty' => round((float) ($agg->duty ?? 0), 2),
            'logistics_cost' => $logisticsFor(null),
        ];
        $totals['total_cost'] = round($totals['goods_cost'] + $totals['duty'] + $totals['logistics_cost'], 2);
        $totals['export_cost'] = $exportCost;
        $totals['profit'] = round($revenue - $exportCost, 2);
        $totals['margin_percent'] = $revenue > 0 ? round($totals['profit'] / $revenue * 100, 2) : null;
        // What the imported goods actually cost by the time they reached stock.
        $totals['import_landed_cost'] = round($totals['total_cost'] - $exportCost, 2);

        return response()->json([
            'data' => $rows,
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
            'totals' => $totals,
        ]);
    }

    /** Outstanding goods value from a grouped {value, settled} row. */
    private function outstandingOf(mixed $row): float
    {
        if (! $row) {
            return 0.0;
        }

        return round(max(0, (float) $row->value - (float) $row->settled), 2);
    }
}
