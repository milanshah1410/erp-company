<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CouponRequest;
use App\Http\Resources\CouponResource;
use App\Models\Coupon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CouponController extends Controller
{
    public function index(Request $request)
    {
        $query = Coupon::query();

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('code', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        // "valid" = active and within its date window right now.
        if ($request->query('state') === 'valid') {
            $today = now()->toDateString();
            $query->where('is_active', true)
                ->where(fn ($q) => $q->whereNull('valid_from')->orWhere('valid_from', '<=', $today))
                ->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', $today));
        }

        $query->orderByDesc('id');

        $perPage = min((int) $request->query('per_page', 15), 100);

        return CouponResource::collection($query->paginate($perPage));
    }

    public function store(CouponRequest $request)
    {
        $coupon = Coupon::create($request->validated());

        return (new CouponResource($coupon))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Coupon $coupon)
    {
        return new CouponResource($coupon);
    }

    public function update(CouponRequest $request, Coupon $coupon)
    {
        $coupon->update($request->validated());

        return new CouponResource($coupon);
    }

    public function destroy(Coupon $coupon)
    {
        if ($coupon->redemptions()->exists()) {
            return response()->json([
                'message' => 'This coupon has been redeemed and cannot be deleted. Mark it inactive instead.',
            ], 422);
        }
        $coupon->delete();

        return response()->json(['message' => 'Coupon deleted.']);
    }

    /**
     * Check a coupon code against an order without consuming it. Used at the
     * billing screen to preview the discount before checkout.
     */
    public function validateCoupon(Request $request)
    {
        $validated = $request->validate([
            'code' => ['required', 'string'],
            'order_value' => ['required', 'numeric', 'min:0'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
        ]);

        $coupon = Coupon::query()
            ->whereRaw('UPPER(code) = ?', [strtoupper(trim($validated['code']))])
            ->first();

        if (! $coupon) {
            return response()->json(['valid' => false, 'message' => 'Coupon code not found.'], 422);
        }

        $orderValue = (float) $validated['order_value'];
        [$eligible, $reason] = $coupon->checkEligibility($orderValue, $validated['customer_id'] ?? null);

        if (! $eligible) {
            return response()->json([
                'valid' => false,
                'message' => $reason,
                'coupon' => new CouponResource($coupon),
            ], 422);
        }

        return response()->json([
            'valid' => true,
            'discount_amount' => $coupon->discountFor($orderValue),
            'coupon' => new CouponResource($coupon),
        ]);
    }

    /**
     * Consume a coupon: re-validates, records a redemption and increments the
     * usage count atomically. Returns the discount applied.
     */
    public function redeem(Request $request, Coupon $coupon)
    {
        $validated = $request->validate([
            'order_value' => ['required', 'numeric', 'min:0'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'invoice_id' => ['nullable', 'integer', 'exists:invoices,id'],
        ]);

        $orderValue = (float) $validated['order_value'];
        $customerId = $validated['customer_id'] ?? null;

        $result = DB::transaction(function () use ($coupon, $orderValue, $customerId, $validated, $request) {
            // Lock the row so the usage-limit check and increment are race-free.
            $locked = Coupon::query()->whereKey($coupon->id)->lockForUpdate()->first();

            [$eligible, $reason] = $locked->checkEligibility($orderValue, $customerId);
            if (! $eligible) {
                return ['error' => $reason];
            }

            $discount = $locked->discountFor($orderValue);

            $locked->redemptions()->create([
                'customer_id' => $customerId,
                'invoice_id' => $validated['invoice_id'] ?? null,
                'order_value' => $orderValue,
                'discount_amount' => $discount,
                'created_by' => $request->user()?->id,
            ]);

            $locked->increment('used_count');

            return ['discount' => $discount, 'coupon' => $locked->refresh()];
        });

        if (isset($result['error'])) {
            return response()->json(['message' => $result['error']], 422);
        }

        return response()->json([
            'message' => 'Coupon redeemed.',
            'discount_amount' => $result['discount'],
            'coupon' => new CouponResource($result['coupon']),
        ]);
    }
}
