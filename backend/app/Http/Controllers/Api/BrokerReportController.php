<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ExportsReports;
use App\Http\Controllers\Controller;
use App\Models\Broker;
use App\Models\BrokerCommission;
use App\Models\BrokerSettlement;
use App\Models\SalesOrder;
use Carbon\Carbon;
use Illuminate\Http\Request;

class BrokerReportController extends Controller
{
    use ExportsReports;

    private const EXPORT_LIMIT = 10000;

    /**
     * Commission report. Filterable by broker, status and date. Serves the
     * "Commission / Pending Commission / Paid Commission" views via ?status=.
     */
    public function commissions(Request $request)
    {
        $query = BrokerCommission::query()
            ->with(['broker:id,code,name', 'invoice:id,invoice_number']);

        if ($request->filled('broker_id')) {
            $query->where('broker_id', $request->query('broker_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }
        if ($request->filled('type')) {
            $query->where('type', $request->query('type'));
        }
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->query('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->query('date_to'));
        }

        $query->orderByDesc('id');

        $map = fn (BrokerCommission $c) => [
            'date' => $c->created_at?->toDateString(),
            'broker' => $c->broker?->name,
            'invoice_number' => $c->invoice?->invoice_number,
            'type' => $c->type,
            'base_amount' => (float) $c->base_amount,
            'commission_percent' => (float) $c->commission_percent,
            'commission_amount' => (float) $c->commission_amount,
            'settled_amount' => (float) $c->settled_amount,
            'outstanding' => $c->outstanding(),
            'status' => $c->status,
        ];

        $columns = [
            'date' => 'Date', 'broker' => 'Broker', 'invoice_number' => 'Invoice',
            'type' => 'Type', 'base_amount' => 'Base Value', 'commission_percent' => 'Rate %',
            'commission_amount' => 'Commission', 'settled_amount' => 'Settled',
            'outstanding' => 'Outstanding', 'status' => 'Status',
        ];

        if ($request->query('format', 'json') === 'json') {
            $totals = (clone $query);
            $perPage = min((int) $request->query('per_page', 20), 200);
            $page = $query->paginate($perPage);
            $rows = $page->getCollection()->map($map)->all();

            return response()->json(['data' => $rows, 'meta' => [
                'current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(),
                'total_commission' => round((float) $totals->sum('commission_amount'), 2),
                'total_settled' => round((float) (clone $totals)->sum('settled_amount'), 2),
                'total_outstanding' => round((float) (clone $totals)->sum('commission_amount') - (float) (clone $totals)->sum('settled_amount'), 2),
            ]]);
        }

        $rows = $query->limit(self::EXPORT_LIMIT)->get()->map($map)->all();

        return $this->exportResponse($request, $rows, $columns, 'broker-commissions');
    }

    /**
     * Monthly statement for one broker: opening balance carried into the month,
     * commissions accrued and settlements paid within the month, and the closing
     * balance. Drives the "Monthly Settlement" view; `?month=YYYY-MM`.
     */
    public function monthlyStatement(Request $request, Broker $broker)
    {
        $month = $request->query('month', now()->format('Y-m'));
        $start = Carbon::createFromFormat('Y-m', $month)->startOfMonth();
        $end = (clone $start)->endOfMonth();

        // Opening = broker opening balance + everything before this month.
        $priorEarned = (float) $broker->commissions()->where('created_at', '<', $start)->sum('commission_amount');
        $priorSettled = (float) $broker->settlements()->where('settlement_date', '<', $start->toDateString())->sum('amount');
        $opening = round((float) $broker->opening_balance + $priorEarned - $priorSettled, 2);

        $commissions = $broker->commissions()
            ->whereBetween('created_at', [$start, $end])
            ->with('invoice:id,invoice_number')
            ->orderBy('id')
            ->get()
            ->map(fn ($c) => [
                'date' => $c->created_at?->toDateString(),
                'type' => $c->type,
                'invoice_number' => $c->invoice?->invoice_number,
                'base_amount' => (float) $c->base_amount,
                'commission_amount' => (float) $c->commission_amount,
            ])->all();

        $settlements = $broker->settlements()
            ->whereBetween('settlement_date', [$start->toDateString(), $end->toDateString()])
            ->orderBy('id')
            ->get()
            ->map(fn ($s) => [
                'date' => $s->settlement_date?->toDateString(),
                'settlement_number' => $s->settlement_number,
                'mode' => $s->mode,
                'amount' => (float) $s->amount,
                'tds_amount' => (float) $s->tds_amount,
                'net_paid' => (float) ($s->net_paid ?? $s->amount),
            ])->all();

        $earnedInMonth = round((float) collect($commissions)->sum('commission_amount'), 2);
        $settledInMonth = round((float) collect($settlements)->sum('amount'), 2);
        $tdsInMonth = round((float) collect($settlements)->sum('tds_amount'), 2);

        return response()->json([
            'broker' => ['id' => $broker->id, 'code' => $broker->code, 'name' => $broker->name],
            'month' => $month,
            'opening_balance' => $opening,
            'commissions' => $commissions,
            'settlements' => $settlements,
            'summary' => [
                'commission_earned' => $earnedInMonth,
                'settled' => $settledInMonth,
                'tds_withheld' => $tdsInMonth,
                'closing_balance' => round($opening + $earnedInMonth - $settledInMonth, 2),
            ],
        ]);
    }

    /** Sales aggregated per broker: order count, sales value, commission earned. */
    public function brokerWiseSales(Request $request)
    {
        $commissionAgg = BrokerCommission::query()
            ->selectRaw('broker_id, SUM(commission_amount) as commission_total')
            ->groupBy('broker_id');

        $query = SalesOrder::query()
            ->join('brokers', 'brokers.id', '=', 'sales_orders.broker_id')
            ->leftJoinSub($commissionAgg, 'ca', 'ca.broker_id', '=', 'sales_orders.broker_id')
            ->where('sales_orders.status', '!=', 'cancelled')
            ->whereNotNull('sales_orders.broker_id');

        if ($request->filled('date_from')) {
            $query->whereDate('sales_orders.order_date', '>=', $request->query('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('sales_orders.order_date', '<=', $request->query('date_to'));
        }

        $rows = $query
            ->selectRaw('brokers.code as broker_code, brokers.name as broker,
                COUNT(sales_orders.id) as order_count,
                SUM(sales_orders.subtotal) as sales_value,
                SUM(sales_orders.total) as sales_total,
                MAX(ca.commission_total) as commission_total')
            ->groupBy('brokers.id', 'brokers.code', 'brokers.name')
            ->orderByDesc('sales_total')
            ->get()
            ->map(fn ($r) => [
                'broker_code' => $r->broker_code,
                'broker' => $r->broker,
                'order_count' => (int) $r->order_count,
                'sales_value' => round((float) $r->sales_value, 2),
                'sales_total' => round((float) $r->sales_total, 2),
                'commission_total' => round((float) $r->commission_total, 2),
            ])->all();

        $columns = [
            'broker_code' => 'Code', 'broker' => 'Broker', 'order_count' => 'Orders',
            'sales_value' => 'Sales Value', 'sales_total' => 'Sales (incl. tax)',
            'commission_total' => 'Commission',
        ];

        return $this->exportResponse($request, $rows, $columns, 'broker-wise-sales', [
            'total_sales' => round((float) collect($rows)->sum('sales_total'), 2),
            'total_commission' => round((float) collect($rows)->sum('commission_total'), 2),
        ]);
    }
}
