<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\SalesOrderResource;
use App\Models\SalesOrder;
use App\Notifications\B2bOrderStatusNotification;
use App\Services\SalesOrderFulfillmentService;
use Illuminate\Http\Request;

/**
 * Staff-side B2B order approval queue (SRS §14). B2B orders are sales orders on
 * the 'b2b' channel; here they are listed, and pending ones are approved
 * (credit-check + reserve stock, then confirmed) or rejected. Approved orders
 * continue through the normal Sales Order fulfilment flow.
 */
class B2bOrderController extends Controller
{
    public function __construct(private readonly SalesOrderFulfillmentService $fulfillment) {}

    public function index(Request $request)
    {
        $query = SalesOrder::query()
            ->where('channel', 'b2b')
            ->with(['customer:id,code,name,company_name', 'warehouse:id,name', 'invoice'])
            ->orderByDesc('id');

        if ($search = $request->query('search')) {
            $query->where('so_number', 'like', "%{$search}%");
        }
        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }
        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->query('customer_id'));
        }

        $perPage = min((int) $request->query('per_page', 15), 100);

        return SalesOrderResource::collection($query->paginate($perPage))
            ->additional(['pending_count' => SalesOrder::query()
                ->where('channel', 'b2b')->where('status', 'pending_approval')->count()]);
    }

    public function show(SalesOrder $salesOrder)
    {
        abort_unless($salesOrder->channel === 'b2b', 404);

        return new SalesOrderResource(
            $salesOrder->load(['customer', 'warehouse', 'items.product', 'invoice'])
        );
    }

    /**
     * Approve a pending B2B order: enforce the customer's credit limit, then
     * reserve available stock and confirm (or backorder) it.
     */
    public function approve(Request $request, SalesOrder $salesOrder)
    {
        abort_unless($salesOrder->channel === 'b2b', 404);

        if ($salesOrder->status !== 'pending_approval') {
            return response()->json(['message' => 'Only orders awaiting approval can be approved.'], 422);
        }

        if ($error = $this->fulfillment->creditLimitError($salesOrder)) {
            return response()->json(['message' => $error], 422);
        }

        $this->fulfillment->reserveAndConfirm($salesOrder);
        $salesOrder->update([
            'approved_by' => $request->user()?->id,
            'approved_at' => now(),
        ]);

        $this->notifyCustomer($salesOrder, 'approved');

        return new SalesOrderResource($salesOrder->fresh()->load(['customer', 'warehouse', 'items.product']));
    }

    /** Reject a pending B2B order with a reason. */
    public function reject(Request $request, SalesOrder $salesOrder)
    {
        abort_unless($salesOrder->channel === 'b2b', 404);

        if ($salesOrder->status !== 'pending_approval') {
            return response()->json(['message' => 'Only orders awaiting approval can be rejected.'], 422);
        }

        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $salesOrder->update([
            'status' => 'cancelled',
            'rejected_reason' => $data['reason'] ?? null,
            'approved_by' => $request->user()?->id,
            'approved_at' => now(),
        ]);

        $this->notifyCustomer($salesOrder, 'rejected');

        return new SalesOrderResource($salesOrder->fresh()->load(['customer', 'warehouse', 'items.product']));
    }

    /** Notify the customer of the approval decision (database + mail). */
    private function notifyCustomer(SalesOrder $salesOrder, string $decision): void
    {
        $customer = $salesOrder->customer;
        if ($customer) {
            $customer->notify(new B2bOrderStatusNotification($salesOrder, $decision));
        }
    }
}
