<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\RendersDocumentPdf;
use App\Http\Controllers\Controller;
use App\Models\Batch;
use App\Models\SerialNumber;
use App\Models\StockReturn;
use App\Models\StockReturnItem;
use App\Services\InventoryTrackingService;
use App\Services\StockService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Returns Inward: stock coming back into a warehouse (customer sales
 * return, or any other inward return) that isn't tied to a formal
 * Sales/Purchase Return workflow yet.
 *
 * Lifecycle mirrors Stock Adjustments: draft -> approve posts every line
 * to the immutable ledger (type 'return_in'); draft rows can still be
 * edited, cancelled, or deleted.
 */
class StockReturnController extends Controller
{
    use RendersDocumentPdf;

    public function __construct(
        private readonly StockService $stock,
        private readonly InventoryTrackingService $tracking,
    ) {}

    public function index(Request $request)
    {
        $query = StockReturn::query()
            ->with(['warehouse:id,name', 'customer:id,name', 'supplier:id,name,email'])
            ->withCount('items')
            // Goods value of the lines, for the debit-note claim amount. A
            // subquery rather than an eager load: the list needs one number per
            // row, not every line of every return on the page.
            ->addSelect(['line_value' => StockReturnItem::query()
                ->selectRaw('SUM(quantity * unit_cost)')
                ->whereColumn('stock_return_id', 'stock_returns.id'),
            ]);

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('return_number', 'like', "%{$search}%")
                    ->orWhere('debit_note_number', 'like', "%{$search}%");
            });
        }
        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }
        if ($request->filled('return_type')) {
            $query->where('return_type', $request->query('return_type'));
        }
        if ($request->filled('warehouse_id')) {
            $query->where('warehouse_id', $request->query('warehouse_id'));
        }
        // The debit-note book read as its own list: only the returns that became
        // a claim on a supplier.
        if ($request->boolean('debit_notes_only')) {
            $query->whereNotNull('debit_note_issued_at');
        }

        $query->orderByDesc('id');
        $perPage = min((int) $request->query('per_page', 15), 100);
        $page = $query->paginate($perPage);

        return response()->json([
            'data' => $page->getCollection()->map(fn ($r) => $this->payload($r)),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        $return = DB::transaction(function () use ($request, $data) {
            $return = StockReturn::create([
                'return_number' => StockReturn::nextNumber(),
                'warehouse_id' => $data['warehouse_id'],
                'return_type' => $data['return_type'],
                'customer_id' => $data['customer_id'] ?? null,
                'supplier_id' => $data['supplier_id'] ?? null,
                'goods_receipt_id' => $data['goods_receipt_id'] ?? null,
                'debit_note_number' => $data['debit_note_number'] ?? null,
                'status' => 'draft',
                'return_date' => $data['return_date'],
                'notes' => $data['notes'] ?? null,
                'created_by' => $request->user()?->id,
            ]);
            $this->syncItems($return, $data['items']);

            return $return;
        });

        return response()->json(['data' => $this->payload($return->load('items.product', 'warehouse', 'customer', 'supplier'))], 201);
    }

    public function show(StockReturn $stockReturn)
    {
        return response()->json(['data' => $this->payload($stockReturn->load('items.product', 'warehouse', 'customer', 'supplier'))]);
    }

    public function update(Request $request, StockReturn $stockReturn)
    {
        if ($stockReturn->status !== 'draft') {
            return response()->json(['message' => 'Only draft returns can be edited.'], 422);
        }
        $data = $this->validated($request);

        DB::transaction(function () use ($stockReturn, $data) {
            $stockReturn->update([
                'warehouse_id' => $data['warehouse_id'],
                'return_type' => $data['return_type'],
                'customer_id' => $data['customer_id'] ?? null,
                'supplier_id' => $data['supplier_id'] ?? null,
                'goods_receipt_id' => $data['goods_receipt_id'] ?? null,
                'debit_note_number' => $data['debit_note_number'] ?? null,
                'return_date' => $data['return_date'],
                'notes' => $data['notes'] ?? null,
            ]);
            $stockReturn->items()->delete();
            $this->syncItems($stockReturn, $data['items']);
        });

        return response()->json(['data' => $this->payload($stockReturn->load('items.product', 'warehouse', 'customer', 'supplier'))]);
    }

    public function destroy(StockReturn $stockReturn)
    {
        if ($stockReturn->status !== 'draft') {
            return response()->json(['message' => 'Only draft returns can be deleted.'], 422);
        }
        $stockReturn->delete();

        return response()->json(['message' => 'Return deleted.']);
    }

    /** Approve: post every line inward to the immutable ledger. All-or-nothing. */
    public function approve(Request $request, StockReturn $stockReturn)
    {
        if ($stockReturn->status !== 'draft') {
            return response()->json(['message' => 'Only draft returns can be approved.'], 422);
        }

        $stockReturn->load('items.product');

        $isVendorReturn = $stockReturn->return_type === 'vendor_return';

        try {
            DB::transaction(function () use ($stockReturn, $request, $isVendorReturn) {
                foreach ($stockReturn->items as $item) {
                    if ($isVendorReturn) {
                        $this->stock->outward(
                            warehouseId: $stockReturn->warehouse_id,
                            productId: $item->product_id,
                            variantId: $item->variant_id,
                            quantity: (float) $item->quantity,
                            type: 'return_out',
                            referenceType: StockReturn::class,
                            referenceId: $stockReturn->id,
                            referenceNumber: $stockReturn->return_number,
                            userId: $request->user()?->id,
                        );

                        if ($item->product?->track_batch) {
                            $item->update(['batch_allocations' => $this->tracking->consumeBatchFefo(
                                $item->product_id, $item->variant_id, $stockReturn->warehouse_id, (float) $item->quantity,
                            )]);
                        }
                        if ($item->product?->track_serial) {
                            $this->tracking->consumeSerials(
                                $item->product_id, $item->variant_id, $stockReturn->warehouse_id,
                                $item->serials ?? [], 'returned', StockReturn::class, $stockReturn->id,
                            );
                        }
                    } else {
                        $this->stock->inward(
                            warehouseId: $stockReturn->warehouse_id,
                            productId: $item->product_id,
                            variantId: $item->variant_id,
                            quantity: (float) $item->quantity,
                            unitCost: (float) $item->unit_cost,
                            type: 'return_in',
                            referenceType: StockReturn::class,
                            referenceId: $stockReturn->id,
                            referenceNumber: $stockReturn->return_number,
                            userId: $request->user()?->id,
                        );

                        if ($item->product?->track_batch && $item->batch_number) {
                            $batch = Batch::firstOrCreate(
                                [
                                    'product_id' => $item->product_id,
                                    'variant_id' => $item->variant_id,
                                    'warehouse_id' => $stockReturn->warehouse_id,
                                    'batch_number' => $item->batch_number,
                                ],
                                ['expiry_date' => $item->expiry_date, 'unit_cost' => (float) $item->unit_cost],
                            );
                            $batch->increment('quantity', (float) $item->quantity);
                        }

                        if ($item->product?->track_serial) {
                            foreach ($item->serials ?? [] as $serial) {
                                $existing = SerialNumber::where('product_id', $item->product_id)
                                    ->where('serial', $serial)->first();
                                if ($existing) {
                                    $existing->update([
                                        'warehouse_id' => $stockReturn->warehouse_id,
                                        'status' => 'in_stock',
                                        'reference_type' => StockReturn::class,
                                        'reference_id' => $stockReturn->id,
                                    ]);
                                } else {
                                    SerialNumber::create([
                                        'product_id' => $item->product_id,
                                        'variant_id' => $item->variant_id,
                                        'warehouse_id' => $stockReturn->warehouse_id,
                                        'serial' => $serial,
                                        'status' => 'in_stock',
                                        'reference_type' => StockReturn::class,
                                        'reference_id' => $stockReturn->id,
                                    ]);
                                }
                            }
                        }
                    }
                }

                $stockReturn->update([
                    'status' => 'approved',
                    'approved_by' => $request->user()?->id,
                    'approved_at' => now(),
                ]);
            });
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['items' => [$e->getMessage()]]);
        }

        return response()->json(['data' => $this->payload($stockReturn->fresh()->load('items.product', 'warehouse', 'customer', 'supplier'))]);
    }

    public function cancel(StockReturn $stockReturn)
    {
        if ($stockReturn->status !== 'draft') {
            return response()->json(['message' => 'Only draft returns can be cancelled.'], 422);
        }
        $stockReturn->update(['status' => 'cancelled']);

        return response()->json(['data' => $this->payload($stockReturn->load('items.product', 'warehouse', 'customer', 'supplier'))]);
    }

    /**
     * Raise the purchase debit note for an approved vendor return (retail base
     * §3). Mints the number, stamps who raised it, and records the rate the
     * claim is made at — nothing else moves, because the stock already went out
     * on approval and the money is settled against the supplier's account.
     *
     * Idempotent by refusal rather than by silence: a second attempt is an
     * error, not a no-op, because the caller almost certainly means to correct
     * something and hiding that would leave them believing they had.
     */
    public function issueDebitNote(Request $request, StockReturn $stockReturn)
    {
        if ($stockReturn->debit_note_issued_at !== null) {
            return response()->json([
                'message' => "Debit note {$stockReturn->debit_note_number} was already raised on this return.",
            ], 422);
        }
        if ($stockReturn->return_type !== 'vendor_return') {
            return response()->json(['message' => 'A debit note can only be raised on a return going back to a supplier.'], 422);
        }
        if ($stockReturn->status !== 'approved') {
            return response()->json(['message' => 'Approve the return first — a debit note claims credit for goods that have actually gone back.'], 422);
        }

        $data = $request->validate([
            'debit_note_date' => ['nullable', 'date'],
            'debit_note_tax_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            // Lets a shop key the vendor's own reference when it has one, rather
            // than minting a series number the supplier will not recognise.
            'debit_note_number' => ['nullable', 'string', 'max:100'],
        ]);

        /**
         * Precedence: what this request says, then whatever was keyed on the
         * return itself (some shops file under the vendor's own reference and
         * type it in when the goods are booked out), then the shop's own series.
         * Minting over a reference somebody had already entered would send the
         * supplier a number neither party has on file.
         */
        $number = $data['debit_note_number']
            ?: ($stockReturn->debit_note_number ?: StockReturn::nextDebitNoteNumber());

        $stockReturn->update([
            'debit_note_number' => $number,
            'debit_note_date' => $data['debit_note_date'] ?? now()->toDateString(),
            'debit_note_tax_percent' => $data['debit_note_tax_percent'] ?? 0,
            'debit_note_issued_at' => now(),
            'debit_note_issued_by' => $request->user()?->id,
        ]);

        return response()->json([
            'message' => "Debit note {$number} raised.",
            'data' => $this->payload($stockReturn->fresh()->load('items.product', 'warehouse', 'customer', 'supplier')),
        ]);
    }

    /** The printable debit note — what actually goes to the supplier. */
    public function debitNotePdf(Request $request, StockReturn $stockReturn)
    {
        if ($stockReturn->debit_note_issued_at === null) {
            return response()->json(['message' => 'No debit note has been raised on this return yet.'], 422);
        }

        $stockReturn->load([
            'supplier', 'warehouse:id,name', 'goodsReceipt:id,grn_number',
            'items.product:id,name,sku,hsn_code', 'items.variant:id,name,sku',
        ]);

        return $this->renderDocument(
            $request,
            'documents.debit-note',
            ['return' => $stockReturn],
            "debit-note-{$stockReturn->debit_note_number}.pdf",
        );
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'warehouse_id' => ['required', 'exists:warehouses,id'],
            'return_type' => ['required', Rule::in(['customer_return', 'vendor_return', 'other'])],
            'customer_id' => ['nullable', 'required_if:return_type,customer_return', 'exists:customers,id'],
            'supplier_id' => ['nullable', 'required_if:return_type,vendor_return', 'exists:suppliers,id'],
            'goods_receipt_id' => ['nullable', 'exists:goods_receipts,id'],
            'debit_note_number' => ['nullable', 'string', 'max:100'],
            'return_date' => ['required', 'date'],
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'exists:products,id'],
            'items.*.variant_id' => ['nullable', 'exists:product_variants,id'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
            'items.*.reason' => ['nullable', 'string', 'max:255'],
            'items.*.batch_number' => ['nullable', 'string', 'max:100'],
            'items.*.expiry_date' => ['nullable', 'date'],
            'items.*.serials' => ['nullable', 'array'],
            'items.*.serials.*' => ['string', 'max:100'],
        ]);
    }

    private function syncItems(StockReturn $return, array $items): void
    {
        foreach ($items as $item) {
            $return->items()->create([
                'product_id' => $item['product_id'],
                'variant_id' => $item['variant_id'] ?? null,
                'quantity' => $item['quantity'],
                'unit_cost' => $item['unit_cost'] ?? 0,
                'reason' => $item['reason'] ?? null,
                'batch_number' => $item['batch_number'] ?? null,
                'expiry_date' => $item['expiry_date'] ?? null,
                'serials' => $item['serials'] ?? null,
            ]);
        }
    }

    private function payload(StockReturn $r): array
    {
        return [
            'id' => $r->id,
            'return_number' => $r->return_number,
            'warehouse_id' => $r->warehouse_id,
            'warehouse' => $r->relationLoaded('warehouse') && $r->warehouse
                ? ['id' => $r->warehouse->id, 'name' => $r->warehouse->name] : null,
            'return_type' => $r->return_type,
            'customer_id' => $r->customer_id,
            'customer' => $r->relationLoaded('customer') && $r->customer
                ? ['id' => $r->customer->id, 'name' => $r->customer->name] : null,
            'supplier_id' => $r->supplier_id,
            'supplier' => $r->relationLoaded('supplier') && $r->supplier
                // The email is carried so the debit-note send dialog opens
                // pre-filled; without it the counter retypes an address the
                // record already holds.
                ? ['id' => $r->supplier->id, 'name' => $r->supplier->name, 'email' => $r->supplier->email] : null,
            'goods_receipt_id' => $r->goods_receipt_id,
            'debit_note_number' => $r->debit_note_number,
            'debit_note_date' => $r->debit_note_date?->toDateString(),
            'debit_note_tax_percent' => $r->debit_note_tax_percent,
            'debit_note_issued_at' => $r->debit_note_issued_at,
            /**
             * What the note is worth. The list gets it from the `line_value`
             * subquery (index() below) so a Debit Notes table can show the claim
             * without loading every line; the detail endpoints derive it from the
             * lines they already hold.
             */
            'debit_note_total' => $r->relationLoaded('items')
                ? $r->debitNoteTotal()
                : ($r->line_value === null
                    ? null
                    : round((float) $r->line_value * (1 + (float) $r->debit_note_tax_percent / 100), 2)),
            'can_issue_debit_note' => $r->canIssueDebitNote(),
            'status' => $r->status,
            'return_date' => $r->return_date?->toDateString(),
            'notes' => $r->notes,
            'items_count' => $r->items_count ?? ($r->relationLoaded('items') ? $r->items->count() : null),
            'items' => $r->relationLoaded('items') ? $r->items->map(fn ($i) => [
                'id' => $i->id,
                'product_id' => $i->product_id,
                'product' => $i->relationLoaded('product') && $i->product
                    ? ['id' => $i->product->id, 'sku' => $i->product->sku, 'name' => $i->product->name] : null,
                'variant_id' => $i->variant_id,
                'quantity' => $i->quantity,
                'unit_cost' => $i->unit_cost,
                'reason' => $i->reason,
            ]) : null,
            'approved_at' => $r->approved_at,
            'created_at' => $r->created_at,
        ];
    }
}
