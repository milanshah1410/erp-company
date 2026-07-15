<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\SalesOrder;
use App\Models\StockLedger;
use App\Models\WarehouseStock;
use App\Support\TenantCache;

class DashboardController extends Controller
{
    /** How long the computed dashboard payload is cached per tenant (seconds). */
    private const CACHE_TTL = 300;

    /**
     * All headline metrics for the landing dashboard (current tenant).
     *
     * The payload is a dozen-plus aggregate queries, so it is cached per tenant
     * (key "tenant_{id}_dashboard") for a few minutes rather than recomputed on
     * every page load. The short TTL bounds staleness without needing every
     * sale/purchase write to invalidate the cache.
     */
    public function kpis()
    {
        return response()->json(
            TenantCache::remember('dashboard', self::CACHE_TTL, fn () => $this->buildKpis())
        );
    }

    /** Compute the full dashboard metrics payload for the current tenant. */
    private function buildKpis(): array
    {
        $today = now()->toDateString();
        $monthStart = now()->startOfMonth();
        $monthEnd = now()->endOfMonth();

        // 1. Today's Sales — billed revenue + invoice count for today.
        $todaySales = Invoice::query()->whereDate('invoice_date', $today);
        $todaySalesTotal = (float) (clone $todaySales)->sum('total');
        $todaySalesCount = (clone $todaySales)->count();

        // 2. Purchase Summary — this month's POs + count of open/pending POs.
        $monthPo = PurchaseOrder::query()->whereBetween('order_date', [$monthStart, $monthEnd]);
        $purchaseMonthTotal = (float) (clone $monthPo)->sum('total');
        $purchaseMonthCount = (clone $monthPo)->count();
        $pendingPoCount = PurchaseOrder::query()
            ->whereIn('status', ['draft', 'pending_approval', 'approved', 'partially_received'])
            ->count();

        // 3. Pending Orders — open sales + open purchase orders.
        $pendingSalesCount = SalesOrder::query()->whereIn('status', ['draft', 'confirmed'])->count();

        // 5. Low Stock Alert — on-hand at or below a positive reorder level.
        $lowStockCount = WarehouseStock::query()
            ->whereHas('product', function ($q) {
                $q->whereColumn('reorder_level', '>=', 'warehouse_stocks.quantity')->where('reorder_level', '>', 0);
            })
            ->count();

        // 6. Out of Stock — distinct products with no on-hand quantity anywhere.
        $outOfStockCount = WarehouseStock::query()
            ->where('quantity', '<=', 0)
            ->distinct('product_id')
            ->count('product_id');

        // 8. Profit & Loss (this month): revenue − COGS. No expenses module yet,
        //    so this is gross profit; expenses will be deducted once available.
        $revenue = (float) Invoice::query()->whereBetween('invoice_date', [$monthStart, $monthEnd])->sum('total');
        $cogs = (float) StockLedger::query()
            ->where('type', 'sale')
            ->whereBetween('created_at', [$monthStart, $monthEnd])
            ->selectRaw('SUM(quantity_out * unit_cost) as c')
            ->value('c');

        // 9. Top Selling Products (last 30 days, by quantity sold).
        $topProducts = StockLedger::query()
            ->join('products', 'products.id', '=', 'stock_ledgers.product_id')
            ->where('stock_ledgers.type', 'sale')
            ->where('stock_ledgers.created_at', '>=', now()->subDays(30))
            ->selectRaw('products.sku, products.name, SUM(stock_ledgers.quantity_out) as qty_sold')
            ->groupBy('products.id', 'products.sku', 'products.name')
            ->orderByDesc('qty_sold')
            ->limit(5)
            ->get()
            ->map(fn ($r) => ['sku' => $r->sku, 'name' => $r->name, 'qty_sold' => (float) $r->qty_sold])
            ->all();

        // 10. Recent Activities (tenant-scoped audit log).
        $recentActivities = ActivityLog::query()
            ->with('causer:id,name')
            ->latest('id')
            ->limit(8)
            ->get()
            ->map(fn ($a) => [
                'description' => $a->description,
                'event' => $a->event,
                'log_name' => $a->log_name,
                'causer' => $a->causer?->name,
                'at' => $a->created_at?->toIso8601String(),
            ])
            ->all();

        $totalStockValue = (float) WarehouseStock::query()
            ->selectRaw('SUM(quantity * average_cost) as total')
            ->value('total');

        return [
            'today_sales' => ['total' => round($todaySalesTotal, 2), 'count' => $todaySalesCount],
            'purchase_summary' => [
                'month_total' => round($purchaseMonthTotal, 2),
                'month_count' => $purchaseMonthCount,
                'pending_count' => $pendingPoCount,
            ],
            'pending_orders' => [
                'sales' => $pendingSalesCount,
                'purchases' => $pendingPoCount,
                'total' => $pendingSalesCount + $pendingPoCount,
            ],
            'low_stock_count' => $lowStockCount,
            'out_of_stock_count' => $outOfStockCount,
            'profit_loss' => [
                'revenue' => round($revenue, 2),
                'cogs' => round($cogs, 2),
                'gross_profit' => round($revenue - $cogs, 2),
                'period' => 'This month',
            ],
            'top_products' => $topProducts,
            'recent_activities' => $recentActivities,

            // Retained headline metrics.
            'total_stock_value' => round($totalStockValue, 2),
            'products_count' => Product::query()->count(),
            // Kept for backward compatibility with the previous UI.
            'pending_po_count' => $pendingPoCount,
            'top_skus' => $topProducts,
        ];
    }
}
