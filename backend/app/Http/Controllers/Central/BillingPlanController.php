<?php

declare(strict_types=1);

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Admin CRUD over the plan catalogue for the SaaS-owner platform panel.
 * Guarded by the `plans.*` central permissions. Distinct from the public
 * PlanController, which only lists active plans for the signup page.
 */
class BillingPlanController extends Controller
{
    public function index(): JsonResponse
    {
        $plans = Plan::query()
            ->withCount('subscriptions')
            ->withCount(['subscriptions as active_subscriptions_count' => function ($q) {
                $q->whereIn('status', ['trialing', 'active']);
            }])
            ->orderBy('sort_order')
            ->get();

        return response()->json([
            // `modules` resolved through the plan so '*' arrives expanded, and
            // `modules_all` flags the plans that place no restriction at all —
            // what a plan sells is half of any change-plan decision, and callers
            // shouldn't have to re-implement the '*' rule to see it.
            'data' => $plans->map(fn (Plan $p) => $p->toArray() + [
                'modules' => $p->allowedModules(),
                'modules_all' => $p->allowsAllModules(),
            ])->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateData($request);

        $plan = Plan::create($data);

        activity('central-billing')->causedBy($request->user())
            ->withProperties(['ip' => $request->ip(), 'plan_id' => $plan->id])
            ->event('plan-created')->log("Operator created plan \"{$plan->name}\"");

        return response()->json(['data' => $plan], 201);
    }

    public function update(Request $request, Plan $plan): JsonResponse
    {
        $data = $this->validateData($request, $plan);

        $plan->update($data);

        activity('central-billing')->causedBy($request->user())
            ->withProperties(['ip' => $request->ip(), 'plan_id' => $plan->id])
            ->event('plan-updated')->log("Operator updated plan \"{$plan->name}\"");

        return response()->json(['data' => $plan->fresh()]);
    }

    public function destroy(Request $request, Plan $plan): JsonResponse
    {
        // A plan with subscriptions (any status — history matters for revenue
        // reporting) can only be retired, never deleted.
        if ($plan->subscriptions()->exists()) {
            return response()->json([
                'message' => 'This plan has subscriptions. Deactivate it instead of deleting.',
            ], 422);
        }

        $plan->delete();

        activity('central-billing')->causedBy($request->user())
            ->withProperties(['ip' => $request->ip(), 'plan_id' => $plan->id])
            ->event('plan-deleted')->log("Operator deleted plan \"{$plan->name}\"");

        return response()->json(['message' => 'Plan deleted.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateData(Request $request, ?Plan $plan = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:64', 'alpha_dash', Rule::unique('plans', 'slug')->ignore($plan?->id)],
            'price' => ['required', 'numeric', 'min:0', 'max:9999999999'],
            'billing_cycle' => ['required', Rule::in(['monthly', 'yearly'])],
            'currency' => ['sometimes', 'string', 'size:3'],
            'is_custom' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'feature_limits' => ['sometimes', 'nullable', 'array'],
        ]);

        // The column is a non-nullable JSON blob — "no limits" is stored as {}.
        if (! isset($data['feature_limits'])) {
            $data['feature_limits'] = $plan?->feature_limits ?? [];
        }

        return $data;
    }
}
