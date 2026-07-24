<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\Tenant;
use App\Services\RazorpayService;
use App\Support\TenantContext;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use RuntimeException;
use Throwable;

/**
 * Tenant-facing billing: view the current subscription, browse plans, and
 * upgrade/downgrade. Subscription rows live in the central DB and are resolved
 * via the active tenant id.
 */
class SubscriptionController extends Controller
{
    public function __construct(private RazorpayService $razorpay) {}

    private function current(): ?Subscription
    {
        return Subscription::with('plan')
            ->where('tenant_id', app(TenantContext::class)->id())
            ->latest('id')
            ->first();
    }

    public function show(): JsonResponse
    {
        $sub = $this->current();

        if (! $sub) {
            return response()->json(['data' => null]);
        }

        return response()->json([
            'data' => [
                'status' => $sub->status,
                'plan' => [
                    'slug' => $sub->plan->slug,
                    'name' => $sub->plan->name,
                    'price' => (float) $sub->plan->price,
                    'feature_limits' => $sub->plan->feature_limits,
                ],
                'on_trial' => $sub->onTrial(),
                'trial_ends_at' => $sub->trial_ends_at,
                'trial_days_left' => $sub->trialDaysLeft(),
                'current_period_end' => $sub->current_period_end,
            ],
        ]);
    }

    public function plans(): JsonResponse
    {
        $plans = Plan::where('is_active', true)->orderBy('sort_order')->get()
            ->map(fn (Plan $p) => [
                'slug' => $p->slug,
                'name' => $p->name,
                'price' => (float) $p->price,
                'currency' => $p->currency,
                'billing_cycle' => $p->billing_cycle,
                'is_custom' => $p->is_custom,
                'feature_limits' => $p->feature_limits,
            ]);

        return response()->json(['data' => $plans]);
    }

    /**
     * Payment history: every SubscriptionInvoice across all of this tenant's
     * subscription rows (a tenant may have re-subscribed over time), newest first.
     */
    public function invoices(): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();

        $invoices = SubscriptionInvoice::query()
            ->whereHas('subscription', fn ($q) => $q->where('tenant_id', $tenantId))
            ->with('subscription.plan')
            ->latest('id')
            ->get()
            ->map(fn (SubscriptionInvoice $inv) => [
                'id' => $inv->id,
                'number' => sprintf('SUB-%06d', $inv->id),
                'plan' => $inv->subscription?->plan?->name,
                'amount' => (float) $inv->amount,
                'currency' => $inv->currency,
                'status' => $inv->status,
                'payment_method' => $inv->payment_method,
                'paid_at' => $inv->paid_at,
                'refund_amount' => $inv->refund_amount !== null ? (float) $inv->refund_amount : null,
                'refunded_at' => $inv->refunded_at,
                'created_at' => $inv->created_at,
            ]);

        return response()->json(['data' => $invoices]);
    }

    /**
     * Render a subscription invoice as a PDF on the platform's letterhead
     * (the SaaS operator bills the tenant, not the tenant's own company).
     * Streams inline for preview; ?download=1 forces a file download.
     */
    public function invoicePdf(Request $request, int $invoice)
    {
        $tenantId = app(TenantContext::class)->id();

        $inv = SubscriptionInvoice::with('subscription.plan')
            ->whereHas('subscription', fn ($q) => $q->where('tenant_id', $tenantId))
            ->findOrFail($invoice);

        $number = sprintf('SUB-%06d', $inv->id);

        $pdf = Pdf::loadView('documents.subscription-invoice', [
            'invoice' => $inv,
            'number' => $number,
            'tenant' => Tenant::findOrFail($tenantId),
            'company' => [
                'name' => config('app.name'),
                'legal_name' => null,
                'address' => null,
                'phone' => null,
                'email' => config('mail.from.address'),
                'gstin' => null,
                'pan' => null,
            ],
            'currency' => $inv->currency === 'INR' ? '₹' : $inv->currency.' ',
            'generatedAt' => now()->format('d M Y, H:i'),
        ])->setPaper('a4');

        $filename = "subscription-invoice-{$number}.pdf";

        return $request->boolean('download') ? $pdf->download($filename) : $pdf->stream($filename);
    }

    /**
     * Upgrade/downgrade. When Razorpay is configured a real recurring
     * subscription is created and we wait for the webhook to flip status to
     * active; otherwise (local/dev) the plan switch is applied immediately.
     */
    public function changePlan(Request $request): JsonResponse
    {
        $data = $request->validate([
            'plan_slug' => ['required', 'string', Rule::in(Plan::query()->pluck('slug')->all())],
        ]);

        $sub = $this->current();
        if (! $sub) {
            return response()->json(['message' => 'No subscription found for this tenant.'], 404);
        }

        $plan = Plan::where('slug', $data['plan_slug'])->firstOrFail();

        if ($plan->is_custom) {
            return response()->json([
                'message' => 'Enterprise is quote-based — our team will contact you to finalise pricing.',
                'contact_sales' => true,
            ], 202);
        }

        if ($this->razorpay->configured()) {
            try {
                return $this->startCheckout($sub, $plan, 'Complete payment to activate your new plan.');
            } catch (RuntimeException $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            }
        }

        // Local / unconfigured: apply the plan change immediately.
        $sub->update([
            'plan_id' => $plan->id,
            'status' => 'active',
            'current_period_start' => now(),
            'current_period_end' => now()->addMonth(),
        ]);
        Tenant::where('id', $sub->tenant_id)->update(['status' => 'active']);

        return response()->json([
            'message' => "Switched to the {$plan->name} plan.",
            'data' => ['plan' => $plan->slug, 'status' => 'active'],
        ]);
    }

    /**
     * Create a fresh Razorpay subscription for $plan and return the payload
     * the in-app Checkout modal needs. The chosen plan is parked on
     * pending_plan_id; status only flips once payment is confirmed (checkout
     * callback or webhook).
     */
    private function startCheckout(Subscription $sub, Plan $plan, string $message): JsonResponse
    {
        $tenant = Tenant::findOrFail($sub->tenant_id);
        $razorpayPlanId = $this->razorpay->ensurePlan($plan);
        $customerId = $sub->razorpay_customer_id ?: $this->razorpay->createCustomer($tenant);
        $rzpSub = $this->razorpay->createSubscription($razorpayPlanId, $customerId);

        $sub->update([
            'pending_plan_id' => $plan->id,
            'razorpay_subscription_id' => $rzpSub['id'] ?? null,
            'razorpay_customer_id' => $customerId,
        ]);

        return response()->json([
            'message' => $message,
            'razorpay_subscription_id' => $rzpSub['id'] ?? null,
            // For the in-app Checkout modal:
            'razorpay_key' => $this->razorpay->keyId(),
            'plan_name' => $plan->name,
            'prefill' => [
                'name' => $tenant->company_name,
                'email' => $tenant->email,
                'contact' => $tenant->phone,
            ],
            // Hosted-page fallback if Checkout can't load in the browser.
            'short_url' => $rzpSub['short_url'] ?? null,
        ]);
    }

    /**
     * "Pay Now" for a lapsed subscription (past_due, or suspended by billing).
     * If Razorpay still has an open invoice on the existing subscription we
     * surface that (paying it settles the dues and resumes the same
     * subscription — no new mandate). Only when there is nothing left to
     * retry do we start a fresh checkout on the tenant's current plan.
     */
    public function payNow(): JsonResponse
    {
        $sub = $this->current();
        if (! $sub) {
            return response()->json(['message' => 'No subscription found for this tenant.'], 404);
        }

        if (! in_array($sub->status, ['past_due', 'suspended'], true)) {
            return response()->json(['message' => 'No payment is due on your subscription.'], 409);
        }

        $plan = $sub->pendingPlan ?: $sub->plan;

        if (! $this->razorpay->configured()) {
            // Local / unconfigured: renew the current period immediately.
            $sub->update([
                'pending_plan_id' => null,
                'status' => 'active',
                'current_period_start' => now(),
                'current_period_end' => $plan->billing_cycle === 'yearly' ? now()->addYear() : now()->addMonth(),
            ]);
            Tenant::where('id', $sub->tenant_id)->update(['status' => 'active', 'suspended_by' => null]);

            return response()->json([
                'message' => 'Subscription reactivated.',
                'data' => ['plan' => $plan->slug, 'status' => 'active'],
            ]);
        }

        if ($sub->razorpay_subscription_id) {
            try {
                $invoice = $this->razorpay->latestUnpaidInvoice($sub->razorpay_subscription_id);

                if ($invoice && ! empty($invoice['short_url'])) {
                    return response()->json([
                        'message' => 'Complete the pending payment to reactivate your subscription.',
                        'payment_url' => $invoice['short_url'],
                        'amount' => isset($invoice['amount']) ? ((int) $invoice['amount']) / 100 : null,
                    ]);
                }
            } catch (Throwable $e) {
                // Couldn't look up dues — fall through to a fresh checkout.
                Log::warning('Razorpay pay-now: invoice lookup failed', [
                    'subscription' => $sub->razorpay_subscription_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        try {
            return $this->startCheckout($sub, $plan, 'Complete payment to reactivate your subscription.');
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * Callback from the in-app Razorpay Checkout after the user completes
     * payment. Verifies the checkout signature, then applies the pending plan
     * and activates immediately for a snappy UX. The webhook remains the
     * source of truth and stays idempotent on top of this (both key the
     * invoice by the gateway payment id).
     */
    public function confirmPayment(Request $request): JsonResponse
    {
        $data = $request->validate([
            'razorpay_payment_id' => ['required', 'string'],
            'razorpay_subscription_id' => ['required', 'string'],
            'razorpay_signature' => ['required', 'string'],
        ]);

        $sub = $this->current();
        if (! $sub || $sub->razorpay_subscription_id !== $data['razorpay_subscription_id']) {
            return response()->json(['message' => 'This payment does not match your subscription.'], 422);
        }

        $verified = $this->razorpay->verifyCheckoutSignature(
            $data['razorpay_payment_id'],
            $data['razorpay_subscription_id'],
            $data['razorpay_signature'],
        );

        if (! $verified) {
            return response()->json([
                'message' => 'Payment could not be verified. If you were charged, your plan will activate automatically within a few minutes.',
            ], 422);
        }

        $plan = $sub->pendingPlan ?: $sub->plan;

        $sub->update([
            'plan_id' => $plan->id,
            'pending_plan_id' => null,
            'status' => 'active',
            'current_period_start' => now(),
            'current_period_end' => $plan->billing_cycle === 'yearly' ? now()->addYear() : now()->addMonth(),
        ]);
        Tenant::where('id', $sub->tenant_id)->update(['status' => 'active', 'suspended_by' => null]);

        // Record the invoice with the gateway-confirmed amount (never the
        // browser's); keyed by payment id so the webhook can't duplicate it.
        try {
            $payment = $this->razorpay->fetchPayment($data['razorpay_payment_id']);
            SubscriptionInvoice::firstOrCreate(
                ['payment_gateway_ref' => $data['razorpay_payment_id']],
                [
                    'subscription_id' => $sub->id,
                    'amount' => ((int) ($payment['amount'] ?? 0)) / 100,
                    'currency' => $payment['currency'] ?? 'INR',
                    'status' => 'paid',
                    'paid_at' => now(),
                ],
            );
        } catch (Throwable $e) {
            // Activation already succeeded; the webhook will backfill the invoice.
            Log::warning('Razorpay confirm-payment: could not fetch payment for invoice', [
                'payment_id' => $data['razorpay_payment_id'],
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json([
            'message' => "Payment received — you're now on the {$plan->name} plan.",
            'data' => ['plan' => $plan->slug, 'status' => 'active'],
        ]);
    }
}
