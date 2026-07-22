<?php

declare(strict_types=1);

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Services\RazorpayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Subscription administration for the SaaS-owner platform panel: browse every
 * tenant's subscription, change plan / extend periods, and cancel. Guarded by
 * the `subscriptions.*` central permissions.
 *
 * Normal lifecycle transitions (payment captured, halted, …) arrive via the
 * Razorpay webhook; these endpoints are the manual override for support and
 * offline/custom-plan arrangements.
 */
class BillingSubscriptionController extends Controller
{
    /** subscription status → tenant status, kept in sync on manual edits. */
    private const TENANT_STATUS = [
        'trialing' => 'trial',
        'active' => 'active',
        'past_due' => 'active', // grace period: workspace stays usable
        'suspended' => 'suspended',
        'cancelled' => 'suspended',
    ];

    public function index(Request $request): JsonResponse
    {
        $query = Subscription::query()->with(['tenant', 'plan']);

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($planId = $request->query('plan_id')) {
            $query->where('plan_id', $planId);
        }
        if ($search = trim((string) $request->query('search', ''))) {
            // Tenant lives on the landlord connection, Subscription on the
            // central one — resolve matching tenant ids first rather than
            // relying on a cross-connection whereHas subquery.
            $tenantIds = Tenant::query()
                ->where(fn ($q) => $q
                    ->where('company_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%"))
                ->pluck('id');
            $query->whereIn('tenant_id', $tenantIds);
        }

        $perPage = min((int) $request->query('per_page', 15), 100);
        $page = $query->orderByDesc('created_at')->paginate($perPage);

        return response()->json([
            'data' => collect($page->items())->map(fn (Subscription $s) => $this->payload($s))->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function show(Subscription $subscription): JsonResponse
    {
        $subscription->load(['tenant', 'plan', 'invoices' => fn ($q) => $q->orderByDesc('created_at')]);

        return response()->json(['data' => $this->payload($subscription, withInvoices: true)]);
    }

    /**
     * Manual adjustments: switch plan, move trial/period dates, or force a
     * non-cancelled status (support fixing webhook drift, offline renewals).
     */
    public function update(Request $request, Subscription $subscription): JsonResponse
    {
        $data = $request->validate([
            'plan_id' => ['sometimes', 'integer', Rule::exists('plans', 'id')],
            'status' => ['sometimes', Rule::in(['trialing', 'active', 'past_due', 'suspended'])],
            'trial_ends_at' => ['sometimes', 'nullable', 'date'],
            'current_period_start' => ['sometimes', 'nullable', 'date'],
            'current_period_end' => ['sometimes', 'nullable', 'date'],
        ]);

        $changes = [];
        if (array_key_exists('plan_id', $data) && $data['plan_id'] !== $subscription->plan_id) {
            $changes['plan'] = [$subscription->plan?->name, Plan::find($data['plan_id'])?->name];
        }
        if (array_key_exists('status', $data) && $data['status'] !== $subscription->status) {
            $changes['status'] = [$subscription->status, $data['status']];
        }

        $subscription->fill($data);
        if (array_key_exists('status', $data)) {
            $subscription->cancelled_at = null; // leaving a cancelled state clears the marker
        }
        $subscription->save();

        if (isset($changes['status'])) {
            $subscription->tenant?->update(['status' => self::TENANT_STATUS[$data['status']]]);
        }

        activity('central-billing')->causedBy($request->user())
            ->withProperties(['ip' => $request->ip(), 'subscription_id' => $subscription->id, 'changes' => $changes])
            ->event('subscription-updated')
            ->log('Operator updated subscription for "'.($subscription->tenant?->company_name ?? '?').'"');

        $subscription->load(['tenant', 'plan']);

        return response()->json(['data' => $this->payload($subscription)]);
    }

    /**
     * Cancel a subscription. `at_cycle_end` (default true) lets the tenant run
     * out the paid period — the daily subscriptions:process sweep suspends the
     * workspace once it lapses. Immediate cancellation suspends right away.
     */
    public function cancel(Request $request, RazorpayService $razorpay, Subscription $subscription): JsonResponse
    {
        if ($subscription->status === 'cancelled') {
            return response()->json(['message' => 'Subscription is already cancelled.'], 422);
        }

        $data = $request->validate([
            'at_cycle_end' => ['sometimes', 'boolean'],
        ]);
        $atCycleEnd = (bool) ($data['at_cycle_end'] ?? true);

        $warning = null;
        if ($subscription->razorpay_subscription_id && $razorpay->configured()) {
            try {
                $razorpay->cancelSubscription($subscription->razorpay_subscription_id, $atCycleEnd);
            } catch (Throwable $e) {
                report($e);
                $warning = 'Razorpay cancellation failed — cancel it in the Razorpay dashboard too.';
            }
        }

        $subscription->cancelled_at = now();
        if (! $atCycleEnd) {
            $subscription->status = 'cancelled';
            $subscription->tenant?->update(['status' => 'suspended']);
        }
        $subscription->save();

        activity('central-billing')->causedBy($request->user())
            ->withProperties(['ip' => $request->ip(), 'subscription_id' => $subscription->id, 'at_cycle_end' => $atCycleEnd])
            ->event('subscription-cancelled')
            ->log('Operator cancelled subscription for "'.($subscription->tenant?->company_name ?? '?').'"');

        $subscription->load(['tenant', 'plan']);

        return response()->json([
            'message' => $atCycleEnd
                ? 'Subscription will end at the close of the current period.'
                : 'Subscription cancelled and workspace suspended.',
            'warning' => $warning,
            'data' => $this->payload($subscription),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Subscription $s, bool $withInvoices = false): array
    {
        $row = [
            'id' => $s->id,
            'tenant_id' => $s->tenant_id,
            'tenant_name' => $s->tenant?->company_name,
            'tenant_email' => $s->tenant?->email,
            'tenant_status' => $s->tenant?->status,
            'plan_id' => $s->plan_id,
            'plan_name' => $s->plan?->name,
            'plan_price' => $s->plan?->price,
            'billing_cycle' => $s->plan?->billing_cycle,
            'currency' => $s->plan?->currency ?? 'INR',
            'status' => $s->status,
            'trial_ends_at' => $s->trial_ends_at?->toDateTimeString(),
            'current_period_start' => $s->current_period_start?->toDateTimeString(),
            'current_period_end' => $s->current_period_end?->toDateTimeString(),
            'cancelled_at' => $s->cancelled_at?->toDateTimeString(),
            'razorpay_subscription_id' => $s->razorpay_subscription_id,
            'created_at' => $s->created_at?->toDateTimeString(),
        ];

        if ($withInvoices) {
            $row['invoices'] = $s->invoices->map(fn ($i) => [
                'id' => $i->id,
                'amount' => $i->amount,
                'currency' => $i->currency,
                'status' => $i->status,
                'payment_gateway_ref' => $i->payment_gateway_ref,
                'paid_at' => $i->paid_at?->toDateTimeString(),
                'refund_amount' => $i->refund_amount,
                'refund_reason' => $i->refund_reason,
                'refunded_at' => $i->refunded_at?->toDateTimeString(),
                'created_at' => $i->created_at?->toDateTimeString(),
            ])->values();
        }

        return $row;
    }
}
