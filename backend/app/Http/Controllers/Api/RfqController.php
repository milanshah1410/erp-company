<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\RfqQuoteRequest;
use App\Http\Requests\RfqRequest;
use App\Http\Resources\PurchaseOrderResource;
use App\Http\Resources\RfqResource;
use App\Models\PurchaseOrder;
use App\Models\Rfq;
use App\Models\RfqSupplier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RfqController extends Controller
{
    public function index(Request $request)
    {
        $query = Rfq::query()
            ->with(['purchaseIndent', 'warehouse', 'awardedSupplier'])
            ->withCount(['items', 'suppliers']);

        if ($search = $request->query('search')) {
            $query->where('rfq_number', 'like', "%{$search}%");
        }
        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        $query->orderByDesc('id');
        $perPage = min((int) $request->query('per_page', 15), 100);

        return RfqResource::collection($query->paginate($perPage));
    }

    public function store(RfqRequest $request)
    {
        $rfq = DB::transaction(function () use ($request) {
            $data = $request->validated();

            $rfq = Rfq::create([
                'rfq_number' => Rfq::nextNumber(),
                'purchase_indent_id' => $data['purchase_indent_id'] ?? null,
                'warehouse_id' => $data['warehouse_id'] ?? null,
                'status' => 'draft',
                'rfq_date' => $data['rfq_date'],
                'response_deadline' => $data['response_deadline'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $request->user()?->id,
            ]);

            foreach ($data['items'] as $item) {
                $rfq->items()->create([
                    'product_id' => $item['product_id'],
                    'variant_id' => $item['variant_id'] ?? null,
                    'quantity' => (float) $item['quantity'],
                ]);
            }

            foreach (array_unique($data['supplier_ids']) as $supplierId) {
                $rfq->suppliers()->create(['supplier_id' => $supplierId, 'status' => 'invited']);
            }

            return $rfq;
        });

        return (new RfqResource($rfq->load(['items.product', 'suppliers.supplier'])))
            ->response()->setStatusCode(201);
    }

    public function show(Rfq $rfq)
    {
        return new RfqResource(
            $rfq->load([
                'purchaseIndent', 'warehouse', 'awardedSupplier', 'purchaseOrder',
                'items.product', 'suppliers.supplier', 'suppliers.quotes',
            ])
        );
    }

    public function update(RfqRequest $request, Rfq $rfq)
    {
        if ($rfq->status !== 'draft') {
            return response()->json(['message' => 'Only draft RFQs can be edited.'], 422);
        }

        DB::transaction(function () use ($request, $rfq) {
            $data = $request->validated();
            $rfq->update([
                'purchase_indent_id' => $data['purchase_indent_id'] ?? null,
                'warehouse_id' => $data['warehouse_id'] ?? null,
                'rfq_date' => $data['rfq_date'],
                'response_deadline' => $data['response_deadline'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);

            // Rebuild items (quotes cascade away with them, which is fine pre-send).
            $rfq->items()->delete();
            foreach ($data['items'] as $item) {
                $rfq->items()->create([
                    'product_id' => $item['product_id'],
                    'variant_id' => $item['variant_id'] ?? null,
                    'quantity' => (float) $item['quantity'],
                ]);
            }

            // Reconcile the invited supplier set without dropping ones that stay.
            $wanted = array_values(array_unique($data['supplier_ids']));
            $rfq->suppliers()->whereNotIn('supplier_id', $wanted)->delete();
            $existing = $rfq->suppliers()->pluck('supplier_id')->all();
            foreach (array_diff($wanted, $existing) as $supplierId) {
                $rfq->suppliers()->create(['supplier_id' => $supplierId, 'status' => 'invited']);
            }
        });

        return new RfqResource($rfq->load(['items.product', 'suppliers.supplier', 'suppliers.quotes']));
    }

    public function destroy(Rfq $rfq)
    {
        if ($rfq->status !== 'draft') {
            return response()->json(['message' => 'Only draft RFQs can be deleted.'], 422);
        }
        $rfq->delete();

        return response()->json(['message' => 'RFQ deleted.']);
    }

    /** Issue the RFQ to its invited vendors. */
    public function send(Rfq $rfq)
    {
        if ($rfq->status !== 'draft') {
            return response()->json(['message' => 'Only draft RFQs can be sent.'], 422);
        }
        if ($rfq->suppliers()->count() === 0) {
            return response()->json(['message' => 'Add at least one vendor before sending.'], 422);
        }
        $rfq->update(['status' => 'sent']);

        return new RfqResource($rfq->load(['items.product', 'suppliers.supplier', 'suppliers.quotes']));
    }

    /** Record one vendor's quoted prices for the RFQ line items. */
    public function recordQuote(RfqQuoteRequest $request, Rfq $rfq, RfqSupplier $rfqSupplier)
    {
        if ($rfqSupplier->rfq_id !== $rfq->id) {
            throw ValidationException::withMessages(['supplier' => ['This vendor is not part of the RFQ.']]);
        }
        if (! in_array($rfq->status, ['sent'], true)) {
            return response()->json(['message' => 'Quotes can only be recorded on a sent RFQ.'], 422);
        }

        $data = $request->validated();
        $rfq->load('items');
        $itemQty = $rfq->items->pluck('quantity', 'id');

        DB::transaction(function () use ($data, $rfq, $rfqSupplier, $itemQty) {
            $rfqSupplier->quotes()->delete();

            $total = 0;
            foreach ($data['quotes'] as $q) {
                $itemId = (int) $q['rfq_item_id'];
                if (! $itemQty->has($itemId)) {
                    throw ValidationException::withMessages(['quotes' => ["Item {$itemId} is not on this RFQ."]]);
                }
                $price = (float) $q['unit_price'];
                $taxPercent = (float) ($q['tax_percent'] ?? 0);
                $qty = (float) $itemQty[$itemId];
                $lineBase = $qty * $price;
                $total += $lineBase + round($lineBase * $taxPercent / 100, 2);

                $rfqSupplier->quotes()->create([
                    'rfq_id' => $rfq->id,
                    'rfq_item_id' => $itemId,
                    'unit_price' => $price,
                    'tax_percent' => $taxPercent,
                    'remarks' => $q['remarks'] ?? null,
                ]);
            }

            $rfqSupplier->update([
                'status' => 'responded',
                'quoted_total' => round($total, 2),
                'lead_time_days' => $data['lead_time_days'] ?? null,
                'remarks' => $data['remarks'] ?? null,
                'responded_at' => now(),
            ]);
        });

        return new RfqResource($rfq->fresh()->load(['items.product', 'suppliers.supplier', 'suppliers.quotes']));
    }

    /**
     * Side-by-side vendor comparison: an item × vendor price matrix plus each
     * vendor's total, marking the cheapest quote per line and the lowest total.
     */
    public function comparison(Rfq $rfq)
    {
        $rfq->load(['items.product', 'suppliers.supplier', 'suppliers.quotes']);

        $responded = $rfq->suppliers->where('status', 'responded')->values();

        $rows = $rfq->items->map(function ($item) use ($responded) {
            $cells = $responded->map(function ($rs) use ($item) {
                $quote = $rs->quotes->firstWhere('rfq_item_id', $item->id);
                $unit = $quote ? (float) $quote->unit_price : null;
                $tax = $quote ? (float) $quote->tax_percent : 0;
                $lineTotal = $unit !== null
                    ? round((float) $item->quantity * $unit * (1 + $tax / 100), 2)
                    : null;

                return [
                    'rfq_supplier_id' => $rs->id,
                    'supplier_id' => $rs->supplier_id,
                    'unit_price' => $unit,
                    'tax_percent' => $tax,
                    'line_total' => $lineTotal,
                    'remarks' => $quote?->remarks,
                ];
            });

            $prices = $cells->pluck('unit_price')->filter(fn ($p) => $p !== null);
            $best = $prices->isNotEmpty() ? $prices->min() : null;

            return [
                'rfq_item_id' => $item->id,
                'product' => $item->product?->name,
                'quantity' => (float) $item->quantity,
                'best_unit_price' => $best,
                'cells' => $cells->map(function ($c) use ($best) {
                    $c['is_best'] = $best !== null && $c['unit_price'] !== null
                        && abs($c['unit_price'] - $best) < 1e-6;

                    return $c;
                })->all(),
            ];
        });

        $totals = $responded->map(fn ($rs) => (float) $rs->quoted_total);
        $lowestTotal = $totals->isNotEmpty() ? $totals->min() : null;

        $vendors = $responded->map(fn ($rs) => [
            'rfq_supplier_id' => $rs->id,
            'supplier_id' => $rs->supplier_id,
            'supplier' => $rs->supplier?->name,
            'quoted_total' => (float) $rs->quoted_total,
            'lead_time_days' => $rs->lead_time_days,
            'remarks' => $rs->remarks,
            'is_lowest_total' => $lowestTotal !== null && abs((float) $rs->quoted_total - $lowestTotal) < 1e-6,
        ])->all();

        return response()->json([
            'rfq' => [
                'id' => $rfq->id,
                'rfq_number' => $rfq->rfq_number,
                'status' => $rfq->status,
                'awarded_supplier_id' => $rfq->awarded_supplier_id,
            ],
            'vendors' => $vendors,
            'rows' => $rows,
        ]);
    }

    /** Award the RFQ to one responding vendor and generate a draft PO from their quote. */
    public function award(Request $request, Rfq $rfq)
    {
        if (! in_array($rfq->status, ['sent'], true)) {
            return response()->json(['message' => 'Only a sent RFQ can be awarded.'], 422);
        }

        $data = $request->validate([
            'rfq_supplier_id' => ['required', 'exists:rfq_suppliers,id'],
            'warehouse_id' => ['nullable', 'exists:warehouses,id'],
        ]);

        $rfq->load(['items']);
        /** @var RfqSupplier $rfqSupplier */
        $rfqSupplier = $rfq->suppliers()->with('quotes')->findOrFail($data['rfq_supplier_id']);

        if ($rfqSupplier->status !== 'responded') {
            throw ValidationException::withMessages(['rfq_supplier_id' => ['That vendor has not submitted a quote.']]);
        }

        $warehouseId = $data['warehouse_id'] ?? $rfq->warehouse_id;
        if (! $warehouseId) {
            throw ValidationException::withMessages(['warehouse_id' => ['A warehouse is required to raise the purchase order.']]);
        }

        $po = DB::transaction(function () use ($rfq, $rfqSupplier, $warehouseId, $request) {
            $po = PurchaseOrder::create([
                'po_number' => PurchaseOrder::nextNumber(),
                'supplier_id' => $rfqSupplier->supplier_id,
                'warehouse_id' => $warehouseId,
                'purchase_indent_id' => $rfq->purchase_indent_id,
                'rfq_id' => $rfq->id,
                'status' => 'draft',
                'order_date' => now()->toDateString(),
                'created_by' => $request->user()?->id,
            ]);

            $subtotal = 0;
            $taxTotal = 0;
            foreach ($rfq->items as $item) {
                $quote = $rfqSupplier->quotes->firstWhere('rfq_item_id', $item->id);
                $cost = $quote ? (float) $quote->unit_price : 0;
                $taxPercent = $quote ? (float) $quote->tax_percent : 0;
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

            $rfq->update([
                'status' => 'awarded',
                'awarded_supplier_id' => $rfqSupplier->supplier_id,
                'purchase_order_id' => $po->id,
            ]);

            return $po;
        });

        return (new PurchaseOrderResource($po->load(['supplier', 'warehouse', 'items.product'])))
            ->additional(['rfq_number' => $rfq->rfq_number])
            ->response()->setStatusCode(201);
    }

    public function close(Rfq $rfq)
    {
        if (! in_array($rfq->status, ['draft', 'sent'], true)) {
            return response()->json(['message' => 'This RFQ cannot be closed.'], 422);
        }
        $rfq->update(['status' => 'closed']);

        return new RfqResource($rfq->load(['items.product', 'suppliers.supplier', 'suppliers.quotes']));
    }
}
