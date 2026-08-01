<?php

declare(strict_types=1);

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\PlatformCoupon;
use App\Models\PlatformCouponRedemption;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Promotional coupons for the platform panel (Volume 11 §Billing → Coupons):
 * issue a code, see what it has cost, retire it. Guarded by `coupons.*`.
 *
 * Two deliberate restrictions on editing a live coupon:
 *
 *  - The **code** is immutable once redeemed. It is out in the world by then — on
 *    a landing page, in an email — and renaming it would silently invalidate every
 *    copy while the redemptions stay attached to a code nobody was offered.
 *  - `max_redemptions` cannot be lowered below what has already been spent, which
 *    would leave the counter reading "112 of 100 used".
 *
 * Deleting is only allowed while a coupon is untouched; a redeemed one is
 * deactivated instead, because its redemptions are revenue records.
 */
class BillingCouponController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = PlatformCoupon::query();

        if ($search = trim((string) $request->query('search', ''))) {
            $query->where(fn ($q) => $q
                ->where('code', 'like', "%{$search}%")
                ->orWhere('name', 'like', "%{$search}%"));
        }

        // 'live' is the only filter worth having as a shortcut — it is the
        // question an operator actually asks ("what is running right now").
        match ($request->query('status')) {
            'live' => $query->where('is_active', true)
                ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
                ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', now())),
            'expired' => $query->whereNotNull('ends_at')->where('ends_at', '<', now()),
            'inactive' => $query->where('is_active', false),
            default => null,
        };

        $perPage = min((int) $request->query('per_page', 20), 100);
        $page = $query->orderByDesc('id')->paginate($perPage);

        return response()->json([
            'data' => collect($page->items())->map(fn (PlatformCoupon $c) => $this->payload($c))->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
            'plans' => Plan::orderBy('sort_order')->get(['id', 'name', 'slug'])->map(fn (Plan $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'slug' => $p->slug,
            ]),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        $data['code'] = PlatformCoupon::normaliseCode($data['code']);
        $data['created_by'] = $request->user()?->id;

        $coupon = PlatformCoupon::create($data);

        activity('central-billing')->causedBy($request->user())
            ->withProperties(['ip' => $request->ip(), 'coupon_id' => $coupon->id, 'code' => $coupon->code])
            ->event('coupon-created')
            ->log("Operator created coupon {$coupon->code}");

        return response()->json(['data' => $this->payload($coupon)], 201);
    }

    public function update(Request $request, PlatformCoupon $coupon): JsonResponse
    {
        $data = $this->validated($request, $coupon);

        if (array_key_exists('code', $data)) {
            $code = PlatformCoupon::normaliseCode($data['code']);

            if ($code !== $coupon->code && $coupon->redemption_count > 0) {
                return response()->json([
                    'message' => 'This code has already been redeemed and cannot be renamed. Create a new coupon instead.',
                    'errors' => ['code' => ['Already redeemed — the code is fixed.']],
                ], 422);
            }

            $data['code'] = $code;
        }

        if (array_key_exists('max_redemptions', $data)
            && $data['max_redemptions'] !== null
            && (int) $data['max_redemptions'] < $coupon->redemption_count) {
            return response()->json([
                'message' => 'The redemption limit cannot be lower than the '.$coupon->redemption_count.' already used.',
                'errors' => ['max_redemptions' => ['Already redeemed '.$coupon->redemption_count.' times.']],
            ], 422);
        }

        $coupon->update($data);

        activity('central-billing')->causedBy($request->user())
            ->withProperties(['ip' => $request->ip(), 'coupon_id' => $coupon->id, 'changes' => array_keys($data)])
            ->event('coupon-updated')
            ->log("Operator updated coupon {$coupon->code}");

        return response()->json(['data' => $this->payload($coupon->fresh())]);
    }

    /** Switch a coupon on or off without touching its terms. */
    public function toggle(Request $request, PlatformCoupon $coupon): JsonResponse
    {
        $coupon->update(['is_active' => ! $coupon->is_active]);

        activity('central-billing')->causedBy($request->user())
            ->withProperties(['ip' => $request->ip(), 'coupon_id' => $coupon->id, 'is_active' => $coupon->is_active])
            ->event('coupon-toggled')
            ->log('Operator '.($coupon->is_active ? 'activated' : 'deactivated')." coupon {$coupon->code}");

        return response()->json(['data' => $this->payload($coupon)]);
    }

    public function destroy(Request $request, PlatformCoupon $coupon): JsonResponse
    {
        if ($coupon->redemption_count > 0) {
            return response()->json([
                'message' => 'This coupon has been redeemed and is part of your revenue records. Deactivate it instead.',
            ], 422);
        }

        $code = $coupon->code;
        $coupon->delete();

        activity('central-billing')->causedBy($request->user())
            ->withProperties(['ip' => $request->ip(), 'code' => $code])
            ->event('coupon-deleted')
            ->log("Operator deleted coupon {$code}");

        return response()->json(['message' => 'Coupon deleted.']);
    }

    /** Who used this coupon, when, and for how much. */
    public function redemptions(Request $request, PlatformCoupon $coupon): JsonResponse
    {
        $rows = PlatformCouponRedemption::query()
            ->where('platform_coupon_id', $coupon->id)
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        // Tenants live on the landlord connection; resolve names in one query
        // rather than per row through a cross-connection relation.
        $tenants = Tenant::whereIn('id', $rows->pluck('tenant_id')->unique())
            ->pluck('company_name', 'id');

        return response()->json([
            'data' => $rows->map(fn (PlatformCouponRedemption $r) => [
                'id' => $r->id,
                'tenant_id' => $r->tenant_id,
                'tenant_name' => $tenants[$r->tenant_id] ?? null,
                'subscription_invoice_id' => $r->subscription_invoice_id,
                'discount_amount' => $r->discount_amount,
                'currency' => $r->currency,
                'redeemed_at' => $r->redeemed_at?->toDateTimeString(),
            ])->values(),
            'summary' => [
                'redemption_count' => $coupon->redemption_count,
                'total_discount' => round((float) PlatformCouponRedemption::query()
                    ->where('platform_coupon_id', $coupon->id)
                    ->sum('discount_amount'), 2),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?PlatformCoupon $existing = null): array
    {
        $sometimes = $existing ? 'sometimes' : 'required';

        $rules = [
            'code' => [$sometimes, 'string', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/',
                Rule::unique('platform_coupons', 'code')->ignore($existing?->id)],
            'name' => [$sometimes, 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],

            'discount_type' => [$sometimes, Rule::in(PlatformCoupon::DISCOUNT_TYPES)],
            'discount_value' => [$sometimes, 'numeric', 'min:0.01'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'max_discount_amount' => ['sometimes', 'nullable', 'numeric', 'min:0.01'],
            'min_amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],

            'duration' => ['sometimes', Rule::in(PlatformCoupon::DURATIONS)],
            'duration_cycles' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:120',
                'required_if:duration,repeating'],

            'applies_to' => ['sometimes', Rule::in(PlatformCoupon::APPLIES_TO)],
            'plan_ids' => ['sometimes', 'nullable', 'array'],
            'plan_ids.*' => ['integer', Rule::exists('plans', 'id')],
            'new_tenants_only' => ['sometimes', 'boolean'],

            'max_redemptions' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'max_redemptions_per_tenant' => ['sometimes', 'integer', 'min:1', 'max:100'],

            'starts_at' => ['sometimes', 'nullable', 'date'],
            'ends_at' => ['sometimes', 'nullable', 'date', 'after:starts_at'],
            'is_active' => ['sometimes', 'boolean'],
        ];

        $data = $request->validate($rules);

        // A percentage over 100 would pay the customer to subscribe.
        if (($data['discount_type'] ?? $existing?->discount_type) === 'percent'
            && isset($data['discount_value'])
            && (float) $data['discount_value'] > 100) {
            abort(response()->json([
                'message' => 'A percentage discount cannot exceed 100%.',
                'errors' => ['discount_value' => ['Must be 100 or less for a percentage coupon.']],
            ], 422));
        }

        // `duration_cycles` is meaningless outside a repeating coupon; drop it so a
        // coupon switched to `forever` doesn't keep a stale count.
        if (array_key_exists('duration', $data) && $data['duration'] !== 'repeating') {
            $data['duration_cycles'] = null;
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(PlatformCoupon $c): array
    {
        return [
            'id' => $c->id,
            'code' => $c->code,
            'name' => $c->name,
            'description' => $c->description,
            'discount_type' => $c->discount_type,
            'discount_value' => $c->discount_value,
            'currency' => $c->currency,
            'max_discount_amount' => $c->max_discount_amount,
            'min_amount' => $c->min_amount,
            'duration' => $c->duration,
            'duration_cycles' => $c->duration_cycles,
            'applies_to' => $c->applies_to,
            'plan_ids' => $c->plan_ids ?? [],
            'new_tenants_only' => $c->new_tenants_only,
            'max_redemptions' => $c->max_redemptions,
            'max_redemptions_per_tenant' => $c->max_redemptions_per_tenant,
            'redemption_count' => $c->redemption_count,
            'starts_at' => $c->starts_at?->toDateTimeString(),
            'ends_at' => $c->ends_at?->toDateTimeString(),
            'is_active' => $c->is_active,
            // Derived state, so the table can say "live" / "expired" / "used up"
            // without the client re-implementing the eligibility rules.
            'is_live' => $c->isCurrentlyValid() && ! $c->isExhausted(),
            'is_exhausted' => $c->isExhausted(),
            'label' => $c->label(),
            'created_at' => $c->created_at?->toDateTimeString(),
        ];
    }
}
