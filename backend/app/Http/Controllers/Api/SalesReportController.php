<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ExportsReports;
use App\Http\Controllers\Controller;
use App\Models\SalesItem;
use App\Models\SalesOrder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SalesReportController extends Controller
{
    use ExportsReports;

    private const EXPORT_LIMIT = 10000;

    /** Sales orders with totals, filterable by customer/date/status. */
    public function summary(Request $request)
    {
        $query = SalesOrder::query()->with(['customer:id,name', 'warehouse:id,name']);

        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->query('customer_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }
        if ($request->filled('date_from')) {
            $query->whereDate('order_date', '>=', $request->query('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('order_date', '<=', $request->query('date_to'));
        }

        $query->orderByDesc('order_date');

        $map = fn (SalesOrder $so) => [
            'so_number' => $so->so_number,
            'customer' => $so->customer?->name,
            'warehouse' => $so->warehouse?->name,
            'order_date' => $so->order_date?->toDateString(),
            'status' => $so->status,
            'subtotal' => (float) $so->subtotal,
            'tax_amount' => (float) $so->tax_amount,
            'total' => (float) $so->total,
        ];

        $columns = [
            'so_number' => 'SO Number', 'customer' => 'Customer', 'warehouse' => 'Warehouse',
            'order_date' => 'Order Date', 'status' => 'Status',
            'subtotal' => 'Subtotal', 'tax_amount' => 'Tax', 'total' => 'Total',
        ];

        if ($request->query('format', 'json') === 'json') {
            $grandTotal = (float) (clone $query)->sum('total');
            $perPage = min((int) $request->query('per_page', 20), 200);
            $page = $query->paginate($perPage);
            $page->getCollection()->transform($map);

            return response()->json(['data' => $page->items(), 'meta' => [
                'current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(),
                'grand_total' => round($grandTotal, 2),
            ]]);
        }

        $rows = $query->limit(self::EXPORT_LIMIT)->get()->map($map)->all();

        return $this->exportResponse($request, $rows, $columns, 'sales-report');
    }

    /**
     * Profitability per product (or per category): revenue minus the actual
     * historical cost basis recorded on the stock ledger at the time of sale
     * (StockService books one 'sale' ledger row per line at its costed
     * unit_cost — using that, not today's average_cost, keeps this accurate
     * even after the item's cost has since moved).
     */
    public function profitability(Request $request)
    {
        $groupByCategory = $request->query('group_by') === 'category';

        $costAgg = DB::table('stock_ledgers')
            ->where('type', 'sale')
            ->where('reference_type', SalesOrder::class)
            ->selectRaw('reference_id, product_id, SUM(quantity_out * unit_cost) as cost_total')
            ->groupBy('reference_id', 'product_id');

        $query = SalesItem::query()
            ->join('sales_orders', 'sales_orders.id', '=', 'sales_items.sales_order_id')
            ->join('products', 'products.id', '=', 'sales_items.product_id')
            ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
            ->leftJoinSub($costAgg, 'cost_agg', function ($join) {
                $join->on('cost_agg.reference_id', '=', 'sales_orders.id')
                    ->on('cost_agg.product_id', '=', 'sales_items.product_id');
            })
            ->where('sales_orders.status', '!=', 'cancelled');

        if ($request->filled('date_from')) {
            $query->whereDate('sales_orders.order_date', '>=', $request->query('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('sales_orders.order_date', '<=', $request->query('date_to'));
        }
        if ($request->filled('category_id')) {
            $query->where('products.category_id', $request->query('category_id'));
        }
        if (! $groupByCategory && $request->filled('product_id')) {
            $query->where('products.id', $request->query('product_id'));
        }

        if ($groupByCategory) {
            $query->selectRaw("COALESCE(categories.name, 'Uncategorized') as category,
                SUM(sales_items.quantity) as qty_sold,
                SUM(sales_items.line_total - sales_items.tax_amount) as revenue,
                SUM(COALESCE(cost_agg.cost_total, 0)) as cost_total")
                ->groupBy('categories.id', 'categories.name');
        } else {
            $query->selectRaw("products.sku, products.name as product_name, COALESCE(categories.name, 'Uncategorized') as category,
                SUM(sales_items.quantity) as qty_sold,
                SUM(sales_items.line_total - sales_items.tax_amount) as revenue,
                SUM(COALESCE(cost_agg.cost_total, 0)) as cost_total")
                ->groupBy('products.id', 'products.sku', 'products.name', 'categories.id', 'categories.name');
        }

        $rows = $query->get()->map(function ($r) use ($groupByCategory) {
            $revenue = (float) $r->revenue;
            $cost = (float) $r->cost_total;
            $profit = round($revenue - $cost, 2);
            $row = $groupByCategory ? ['category' => $r->category] : ['sku' => $r->sku, 'product_name' => $r->product_name, 'category' => $r->category];

            return $row + [
                'qty_sold' => (float) $r->qty_sold,
                'revenue' => round($revenue, 2),
                'cost' => round($cost, 2),
                'profit' => $profit,
                'margin_percent' => $revenue > 0 ? round($profit / $revenue * 100, 1) : null,
            ];
        })->sortByDesc('profit')->values()->all();

        $columns = $groupByCategory
            ? ['category' => 'Category', 'qty_sold' => 'Qty Sold', 'revenue' => 'Revenue', 'cost' => 'Cost', 'profit' => 'Profit', 'margin_percent' => 'Margin %']
            : ['sku' => 'SKU', 'product_name' => 'Product', 'category' => 'Category', 'qty_sold' => 'Qty Sold', 'revenue' => 'Revenue', 'cost' => 'Cost', 'profit' => 'Profit', 'margin_percent' => 'Margin %'];

        return $this->exportResponse($request, $rows, $columns, 'profitability');
    }

    /** GST output tax summary from sales invoices lines, grouped by HSN code and tax rate. */
    public function gstOutput(Request $request)
    {
        $query = SalesItem::query()
            ->join('products', 'products.id', '=', 'sales_items.product_id')
            ->join('sales_orders', 'sales_orders.id', '=', 'sales_items.sales_order_id')
            ->where('sales_orders.status', '!=', 'cancelled')
            ->selectRaw("COALESCE(products.hsn_code, 'N/A') as hsn_code, sales_items.tax_percent,
                SUM(sales_items.line_total - sales_items.tax_amount) as taxable_value,
                SUM(sales_items.tax_amount) as tax_amount")
            ->groupBy('hsn_code', 'sales_items.tax_percent');

        if ($request->filled('date_from')) {
            $query->whereDate('sales_orders.order_date', '>=', $request->query('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('sales_orders.order_date', '<=', $request->query('date_to'));
        }

        $rows = $query->orderBy('hsn_code')->get()->map(fn ($r) => [
            'hsn_code' => $r->hsn_code,
            'tax_percent' => (float) $r->tax_percent,
            'taxable_value' => round((float) $r->taxable_value, 2),
            'tax_amount' => round((float) $r->tax_amount, 2),
        ])->all();

        $columns = [
            'hsn_code' => 'HSN Code', 'tax_percent' => 'Tax %',
            'taxable_value' => 'Taxable Value', 'tax_amount' => 'Tax Amount',
        ];

        return $this->exportResponse($request, $rows, $columns, 'gst-output', [
            'total_tax' => round((float) collect($rows)->sum('tax_amount'), 2),
        ]);
    }
}
