<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PurchaseInvoiceRequest;
use App\Http\Resources\PurchaseInvoiceResource;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseItem;
use App\Models\PurchaseOrder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Vendor invoice: the third leg of 3-way matching (PO vs GRN vs Invoice).
 * No stock is posted here — that already happened at GRN time. An invoice
 * with lines outside tolerance is flagged as an "exception" but can still
 * be approved for payment (soft warning, override allowed).
 */
class PurchaseInvoiceController extends Controller
{
    public function index(Request $request)
    {
        $query = PurchaseInvoice::query()
            ->with(['purchaseOrder:id,po_number', 'supplier:id,name']);

        if ($search = $request->query('search')) {
            $query->where('invoice_number', 'like', "%{$search}%");
        }
        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }
        if ($request->filled('match_status')) {
            $query->where('match_status', $request->query('match_status'));
        }
        if ($request->filled('supplier_id')) {
            $query->where('supplier_id', $request->query('supplier_id'));
        }

        $query->orderByDesc('id');
        $perPage = min((int) $request->query('per_page', 15), 100);

        return PurchaseInvoiceResource::collection($query->paginate($perPage));
    }

    public function store(PurchaseInvoiceRequest $request)
    {
        $data = $request->validated();

        /** @var PurchaseOrder $po */
        $po = PurchaseOrder::with('items')->findOrFail($data['purchase_order_id']);

        $lines = $this->resolveLines($po, $data['items']);

        $invoice = DB::transaction(function () use ($po, $data, $lines, $request) {
            $invoice = PurchaseInvoice::create([
                'invoice_number' => $data['invoice_number'],
                'purchase_order_id' => $po->id,
                'supplier_id' => $po->supplier_id,
                'invoice_date' => $data['invoice_date'],
                'due_date' => $data['due_date'] ?? null,
                'status' => 'pending',
                'notes' => $data['notes'] ?? null,
                'created_by' => $request->user()?->id,
            ]);

            $this->syncItems($invoice, $lines);

            return $invoice;
        });

        return (new PurchaseInvoiceResource($invoice->load(['purchaseOrder', 'supplier', 'items.product', 'items.purchaseItem'])))
            ->response()->setStatusCode(201);
    }

    public function show(PurchaseInvoice $purchaseInvoice)
    {
        return new PurchaseInvoiceResource(
            $purchaseInvoice->load(['purchaseOrder', 'supplier', 'items.product', 'items.purchaseItem'])
        );
    }

    public function update(PurchaseInvoiceRequest $request, PurchaseInvoice $purchaseInvoice)
    {
        if ($purchaseInvoice->status !== 'pending') {
            return response()->json(['message' => 'Only pending invoices can be edited.'], 422);
        }

        $data = $request->validated();

        /** @var PurchaseOrder $po */
        $po = PurchaseOrder::with('items')->findOrFail($data['purchase_order_id']);
        $lines = $this->resolveLines($po, $data['items']);

        DB::transaction(function () use ($request, $purchaseInvoice, $po, $data, $lines) {
            $purchaseInvoice->update([
                'invoice_number' => $data['invoice_number'],
                'purchase_order_id' => $po->id,
                'supplier_id' => $po->supplier_id,
                'invoice_date' => $data['invoice_date'],
                'due_date' => $data['due_date'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);
            $purchaseInvoice->items()->delete();
            $this->syncItems($purchaseInvoice, $lines);
        });

        return new PurchaseInvoiceResource($purchaseInvoice->load(['purchaseOrder', 'supplier', 'items.product', 'items.purchaseItem']));
    }

    public function destroy(PurchaseInvoice $purchaseInvoice)
    {
        if ($purchaseInvoice->status !== 'pending') {
            return response()->json(['message' => 'Only pending invoices can be deleted.'], 422);
        }
        $purchaseInvoice->delete();

        return response()->json(['message' => 'Invoice deleted.']);
    }

    /** Approve for payment — allowed regardless of match_status (soft override). */
    public function approve(Request $request, PurchaseInvoice $purchaseInvoice)
    {
        if ($purchaseInvoice->status !== 'pending') {
            return response()->json(['message' => 'Only pending invoices can be approved.'], 422);
        }
        $purchaseInvoice->update([
            'status' => 'approved',
            'approved_by' => $request->user()?->id,
            'approved_at' => now(),
        ]);

        return new PurchaseInvoiceResource($purchaseInvoice->load(['purchaseOrder', 'supplier', 'items.product', 'items.purchaseItem']));
    }

    public function cancel(PurchaseInvoice $purchaseInvoice)
    {
        if ($purchaseInvoice->status !== 'pending') {
            return response()->json(['message' => 'Only pending invoices can be cancelled.'], 422);
        }
        $purchaseInvoice->update(['status' => 'cancelled']);

        return new PurchaseInvoiceResource($purchaseInvoice->load(['purchaseOrder', 'supplier', 'items.product', 'items.purchaseItem']));
    }

    /** Validate each line's purchase_item_id belongs to the given PO. */
    private function resolveLines(PurchaseOrder $po, array $items): array
    {
        $lines = [];
        foreach ($items as $item) {
            /** @var PurchaseItem|null $poItem */
            $poItem = $po->items->firstWhere('id', (int) $item['purchase_item_id']);
            if (! $poItem) {
                throw ValidationException::withMessages([
                    'items' => ["Purchase item {$item['purchase_item_id']} does not belong to this order."],
                ]);
            }
            $lines[] = [$poItem, $item];
        }

        return $lines;
    }

    /** Create line items, compute totals, and set the overall match_status. */
    private function syncItems(PurchaseInvoice $invoice, array $lines): void
    {
        $subtotal = 0;
        $taxTotal = 0;
        $hasException = false;

        foreach ($lines as [$poItem, $item]) {
            $qty = (float) $item['quantity'];
            $cost = (float) $item['unit_cost'];
            $taxPercent = (float) ($item['tax_percent'] ?? 0);
            $lineBase = $qty * $cost;
            $lineTax = round($lineBase * $taxPercent / 100, 2);

            $invoiceItem = $invoice->items()->create([
                'purchase_item_id' => $poItem->id,
                'product_id' => $poItem->product_id,
                'variant_id' => $poItem->variant_id,
                'quantity' => $qty,
                'unit_cost' => $cost,
                'tax_percent' => $taxPercent,
                'tax_amount' => $lineTax,
                'line_total' => round($lineBase + $lineTax, 2),
            ]);
            $invoiceItem->setRelation('purchaseItem', $poItem);

            if (! $invoiceItem->variance()['within_tolerance']) {
                $hasException = true;
            }

            $subtotal += $lineBase;
            $taxTotal += $lineTax;
        }

        $invoice->update([
            'subtotal' => round($subtotal, 2),
            'tax_amount' => round($taxTotal, 2),
            'total' => round($subtotal + $taxTotal, 2),
            'match_status' => $hasException ? 'exception' : 'matched',
        ]);
    }
}
