<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Services\RazorpayService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

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
                $tenant = Tenant::findOrFail($sub->tenant_id);
                $razorpayPlanId = $this->razorpay->ensurePlan($plan);
                $customerId = $sub->razorpay_customer_id ?: $this->razorpay->createCustomer($tenant);
                $rzpSub = $this->razorpay->createSubscription($razorpayPlanId, $customerId);

                // Leave our status until the webhook confirms payment; store refs.
                $sub->update([
                    'razorpay_subscription_id' => $rzpSub['id'] ?? null,
                    'razorpay_customer_id' => $customerId,
                ]);

                return response()->json([
                    'message' => 'Complete payment to activate your new plan.',
                    'razorpay_subscription_id' => $rzpSub['id'] ?? null,
                    'short_url' => $rzpSub['short_url'] ?? null,
                ]);
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
}
