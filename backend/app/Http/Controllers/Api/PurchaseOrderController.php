<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PurchaseOrderRequest;
use App\Http\Resources\PurchaseOrderResource;
use App\Models\PurchaseOrder;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\PoApprovalPendingNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

class PurchaseOrderController extends Controller
{
    public function index(Request $request)
    {
        $query = PurchaseOrder::query()
            ->with(['supplier', 'warehouse'])
            ->withCount('goodsReceipts');

        if ($search = $request->query('search')) {
            $query->where('po_number', 'like', "%{$search}%");
        }
        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }
        if ($request->filled('supplier_id')) {
            $query->where('supplier_id', $request->query('supplier_id'));
        }

        $query->orderByDesc('id');
        $perPage = min((int) $request->query('per_page', 15), 100);

        return PurchaseOrderResource::collection($query->paginate($perPage));
    }

    public function store(PurchaseOrderRequest $request)
    {
        $po = DB::transaction(function () use ($request) {
            $data = $request->validated();

            $po = PurchaseOrder::create([
                'po_number' => PurchaseOrder::nextNumber(),
                'supplier_id' => $data['supplier_id'],
                'warehouse_id' => $data['warehouse_id'],
                'status' => 'draft',
                'order_date' => $data['order_date'],
                'expected_date' => $data['expected_date'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $request->user()?->id,
            ]);

            $this->syncItems($po, $data['items']);

            return $po;
        });

        return (new PurchaseOrderResource($po->load(['supplier', 'warehouse', 'items.product'])))
            ->response()->setStatusCode(201);
    }

    public function show(PurchaseOrder $purchaseOrder)
    {
        return new PurchaseOrderResource(
            $purchaseOrder->load(['supplier', 'warehouse', 'items.product', 'goodsReceipts'])
        );
    }

    public function update(PurchaseOrderRequest $request, PurchaseOrder $purchaseOrder)
    {
        if (! in_array($purchaseOrder->status, ['draft', 'pending_approval'], true)) {
            return response()->json(['message' => 'Only draft or pending orders can be edited.'], 422);
        }

        DB::transaction(function () use ($request, $purchaseOrder) {
            $data = $request->validated();
            $purchaseOrder->update([
                'supplier_id' => $data['supplier_id'],
                'warehouse_id' => $data['warehouse_id'],
                'order_date' => $data['order_date'],
                'expected_date' => $data['expected_date'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);
            $purchaseOrder->items()->delete();
            $this->syncItems($purchaseOrder, $data['items']);
        });

        return new PurchaseOrderResource($purchaseOrder->load(['supplier', 'warehouse', 'items.product']));
    }

    public function destroy(PurchaseOrder $purchaseOrder)
    {
        if ($purchaseOrder->status !== 'draft') {
            return response()->json(['message' => 'Only draft orders can be deleted.'], 422);
        }
        $purchaseOrder->delete();

        return response()->json(['message' => 'Purchase order deleted.']);
    }

    /** Move a draft into the approval queue. */
    public function submit(PurchaseOrder $purchaseOrder)
    {
        if ($purchaseOrder->status !== 'draft') {
            return response()->json(['message' => 'Only draft orders can be submitted.'], 422);
        }
        $purchaseOrder->update(['status' => 'pending_approval']);

        return new PurchaseOrderResource($purchaseOrder->load(['supplier', 'warehouse', 'items.product']));
    }

    /**
     * Approve a pending order (guarded by purchases.approve).
     *
     * Orders at or below the configured approval threshold finalize on the
     * first approval, as before. Orders above it need a second approval
     * from an admin/super-admin, distinct from whoever gave the first.
     */
    public function approve(Request $request, PurchaseOrder $purchaseOrder)
    {
        if (! in_array($purchaseOrder->status, ['draft', 'pending_approval'], true)) {
            return response()->json(['message' => 'This order cannot be approved.'], 422);
        }

        $threshold = (float) Setting::get('purchase_approval_threshold', '0');
        $needsSecond = $threshold > 0 && (float) $purchaseOrder->total > $threshold;
        $user = $request->user();

        if ($needsSecond && ! $purchaseOrder->first_approved_by) {
            $purchaseOrder->update([
                'status' => 'pending_approval',
                'requires_second_approval' => true,
                'first_approved_by' => $user?->id,
                'first_approved_at' => now(),
            ]);

            $finalApprovers = User::role(['admin', 'super-admin'])->when($user, fn ($q) => $q->where('id', '!=', $user->id))->get();
            if ($finalApprovers->isNotEmpty()) {
                Notification::send($finalApprovers, new PoApprovalPendingNotification($purchaseOrder));
            }

            return new PurchaseOrderResource($purchaseOrder->load(['supplier', 'warehouse', 'items.product']));
        }

        if ($needsSecond) {
            if ((int) $purchaseOrder->first_approved_by === (int) $user?->id) {
                throw ValidationException::withMessages([
                    'approval' => ['The final approval must come from a different user than the first approval.'],
                ]);
            }
            if (! $user?->hasAnyRole(['admin', 'super-admin'])) {
                throw ValidationException::withMessages([
                    'approval' => ['This order exceeds the approval threshold and requires a final approval from an admin.'],
                ]);
            }
        }

        $purchaseOrder->update([
            'status' => 'approved',
            'approved_by' => $user?->id,
            'approved_at' => now(),
        ]);

        return new PurchaseOrderResource($purchaseOrder->load(['supplier', 'warehouse', 'items.product']));
    }

    public function cancel(PurchaseOrder $purchaseOrder)
    {
        if (in_array($purchaseOrder->status, ['received', 'cancelled'], true)) {
            return response()->json(['message' => 'This order cannot be cancelled.'], 422);
        }
        $purchaseOrder->update(['status' => 'cancelled']);

        return new PurchaseOrderResource($purchaseOrder->load(['supplier', 'warehouse', 'items.product']));
    }

    /** Create line items from request payload and recompute PO totals. */
    private function syncItems(PurchaseOrder $po, array $items): void
    {
        $subtotal = 0;
        $taxTotal = 0;

        foreach ($items as $item) {
            $qty = (float) $item['quantity'];
            $cost = (float) $item['unit_cost'];
            $taxPercent = (float) ($item['tax_percent'] ?? 0);
            $lineBase = $qty * $cost;
            $lineTax = round($lineBase * $taxPercent / 100, 2);

            $po->items()->create([
                'product_id' => $item['product_id'],
                'variant_id' => $item['variant_id'] ?? null,
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
    }
}
