<?php

declare(strict_types=1);

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Models\SubscriptionInvoice;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * SaaS billing invoices for the platform panel: browse every subscription
 * invoice and record refunds. Guarded by the `invoices.*` central permissions.
 *
 * A refund here is bookkeeping: it flips the invoice to 'refunded' and stores
 * amount/reason/when. The money itself is returned via the gateway dashboard
 * or bank transfer — there is deliberately no automatic gateway refund call.
 */
class BillingInvoiceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = SubscriptionInvoice::query()->with(['subscription.tenant', 'subscription.plan']);

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($search = trim((string) $request->query('search', ''))) {
            // Tenants live on the landlord connection — resolve ids first,
            // then filter through the (central-connection) subscription.
            $tenantIds = Tenant::query()
                ->where(fn ($q) => $q
                    ->where('company_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%"))
                ->pluck('id');
            $query->whereHas('subscription', fn ($q) => $q->whereIn('tenant_id', $tenantIds));
        }

        $perPage = min((int) $request->query('per_page', 15), 100);
        $page = $query->orderByDesc('created_at')->paginate($perPage);

        return response()->json([
            'data' => collect($page->items())->map(fn (SubscriptionInvoice $i) => [
                'id' => $i->id,
                'subscription_id' => $i->subscription_id,
                'tenant_id' => $i->subscription?->tenant_id,
                'tenant_name' => $i->subscription?->tenant?->company_name,
                'plan_name' => $i->subscription?->plan?->name,
                'amount' => $i->amount,
                'currency' => $i->currency,
                'status' => $i->status,
                'payment_gateway_ref' => $i->payment_gateway_ref,
                'paid_at' => $i->paid_at?->toDateTimeString(),
                'refund_amount' => $i->refund_amount,
                'refund_reason' => $i->refund_reason,
                'refunded_at' => $i->refunded_at?->toDateTimeString(),
                'created_at' => $i->created_at?->toDateTimeString(),
            ])->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    /** Record a full or partial refund against a paid invoice. */
    public function refund(Request $request, SubscriptionInvoice $invoice): JsonResponse
    {
        if ($invoice->status !== 'paid') {
            return response()->json(['message' => 'Only paid invoices can be refunded.'], 422);
        }

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', 'max:'.$invoice->amount],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $invoice->update([
            'status' => 'refunded',
            'refund_amount' => $data['amount'],
            'refund_reason' => $data['reason'],
            'refunded_at' => now(),
        ]);

        $tenantName = $invoice->subscription?->tenant?->company_name ?? '?';

        activity('central-billing')->causedBy($request->user())
            ->withProperties([
                'ip' => $request->ip(),
                'invoice_id' => $invoice->id,
                'amount' => $data['amount'],
                'reason' => $data['reason'],
            ])
            ->event('invoice-refunded')
            ->log("Operator refunded invoice #{$invoice->id} for \"{$tenantName}\"");

        return response()->json([
            'message' => 'Refund recorded. Return the money via your payment gateway or bank.',
            'data' => $invoice->fresh(),
        ]);
    }
}
