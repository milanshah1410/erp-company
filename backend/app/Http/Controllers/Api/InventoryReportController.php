<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ExportsReports;
use App\Http\Controllers\Controller;
use App\Models\Batch;
use App\Models\Product;
use App\Models\StockLedger;
use App\Models\WarehouseStock;
use Carbon\Carbon;
use Illuminate\Http\Request;

class InventoryReportController extends Controller
{
    use ExportsReports;

    /** Safety cap on rows pulled for a non-paginated export. */
    private const EXPORT_LIMIT = 10000;

    /**
     * Dead-stock / slow-moving report: on-hand stock with no outward
     * movement in the last N days (default 90), or none ever recorded.
     */
    public function deadStock(Request $request)
    {
        $days = max(1, (int) $request->query('days', 90));
        $cutoff = now()->subDays($days);

        $stocks = WarehouseStock::query()
            ->with(['product:id,sku,name', 'warehouse:id,name'])
            ->where('quantity', '>', 0)
            ->get();

        if ($stocks->isEmpty()) {
            return response()->json(['data' => [], 'meta' => ['days' => $days]]);
        }

        $lastMovements = StockLedger::query()
            ->where('quantity_out', '>', 0)
            ->whereIn('product_id', $stocks->pluck('product_id')->unique())
            ->whereIn('warehouse_id', $stocks->pluck('warehouse_id')->unique())
            ->selectRaw('product_id, warehouse_id, MAX(created_at) as last_movement_at')
            ->groupBy('product_id', 'warehouse_id')
            ->get()
            ->keyBy(fn ($r) => "{$r->product_id}-{$r->warehouse_id}");

        $rows = $stocks->map(function (WarehouseStock $stock) use ($lastMovements) {
            $lastMovement = $lastMovements->get("{$stock->product_id}-{$stock->warehouse_id}");
            $lastMovementAt = $lastMovement ? Carbon::parse($lastMovement->last_movement_at) : null;

            return [
                'product' => $stock->product ? ['id' => $stock->product->id, 'sku' => $stock->product->sku, 'name' => $stock->product->name] : null,
                'warehouse' => $stock->warehouse ? ['id' => $stock->warehouse->id, 'name' => $stock->warehouse->name] : null,
                'quantity' => $stock->quantity,
                'stock_value' => round((float) $stock->quantity * (float) $stock->average_cost, 2),
                'last_movement_at' => $lastMovementAt,
                'days_idle' => $lastMovementAt ? now()->diffInDays($lastMovementAt) : null,
            ];
        })->filter(fn ($row) => ! $row['last_movement_at'] || $row['last_movement_at'] < $cutoff)
            ->sortByDesc(fn ($row) => $row['days_idle'] ?? PHP_INT_MAX)
            ->values();

        return response()->json(['data' => $rows, 'meta' => ['days' => $days]]);
    }

    /** Current on-hand stock, exportable. */
    public function currentStock(Request $request)
    {
        return $this->stockLevelReport($request, fn ($q) => $q, 'current-stock');
    }

    /** On-hand stock at or below the product's reorder level. */
    public function lowStock(Request $request)
    {
        return $this->stockLevelReport($request, function ($q) {
            $q->whereHas('product', function ($p) {
                $p->whereColumn('reorder_level', '>=', 'warehouse_stocks.quantity')->where('reorder_level', '>', 0);
            });
        }, 'low-stock');
    }

    /** On-hand stock that has gone negative (data-integrity / oversell watch). */
    public function negativeStock(Request $request)
    {
        return $this->stockLevelReport($request, fn ($q) => $q->where('quantity', '<', 0), 'negative-stock');
    }

    private function stockLevelReport(Request $request, callable $scope, string $filename)
    {
        $columns = [
            'sku' => 'SKU',
            'product_name' => 'Product',
            'warehouse' => 'Warehouse',
            'quantity' => 'Quantity',
            'reserved_quantity' => 'Reserved',
            'available_quantity' => 'Available',
            'average_cost' => 'Avg Cost',
            'stock_value' => 'Stock Value',
        ];

        $query = WarehouseStock::query()->with(['product:id,sku,name,reorder_level', 'warehouse:id,name']);
        $scope($query);

        if ($request->filled('warehouse_id')) {
            $query->where('warehouse_id', $request->query('warehouse_id'));
        }
        if ($request->filled('product_id')) {
            $query->where('product_id', $request->query('product_id'));
        }

        $query->orderByDesc('quantity');

        $map = fn (WarehouseStock $s) => [
            'sku' => $s->product?->sku,
            'product_name' => $s->product?->name,
            'warehouse' => $s->warehouse?->name,
            'quantity' => (float) $s->quantity,
            'reserved_quantity' => (float) $s->reserved_quantity,
            'available_quantity' => $s->availableQuantity(),
            'average_cost' => (float) $s->average_cost,
            'stock_value' => round((float) $s->quantity * (float) $s->average_cost, 2),
        ];

        if ($request->query('format', 'json') === 'json') {
            $perPage = min((int) $request->query('per_page', 20), 200);
            $page = $query->paginate($perPage);
            $page->getCollection()->transform($map);

            return response()->json(['data' => $page->items(), 'meta' => [
                'current_page' => $page->currentPage(), 'per_page' => $page->perPage(),
                'total' => $page->total(), 'last_page' => $page->lastPage(),
            ]]);
        }

        $rows = $query->limit(self::EXPORT_LIMIT)->get()->map($map)->all();

        return $this->exportResponse($request, $rows, $columns, $filename);
    }

    /** Batches expiring within (or already past) the given window. */
    public function expiryStock(Request $request)
    {
        $days = max(0, (int) $request->query('days', 30));
        $threshold = now()->addDays($days)->endOfDay();

        $query = Batch::query()
            ->with(['product:id,sku,name', 'warehouse:id,name'])
            ->where('quantity', '>', 0)
            ->whereNotNull('expiry_date')
            ->where('expiry_date', '<=', $threshold)
            ->orderBy('expiry_date');

        if ($request->filled('warehouse_id')) {
            $query->where('warehouse_id', $request->query('warehouse_id'));
        }
        if ($request->filled('product_id')) {
            $query->where('product_id', $request->query('product_id'));
        }

        $columns = [
            'sku' => 'SKU', 'product_name' => 'Product', 'warehouse' => 'Warehouse',
            'batch_number' => 'Batch', 'expiry_date' => 'Expiry Date',
            'quantity' => 'Quantity', 'days_to_expiry' => 'Days to Expiry', 'is_expired' => 'Expired',
        ];

        $map = fn (Batch $b) => [
            'sku' => $b->product?->sku,
            'product_name' => $b->product?->name,
            'warehouse' => $b->warehouse?->name,
            'batch_number' => $b->batch_number,
            'expiry_date' => $b->expiry_date?->toDateString(),
            'quantity' => (float) $b->quantity,
            'days_to_expiry' => $b->expiry_date ? now()->startOfDay()->diffInDays($b->expiry_date, false) : null,
            'is_expired' => (bool) ($b->expiry_date && $b->expiry_date->isPast()),
        ];

        if ($request->query('format', 'json') === 'json') {
            $perPage = min((int) $request->query('per_page', 20), 200);
            $page = $query->paginate($perPage);
            $page->getCollection()->transform($map);

            return response()->json(['data' => $page->items(), 'meta' => [
                'days' => $days, 'current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(),
            ]]);
        }

        $rows = $query->limit(self::EXPORT_LIMIT)->get()->map($map)->all();

        return $this->exportResponse($request, $rows, $columns, 'expiry-stock', ['days' => $days]);
    }

    /** Stock movement totals per product/warehouse over a date range. */
    public function stockMovementSummary(Request $request)
    {
        $query = StockLedger::query()
            ->join('products', 'products.id', '=', 'stock_ledgers.product_id')
            ->join('warehouses', 'warehouses.id', '=', 'stock_ledgers.warehouse_id')
            ->selectRaw('stock_ledgers.product_id, stock_ledgers.warehouse_id, products.sku, products.name as product_name, warehouses.name as warehouse_name,
                SUM(stock_ledgers.quantity_in) as total_in, SUM(stock_ledgers.quantity_out) as total_out')
            ->groupBy('stock_ledgers.product_id', 'stock_ledgers.warehouse_id', 'products.sku', 'products.name', 'warehouses.name');

        if ($request->filled('product_id')) {
            $query->where('stock_ledgers.product_id', $request->query('product_id'));
        }
        if ($request->filled('warehouse_id')) {
            $query->where('stock_ledgers.warehouse_id', $request->query('warehouse_id'));
        }
        if ($request->filled('type')) {
            $query->where('stock_ledgers.type', $request->query('type'));
        }
        if ($request->filled('date_from')) {
            $query->whereDate('stock_ledgers.created_at', '>=', $request->query('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('stock_ledgers.created_at', '<=', $request->query('date_to'));
        }

        $rows = $query->orderByDesc('total_out')->limit(self::EXPORT_LIMIT)->get()->map(fn ($r) => [
            'sku' => $r->sku,
            'product_name' => $r->product_name,
            'warehouse' => $r->warehouse_name,
            'total_in' => (float) $r->total_in,
            'total_out' => (float) $r->total_out,
            'net_change' => (float) $r->total_in - (float) $r->total_out,
        ])->all();

        $columns = [
            'sku' => 'SKU', 'product_name' => 'Product', 'warehouse' => 'Warehouse',
            'total_in' => 'Total In', 'total_out' => 'Total Out', 'net_change' => 'Net Change',
        ];

        return $this->exportResponse($request, $rows, $columns, 'stock-movement');
    }

    /** Fast-moving vs slow/no-moving products, ranked by outward quantity over a window. */
    public function fastSlowMoving(Request $request)
    {
        $days = max(1, (int) $request->query('days', 90));
        $limit = min((int) $request->query('limit', 20), 100);
        $cutoff = now()->subDays($days);

        $movement = StockLedger::query()
            ->join('products', 'products.id', '=', 'stock_ledgers.product_id')
            ->where('stock_ledgers.created_at', '>=', $cutoff)
            ->selectRaw('stock_ledgers.product_id, products.sku, products.name as product_name, SUM(stock_ledgers.quantity_out) as total_out')
            ->groupBy('stock_ledgers.product_id', 'products.sku', 'products.name')
            ->get();

        $moving = $movement->sortByDesc('total_out')->values();
        $fast = $moving->take($limit)->map(fn ($r) => [
            'sku' => $r->sku, 'product_name' => $r->product_name, 'total_out' => (float) $r->total_out, 'movement' => 'fast',
        ]);

        $movedProductIds = $movement->where('total_out', '>', 0)->pluck('product_id');
        $neverMoved = Product::query()
            ->whereNotIn('id', $movedProductIds)
            ->where('is_active', true)
            ->limit($limit)
            ->get(['id', 'sku', 'name']);

        $slow = $moving->where('total_out', '>', 0)->sortBy('total_out')->take($limit)->map(fn ($r) => [
            'sku' => $r->sku, 'product_name' => $r->product_name, 'total_out' => (float) $r->total_out, 'movement' => 'slow',
        ])->concat($neverMoved->map(fn ($p) => [
            'sku' => $p->sku, 'product_name' => $p->name, 'total_out' => 0.0, 'movement' => 'slow',
        ]))->take($limit);

        $rows = $fast->concat($slow)->values()->all();

        $columns = ['sku' => 'SKU', 'product_name' => 'Product', 'total_out' => 'Qty Sold/Issued', 'movement' => 'Movement'];

        return $this->exportResponse($request, $rows, $columns, 'fast-slow-moving', ['days' => $days]);
    }

    /** Inventory valuation grouped by warehouse and category. */
    public function valuation(Request $request)
    {
        $query = WarehouseStock::query()
            ->join('products', 'products.id', '=', 'warehouse_stocks.product_id')
            ->join('warehouses', 'warehouses.id', '=', 'warehouse_stocks.warehouse_id')
            ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
            ->where('warehouse_stocks.quantity', '>', 0)
            ->selectRaw('warehouses.id as warehouse_id, warehouses.name as warehouse_name,
                categories.id as category_id, categories.name as category_name,
                SUM(warehouse_stocks.quantity) as total_quantity,
                SUM(warehouse_stocks.quantity * warehouse_stocks.average_cost) as total_value')
            ->groupBy('warehouses.id', 'warehouses.name', 'categories.id', 'categories.name');

        if ($request->filled('warehouse_id')) {
            $query->where('warehouse_stocks.warehouse_id', $request->query('warehouse_id'));
        }
        if ($request->filled('category_id')) {
            $query->where('products.category_id', $request->query('category_id'));
        }

        $rows = $query->orderByDesc('total_value')->get()->map(fn ($r) => [
            'warehouse' => $r->warehouse_name,
            'category' => $r->category_name ?? 'Uncategorized',
            'quantity' => (float) $r->total_quantity,
            'stock_value' => round((float) $r->total_value, 2),
        ])->all();

        $columns = ['warehouse' => 'Warehouse', 'category' => 'Category', 'quantity' => 'Quantity', 'stock_value' => 'Stock Value'];

        return $this->exportResponse($request, $rows, $columns, 'inventory-valuation', [
            'grand_total' => round((float) collect($rows)->sum('stock_value'), 2),
        ]);
    }

    /**
     * Stock ageing: on-hand stock bucketed by how long it has sat since its
     * last inward movement (0-30 / 31-60 / 61-90 / 90+ days). Stock with no
     * recorded inward movement falls in the oldest bucket.
     */
    public function stockAgeing(Request $request)
    {
        $stocks = WarehouseStock::query()
            ->with(['product:id,sku,name', 'warehouse:id,name'])
            ->where('quantity', '>', 0)
            ->when($request->filled('warehouse_id'), fn ($q) => $q->where('warehouse_id', $request->query('warehouse_id')))
            ->when($request->filled('product_id'), fn ($q) => $q->where('product_id', $request->query('product_id')))
            ->get();

        if ($stocks->isEmpty()) {
            return $this->exportResponse($request, [], $this->ageingColumns(), 'stock-ageing');
        }

        $lastInward = StockLedger::query()
            ->where('quantity_in', '>', 0)
            ->whereIn('product_id', $stocks->pluck('product_id')->unique())
            ->whereIn('warehouse_id', $stocks->pluck('warehouse_id')->unique())
            ->selectRaw('product_id, warehouse_id, MAX(created_at) as last_in_at')
            ->groupBy('product_id', 'warehouse_id')
            ->get()
            ->keyBy(fn ($r) => "{$r->product_id}-{$r->warehouse_id}");

        $today = now();
        $rows = $stocks->map(function (WarehouseStock $stock) use ($lastInward, $today) {
            $last = $lastInward->get("{$stock->product_id}-{$stock->warehouse_id}");
            $lastAt = $last ? Carbon::parse($last->last_in_at) : null;
            $days = $lastAt ? (int) $lastAt->diffInDays($today) : null;
            $bucket = $days === null || $days > 90 ? '90+'
                : ($days <= 30 ? '0-30' : ($days <= 60 ? '31-60' : '61-90'));

            return [
                'sku' => $stock->product?->sku,
                'product_name' => $stock->product?->name,
                'warehouse' => $stock->warehouse?->name,
                'quantity' => (float) $stock->quantity,
                'stock_value' => round((float) $stock->quantity * (float) $stock->average_cost, 2),
                'last_inward_at' => $lastAt?->toDateString(),
                'days_in_stock' => $days,
                'age_bucket' => $bucket,
            ];
        })->sortByDesc(fn ($r) => $r['days_in_stock'] ?? PHP_INT_MAX)->values();

        $bucketTotals = ['0-30' => 0.0, '31-60' => 0.0, '61-90' => 0.0, '90+' => 0.0];
        foreach ($rows as $r) {
            $bucketTotals[$r['age_bucket']] = round($bucketTotals[$r['age_bucket']] + $r['stock_value'], 2);
        }

        return $this->exportResponse($request, $rows->all(), $this->ageingColumns(), 'stock-ageing', [
            'bucket_totals' => $bucketTotals,
            'grand_total' => round((float) $rows->sum('stock_value'), 2),
        ]);
    }

    private function ageingColumns(): array
    {
        return [
            'sku' => 'SKU', 'product_name' => 'Product', 'warehouse' => 'Warehouse',
            'quantity' => 'Quantity', 'stock_value' => 'Stock Value',
            'last_inward_at' => 'Last Inward', 'days_in_stock' => 'Days in Stock', 'age_bucket' => 'Age Bucket',
        ];
    }

    /** Fastest-moving products by outward quantity over a window. */
    public function fastMoving(Request $request)
    {
        return $this->movingReport($request, 'fast');
    }

    /** Slowest / non-moving products by outward quantity over a window. */
    public function slowMoving(Request $request)
    {
        return $this->movingReport($request, 'slow');
    }

    /** Shared ranker for the fast / slow moving reports. */
    private function movingReport(Request $request, string $mode)
    {
        $days = max(1, (int) $request->query('days', 90));
        $limit = min((int) $request->query('limit', 50), 200);
        $cutoff = now()->subDays($days);

        $movement = StockLedger::query()
            ->join('products', 'products.id', '=', 'stock_ledgers.product_id')
            ->where('stock_ledgers.created_at', '>=', $cutoff)
            ->selectRaw('stock_ledgers.product_id, products.sku, products.name as product_name, SUM(stock_ledgers.quantity_out) as total_out')
            ->groupBy('stock_ledgers.product_id', 'products.sku', 'products.name')
            ->get();

        if ($mode === 'fast') {
            $rows = $movement->where('total_out', '>', 0)->sortByDesc('total_out')->take($limit)
                ->map(fn ($r) => ['sku' => $r->sku, 'product_name' => $r->product_name, 'total_out' => (float) $r->total_out])
                ->values()->all();
        } else {
            $movedIds = $movement->where('total_out', '>', 0)->pluck('product_id');
            $slowMoved = $movement->where('total_out', '>', 0)->sortBy('total_out')
                ->map(fn ($r) => ['sku' => $r->sku, 'product_name' => $r->product_name, 'total_out' => (float) $r->total_out]);
            $neverMoved = Product::query()
                ->whereNotIn('id', $movedIds)->where('is_active', true)
                ->limit($limit)->get(['sku', 'name'])
                ->map(fn ($p) => ['sku' => $p->sku, 'product_name' => $p->name, 'total_out' => 0.0]);
            $rows = $neverMoved->concat($slowMoved)->take($limit)->values()->all();
        }

        $columns = ['sku' => 'SKU', 'product_name' => 'Product', 'total_out' => 'Qty Sold/Issued'];

        return $this->exportResponse($request, $rows, $columns, "{$mode}-moving", ['days' => $days]);
    }
}
