<?php

declare(strict_types=1);

namespace App\Modules\Industry\Healthcare\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Industry\Healthcare\Models\HospitalIndentItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * What each ward actually consumed, and what it cost.
 *
 * This is the report the whole hospital-store module exists to produce. A stock
 * adjustment could already have moved the same gloves out of the same warehouse
 * — what it could never say is that the ICU used them, and a hospital's store is
 * only ever asked one question: which department is spending the money.
 *
 * Read from the indent lines rather than the stock ledger even though both hold
 * the movement, because the department lives on the indent. The ledger is the
 * authority on quantity; the indent is the authority on *who for*, and joining
 * back through the reference would say the same thing more slowly.
 */
class HospitalConsumptionController extends Controller
{
    public function index(Request $request)
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'department_id' => ['nullable', 'integer'],
            'warehouse_id' => ['nullable', 'integer'],
            // 'department' (default) or 'product' — the same numbers cut the two
            // ways anyone asks for them: who spent it, and on what.
            'group_by' => ['nullable', 'in:department,product'],
        ]);

        $from = $validated['from'] ?? now()->startOfMonth()->toDateString();
        $to = $validated['to'] ?? now()->toDateString();
        $groupBy = $validated['group_by'] ?? 'department';

        $base = HospitalIndentItem::query()
            ->join('hospital_indents', 'hospital_indents.id', '=', 'hospital_indent_items.hospital_indent_id')
            // Only what actually left the store. An approved-but-unissued line is
            // a promise, not consumption, and counting it would overstate every
            // ward's spend for as long as the store is behind.
            ->where('hospital_indent_items.issued_quantity', '>', 0)
            ->whereBetween('hospital_indents.indent_date', [$from, $to]);

        if (! empty($validated['department_id'])) {
            $base->where('hospital_indents.department_id', $validated['department_id']);
        }
        if (! empty($validated['warehouse_id'])) {
            $base->where('hospital_indents.warehouse_id', $validated['warehouse_id']);
        }

        $rows = $groupBy === 'product'
            ? $this->byProduct(clone $base)
            : $this->byDepartment(clone $base);

        return response()->json([
            'data' => $rows,
            'meta' => [
                'from' => $from,
                'to' => $to,
                'group_by' => $groupBy,
                'total_value' => round(array_sum(array_column($rows, 'value')), 2),
                'total_quantity' => round(array_sum(array_column($rows, 'quantity')), 3),
            ],
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function byDepartment($query): array
    {
        return $query
            ->join('departments', 'departments.id', '=', 'hospital_indents.department_id')
            ->groupBy('departments.id', 'departments.code', 'departments.name')
            ->selectRaw('departments.id as id, departments.code as code, departments.name as label')
            ->selectRaw('COUNT(DISTINCT hospital_indents.id) as indents')
            ->selectRaw('SUM(hospital_indent_items.issued_quantity) as quantity')
            ->selectRaw('SUM(hospital_indent_items.issue_value) as value')
            ->orderByDesc('value')
            ->get()
            ->map(fn ($r) => [
                'id' => (int) $r->id,
                'code' => $r->code,
                'label' => $r->label,
                'indents' => (int) $r->indents,
                'quantity' => round((float) $r->quantity, 3),
                'value' => round((float) $r->value, 2),
            ])
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function byProduct($query): array
    {
        return $query
            ->join('products', 'products.id', '=', 'hospital_indent_items.product_id')
            ->groupBy('products.id', 'products.sku', 'products.name')
            ->selectRaw('products.id as id, products.sku as code, products.name as label')
            ->selectRaw('COUNT(DISTINCT hospital_indents.department_id) as departments')
            ->selectRaw('SUM(hospital_indent_items.issued_quantity) as quantity')
            ->selectRaw('SUM(hospital_indent_items.issue_value) as value')
            ->orderByDesc('value')
            ->get()
            ->map(fn ($r) => [
                'id' => (int) $r->id,
                'code' => $r->code,
                'label' => $r->label,
                'departments' => (int) $r->departments,
                'quantity' => round((float) $r->quantity, 3),
                'value' => round((float) $r->value, 2),
            ])
            ->all();
    }

    /**
     * One department's consumption, item by item — the drill-down behind a row
     * of the summary above.
     */
    public function detail(Request $request)
    {
        $validated = $request->validate([
            'department_id' => ['required', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $from = $validated['from'] ?? now()->startOfMonth()->toDateString();
        $to = $validated['to'] ?? now()->toDateString();

        $rows = HospitalIndentItem::query()
            ->join('hospital_indents', 'hospital_indents.id', '=', 'hospital_indent_items.hospital_indent_id')
            ->join('products', 'products.id', '=', 'hospital_indent_items.product_id')
            ->leftJoin('batches', 'batches.id', '=', 'hospital_indent_items.batch_id')
            ->where('hospital_indents.department_id', $validated['department_id'])
            ->where('hospital_indent_items.issued_quantity', '>', 0)
            ->whereBetween('hospital_indents.indent_date', [$from, $to])
            ->orderByDesc('hospital_indents.indent_date')
            ->get([
                'hospital_indents.indent_number',
                'hospital_indents.indent_date',
                'products.sku',
                'products.name as product',
                'batches.batch_number',
                DB::raw('hospital_indent_items.issued_quantity as quantity'),
                DB::raw('hospital_indent_items.unit_cost as unit_cost'),
                DB::raw('hospital_indent_items.issue_value as value'),
            ])
            ->map(fn ($r) => [
                'indent_number' => $r->indent_number,
                'indent_date' => $r->indent_date,
                'sku' => $r->sku,
                'product' => $r->product,
                'batch_number' => $r->batch_number,
                'quantity' => (float) $r->quantity,
                'unit_cost' => (float) $r->unit_cost,
                'value' => (float) $r->value,
            ])
            ->all();

        return response()->json([
            'data' => $rows,
            'meta' => ['from' => $from, 'to' => $to, 'total_value' => round(array_sum(array_column($rows, 'value')), 2)],
        ]);
    }
}
