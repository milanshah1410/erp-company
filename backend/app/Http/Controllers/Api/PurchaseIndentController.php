<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PurchaseIndentRequest;
use App\Http\Resources\PurchaseIndentResource;
use App\Http\Resources\PurchaseOrderResource;
use App\Http\Resources\RfqResource;
use App\Models\PurchaseIndent;
use App\Models\PurchaseOrder;
use App\Models\Rfq;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PurchaseIndentController extends Controller
{
    public function index(Request $request)
    {
        $query = PurchaseIndent::query()
            ->with(['department', 'warehouse'])
            ->withCount(['items', 'rfqs']);

        if ($search = $request->query('search')) {
            $query->where('indent_number', 'like', "%{$search}%");
        }
        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }
        if ($request->filled('priority')) {
            $query->where('priority', $request->query('priority'));
        }
        if ($request->filled('department_id')) {
            $query->where('department_id', $request->query('department_id'));
        }

        $query->orderByDesc('id');
        $perPage = min((int) $request->query('per_page', 15), 100);

        return PurchaseIndentResource::collection($query->paginate($perPage));
    }

    public function store(PurchaseIndentRequest $request)
    {
        $indent = DB::transaction(function () use ($request) {
            $data = $request->validated();

            $indent = PurchaseIndent::create([
                'indent_number' => PurchaseIndent::nextNumber(),
                'department_id' => $data['department_id'] ?? null,
                'warehouse_id' => $data['warehouse_id'] ?? null,
                'status' => 'draft',
                'priority' => $data['priority'] ?? 'normal',
                'indent_date' => $data['indent_date'],
                'required_date' => $data['required_date'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $request->user()?->id,
            ]);

            $this->syncItems($indent, $data['items']);

            return $indent;
        });

        return (new PurchaseIndentResource($indent->load(['department', 'warehouse', 'items.product'])))
            ->response()->setStatusCode(201);
    }

    public function show(PurchaseIndent $purchaseIndent)
    {
        return new PurchaseIndentResource(
            $purchaseIndent->load(['department', 'warehouse', 'items.product'])
        );
    }

    public function update(PurchaseIndentRequest $request, PurchaseIndent $purchaseIndent)
    {
        if (! in_array($purchaseIndent->status, ['draft', 'rejected'], true)) {
            return response()->json(['message' => 'Only draft or rejected indents can be edited.'], 422);
        }

        DB::transaction(function () use ($request, $purchaseIndent) {
            $data = $request->validated();
            $purchaseIndent->update([
                'department_id' => $data['department_id'] ?? null,
                'warehouse_id' => $data['warehouse_id'] ?? null,
                'priority' => $data['priority'] ?? 'normal',
                'indent_date' => $data['indent_date'],
                'required_date' => $data['required_date'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);
            $purchaseIndent->items()->delete();
            $this->syncItems($purchaseIndent, $data['items']);
        });

        return new PurchaseIndentResource($purchaseIndent->load(['department', 'warehouse', 'items.product']));
    }

    public function destroy(PurchaseIndent $purchaseIndent)
    {
        if (! in_array($purchaseIndent->status, ['draft', 'rejected'], true)) {
            return response()->json(['message' => 'Only draft or rejected indents can be deleted.'], 422);
        }
        $purchaseIndent->delete();

        return response()->json(['message' => 'Purchase indent deleted.']);
    }

    /** Move a draft into the approval queue. */
    public function submit(PurchaseIndent $purchaseIndent)
    {
        if (! in_array($purchaseIndent->status, ['draft', 'rejected'], true)) {
            return response()->json(['message' => 'Only draft or rejected indents can be submitted.'], 422);
        }
        $purchaseIndent->update(['status' => 'pending_approval', 'rejection_reason' => null]);

        return new PurchaseIndentResource($purchaseIndent->load(['department', 'warehouse', 'items.product']));
    }

    /** Approve a pending indent (guarded by purchase-indents.approve). */
    public function approve(Request $request, PurchaseIndent $purchaseIndent)
    {
        if ($purchaseIndent->status !== 'pending_approval') {
            return response()->json(['message' => 'Only pending indents can be approved.'], 422);
        }
        $purchaseIndent->update([
            'status' => 'approved',
            'approved_by' => $request->user()?->id,
            'approved_at' => now(),
        ]);

        return new PurchaseIndentResource($purchaseIndent->load(['department', 'warehouse', 'items.product']));
    }

    public function reject(Request $request, PurchaseIndent $purchaseIndent)
    {
        if ($purchaseIndent->status !== 'pending_approval') {
            return response()->json(['message' => 'Only pending indents can be rejected.'], 422);
        }
        $data = $request->validate(['reason' => ['nullable', 'string']]);
        $purchaseIndent->update([
            'status' => 'rejected',
            'rejection_reason' => $data['reason'] ?? null,
        ]);

        return new PurchaseIndentResource($purchaseIndent->load(['department', 'warehouse', 'items.product']));
    }

    /** Spin up a draft RFQ from an approved indent, seeded with its line items. */
    public function convertToRfq(Request $request, PurchaseIndent $purchaseIndent)
    {
        if ($purchaseIndent->status !== 'approved') {
            return response()->json(['message' => 'Only approved indents can be converted to an RFQ.'], 422);
        }

        $data = $request->validate([
            'supplier_ids' => ['required', 'array', 'min:1'],
            'supplier_ids.*' => ['exists:suppliers,id'],
            'response_deadline' => ['nullable', 'date'],
        ]);

        $purchaseIndent->load('items');

        $rfq = DB::transaction(function () use ($purchaseIndent, $data, $request) {
            $rfq = Rfq::create([
                'rfq_number' => Rfq::nextNumber(),
                'purchase_indent_id' => $purchaseIndent->id,
                'warehouse_id' => $purchaseIndent->warehouse_id,
                'status' => 'draft',
                'rfq_date' => now()->toDateString(),
                'response_deadline' => $data['response_deadline'] ?? null,
                'created_by' => $request->user()?->id,
            ]);

            foreach ($purchaseIndent->items as $item) {
                $rfq->items()->create([
                    'product_id' => $item->product_id,
                    'variant_id' => $item->variant_id,
                    'quantity' => $item->quantity,
                ]);
            }

            foreach (array_unique($data['supplier_ids']) as $supplierId) {
                $rfq->suppliers()->create(['supplier_id' => $supplierId, 'status' => 'invited']);
            }

            $purchaseIndent->update(['status' => 'converted']);

            return $rfq;
        });

        return (new RfqResource($rfq->load(['items.product', 'suppliers.supplier'])))
            ->additional(['indent_number' => $purchaseIndent->indent_number])
            ->response()->setStatusCode(201);
    }

    /** Convert an approved indent straight into a draft PO for a chosen supplier. */
    public function convertToPo(Request $request, PurchaseIndent $purchaseIndent)
    {
        if ($purchaseIndent->status !== 'approved') {
            return response()->json(['message' => 'Only approved indents can be converted to a PO.'], 422);
        }

        $data = $request->validate([
            'supplier_id' => ['required', 'exists:suppliers,id'],
            'warehouse_id' => ['required', 'exists:warehouses,id'],
        ]);

        $purchaseIndent->load('items.product');

        $po = DB::transaction(function () use ($purchaseIndent, $data, $request) {
            $po = PurchaseOrder::create([
                'po_number' => PurchaseOrder::nextNumber(),
                'supplier_id' => $data['supplier_id'],
                'warehouse_id' => $data['warehouse_id'],
                'purchase_indent_id' => $purchaseIndent->id,
                'status' => 'draft',
                'order_date' => now()->toDateString(),
                'created_by' => $request->user()?->id,
            ]);

            $subtotal = 0;
            $taxTotal = 0;
            foreach ($purchaseIndent->items as $item) {
                // Estimated cost from the indent, else fall back to the product's cost price.
                $cost = (float) $item->estimated_cost > 0
                    ? (float) $item->estimated_cost
                    : (float) ($item->product?->cost_price ?? 0);
                $taxPercent = (float) ($item->product?->tax_percent ?? 0);
                $qty = (float) $item->quantity;
                $lineBase = $qty * $cost;
                $lineTax = round($lineBase * $taxPercent / 100, 2);

                $po->items()->create([
                    'product_id' => $item->product_id,
                    'variant_id' => $item->variant_id,
                    'quantity' => $qty,
                    'received_quantity' => 0,
                    'unit_cost' => $cost,
                    'tax_percent' => $taxPercent,
                    'tax_amount' => $lineTax,
                    'line_total' => round($lineBase + $lineTax, 2),
                ]);
                $subtotal += $lineBase;
                $taxTotal += $lineTax;
            }

            $po->update([
                'subtotal' => round($subtotal, 2),
                'tax_amount' => round($taxTotal, 2),
                'total' => round($subtotal + $taxTotal, 2),
            ]);

            $purchaseIndent->update(['status' => 'converted']);

            return $po;
        });

        return (new PurchaseOrderResource($po->load(['supplier', 'warehouse', 'items.product'])))
            ->additional(['indent_number' => $purchaseIndent->indent_number])
            ->response()->setStatusCode(201);
    }

    private function syncItems(PurchaseIndent $indent, array $items): void
    {
        foreach ($items as $item) {
            $indent->items()->create([
                'product_id' => $item['product_id'],
                'variant_id' => $item['variant_id'] ?? null,
                'quantity' => (float) $item['quantity'],
                'estimated_cost' => (float) ($item['estimated_cost'] ?? 0),
                'notes' => $item['notes'] ?? null,
            ]);
        }
    }
}
