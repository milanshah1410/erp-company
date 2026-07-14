<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ExportsReports;
use App\Http\Controllers\Controller;
use App\Models\ManufacturingOrder;
use Illuminate\Http\Request;

class ManufacturingReportController extends Controller
{
    use ExportsReports;

    private const EXPORT_LIMIT = 10000;

    /** Manufacturing orders with quantities, status and cost, filterable by product/status/date. */
    public function summary(Request $request)
    {
        $query = ManufacturingOrder::query()->with(['product:id,sku,name', 'warehouse:id,name']);

        if ($request->filled('product_id')) {
            $query->where('product_id', $request->query('product_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }
        if ($request->filled('warehouse_id')) {
            $query->where('warehouse_id', $request->query('warehouse_id'));
        }
        if ($request->filled('date_from')) {
            $query->whereDate('order_date', '>=', $request->query('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('order_date', '<=', $request->query('date_to'));
        }

        $query->orderByDesc('order_date')->orderByDesc('id');

        $map = fn (ManufacturingOrder $mo) => [
            'mo_number' => $mo->mo_number,
            'product' => $mo->product?->name,
            'sku' => $mo->product?->sku,
            'warehouse' => $mo->warehouse?->name,
            'order_date' => $mo->order_date?->toDateString(),
            'expected_completion_date' => $mo->expected_completion_date?->toDateString(),
            'status' => $mo->status,
            'quantity_to_produce' => (float) $mo->quantity_to_produce,
            'quantity_produced' => (float) $mo->quantity_produced,
            'total_cost' => (float) $mo->total_cost,
        ];

        $columns = [
            'mo_number' => 'MO Number', 'product' => 'Product', 'sku' => 'SKU',
            'warehouse' => 'Warehouse', 'order_date' => 'Order Date',
            'expected_completion_date' => 'Expected', 'status' => 'Status',
            'quantity_to_produce' => 'To Produce', 'quantity_produced' => 'Produced',
            'total_cost' => 'Total Cost',
        ];

        if ($request->query('format', 'json') === 'json') {
            $grandTotal = (float) (clone $query)->sum('total_cost');
            $perPage = min((int) $request->query('per_page', 20), 200);
            $page = $query->paginate($perPage);
            $page->getCollection()->transform($map);

            return response()->json(['data' => $page->items(), 'meta' => [
                'current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(),
                'grand_total' => round($grandTotal, 2),
            ]]);
        }

        $rows = $query->limit(self::EXPORT_LIMIT)->get()->map($map)->all();

        return $this->exportResponse($request, $rows, $columns, 'manufacturing-report');
    }

    /**
     * Production cost report: the full per-order costing — raw material plus each
     * cost bucket (tailor / printing / embroidery / packing / transport / other),
     * the order total and the cost booked into each produced unit.
     */
    public function productionCost(Request $request)
    {
        $query = ManufacturingOrder::query()->with('product:id,sku,name');

        if ($request->filled('product_id')) {
            $query->where('product_id', $request->query('product_id'));
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

        $query->orderByDesc('order_date')->orderByDesc('id');

        $map = fn (ManufacturingOrder $mo) => [
            'mo_number' => $mo->mo_number,
            'product' => $mo->product?->name,
            'quantity' => $mo->costingQuantity(),
            'material_cost' => (float) $mo->material_cost,
            'tailor_cost' => (float) $mo->tailor_cost,
            'printing_cost' => (float) $mo->printing_cost,
            'embroidery_cost' => (float) $mo->embroidery_cost,
            'packing_cost' => (float) $mo->packing_cost,
            'transport_cost' => (float) $mo->transport_cost,
            'other_cost' => (float) $mo->other_cost,
            'total_cost' => (float) $mo->total_cost,
            'per_unit_cost' => $mo->unitProductionCost(),
        ];

        $columns = [
            'mo_number' => 'MO Number', 'product' => 'Product', 'quantity' => 'Quantity',
            'material_cost' => 'Material', 'tailor_cost' => 'Tailor', 'printing_cost' => 'Printing',
            'embroidery_cost' => 'Embroidery', 'packing_cost' => 'Packing', 'transport_cost' => 'Transport',
            'other_cost' => 'Other', 'total_cost' => 'Total Cost', 'per_unit_cost' => 'Per Unit',
        ];

        if ($request->query('format', 'json') === 'json') {
            $perPage = min((int) $request->query('per_page', 20), 200);
            $page = $query->paginate($perPage);
            $totals = (clone $query)->selectRaw('SUM(material_cost) as m, SUM(total_cost) as t')->first();
            $page->getCollection()->transform($map);

            return response()->json(['data' => $page->items(), 'meta' => [
                'current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(),
                'material_total' => round((float) $totals->m, 2),
                'grand_total' => round((float) $totals->t, 2),
            ]]);
        }

        $rows = $query->limit(self::EXPORT_LIMIT)->get()->map($map)->all();

        return $this->exportResponse($request, $rows, $columns, 'production-cost-report');
    }
}
