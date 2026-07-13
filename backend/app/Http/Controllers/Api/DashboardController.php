<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\StockLedger;
use App\Models\WarehouseStock;

class DashboardController extends Controller
{
    /** Headline KPIs for the landing dashboard. */
    public function kpis()
    {
        $totalStockValue = (float) WarehouseStock::query()
            ->selectRaw('SUM(quantity * average_cost) as total')
            ->value('total') ?? 0;

        $lowStockCount = WarehouseStock::query()
            ->whereHas('product', function ($q) {
                $q->whereColumn('reorder_level', '>=', 'warehouse_stocks.quantity')->where('reorder_level', '>', 0);
            })
            ->count();

        $pendingPoCount = PurchaseOrder::query()->where('status', 'pending_approval')->count();

        $topSkus = StockLedger::query()
            ->join('products', 'products.id', '=', 'stock_ledgers.product_id')
            ->where('stock_ledgers.type', 'sale')
            ->where('stock_ledgers.created_at', '>=', now()->subDays(30))
            ->selectRaw('products.sku, products.name, SUM(stock_ledgers.quantity_out) as qty_sold')
            ->groupBy('products.id', 'products.sku', 'products.name')
            ->orderByDesc('qty_sold')
            ->limit(5)
            ->get()
            ->map(fn ($r) => ['sku' => $r->sku, 'name' => $r->name, 'qty_sold' => (float) $r->qty_sold]);

        return response()->json([
            'total_stock_value' => round($totalStockValue, 2),
            'low_stock_count' => $lowStockCount,
            'pending_po_count' => $pendingPoCount,
            'top_skus' => $topSkus,
            'products_count' => Product::query()->count(),
        ]);
    }
}
