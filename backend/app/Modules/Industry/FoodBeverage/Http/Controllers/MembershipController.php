<?php

declare(strict_types=1);

namespace App\Modules\Industry\FoodBeverage\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Modules\Industry\FoodBeverage\Models\CustomerMembership;
use App\Modules\Industry\FoodBeverage\Models\MembershipPlan;
use App\Modules\Industry\FoodBeverage\Services\MembershipService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Membership tiers, and the cards people hold.
 *
 * Both live on one controller because they are one screen: a café sells three tiers
 * and looks at who is on them, and splitting that across two nav entries would mean
 * two clicks to answer one question.
 *
 * Selling, renewing and cancelling all go through MembershipService — the money is
 * a sale on the 'membership' channel and none of it is done here.
 */
class MembershipController extends Controller
{
    public function __construct(private readonly MembershipService $memberships) {}

    /*
    |--------------------------------------------------------------------------
    | Plans
    |--------------------------------------------------------------------------
    */

    public function plans(Request $request)
    {
        $plans = MembershipPlan::query()
            ->withCount([
                'memberships as active_count' => fn ($q) => $q->entitling(),
                'memberships as total_count',
            ])
            ->when($request->query('search'), fn ($q, $s) => $q->where('name', 'like', "%{$s}%"))
            ->orderBy('price')
            ->get();

        return response()->json([
            'data' => $plans->map(fn (MembershipPlan $p) => [
                'id' => $p->id,
                'code' => $p->code,
                'name' => $p->name,
                'description' => $p->description,
                'price' => (float) $p->price,
                'duration_days' => (int) $p->duration_days,
                'discount_percent' => (float) $p->discount_percent,
                'loyalty_multiplier' => (float) $p->loyalty_multiplier,
                'benefits' => $p->benefits,
                'is_active' => (bool) $p->is_active,
                'active_members' => (int) $p->active_count,
                'total_sold' => (int) $p->total_count,
                // What the tier has earned, at the price each member actually paid.
                'revenue' => round((float) $p->memberships()->sum('price_paid'), 2),
            ])->values(),
        ]);
    }

    public function storePlan(Request $request)
    {
        $data = $this->validatePlan($request);

        $plan = MembershipPlan::create($data);

        return response()->json(['data' => $plan], 201);
    }

    public function updatePlan(Request $request, MembershipPlan $membershipPlan)
    {
        $membershipPlan->update($this->validatePlan($request, $membershipPlan));

        return response()->json(['data' => $membershipPlan->fresh()]);
    }

    public function destroyPlan(MembershipPlan $membershipPlan)
    {
        /**
         * A tier somebody holds is deactivated, never deleted: the card in their
         * wallet has to keep resolving to the discount it promised, and cascading
         * the delete would strip the entitlement from a live member mid-term.
         */
        if ($membershipPlan->memberships()->entitling()->exists()) {
            $membershipPlan->update(['is_active' => false]);

            return response()->json([
                'message' => 'Members still hold this tier, so it has been deactivated instead of deleted.',
            ]);
        }

        $membershipPlan->delete();

        return response()->json(['message' => 'Membership plan deleted.']);
    }

    /*
    |--------------------------------------------------------------------------
    | Cards
    |--------------------------------------------------------------------------
    */

    public function index(Request $request)
    {
        $query = CustomerMembership::query()
            ->with(['customer:id,code,name,phone', 'plan:id,code,name,discount_percent']);

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('member_number', 'like', "%{$search}%")
                    ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%"));
            });
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($request->filled('membership_plan_id')) {
            $query->where('membership_plan_id', $request->query('membership_plan_id'));
        }

        // Expiring soonest first among live cards: the renewal call list.
        $query->orderByRaw("FIELD(status, 'active', 'expired', 'cancelled')")->orderBy('ends_on');

        return response()->json(
            $query->paginate(min((int) $request->query('per_page', 25), 100))
                ->through(fn (CustomerMembership $m) => $this->row($m))->toArray()
            + ['summary' => $this->summary()],
        );
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'customer_id' => ['required', 'exists:customers,id'],
            'membership_plan_id' => ['required', 'exists:membership_plans,id'],
            'starts_on' => ['nullable', 'date'],
            // An override for a discounted or comped card. Null takes the tier's price.
            'price' => ['nullable', 'numeric', 'min:0'],
            'raise_invoice' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $membership = $this->memberships->sell(
                Customer::findOrFail($data['customer_id']),
                MembershipPlan::findOrFail($data['membership_plan_id']),
                $data['starts_on'] ?? null,
                isset($data['price']) ? (float) $data['price'] : null,
                $data['raise_invoice'] ?? true,
                $request->user()?->id,
                $data['notes'] ?? null,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => $this->row($membership->fresh(['customer', 'plan'])),
        ], 201);
    }

    public function renew(Request $request, CustomerMembership $customerMembership)
    {
        $data = $request->validate([
            'price' => ['nullable', 'numeric', 'min:0'],
            'raise_invoice' => ['nullable', 'boolean'],
        ]);

        try {
            $membership = $this->memberships->renew(
                $customerMembership,
                isset($data['price']) ? (float) $data['price'] : null,
                $data['raise_invoice'] ?? true,
                $request->user()?->id,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->row($membership->fresh(['customer', 'plan']))]);
    }

    public function cancel(Request $request, CustomerMembership $customerMembership)
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        $membership = $this->memberships->cancel($customerMembership, $data['reason'] ?? null);

        return response()->json(['data' => $this->row($membership->fresh(['customer', 'plan']))]);
    }

    /**
     * The card a customer holds — what the till asks before it prices a bill.
     *
     * Returns 200 with a null card rather than a 404: "this customer is not a
     * member" is a normal answer, not an error, and a POS screen that had to treat
     * it as one would log a 404 on most of its sales.
     */
    public function forCustomer(Customer $customer)
    {
        $membership = $this->memberships->activeFor((int) $customer->id);

        return response()->json([
            'data' => $membership ? $this->row($membership->load(['customer', 'plan'])) : null,
        ]);
    }

    /** @return array<string, mixed> */
    private function validatePlan(Request $request, ?MembershipPlan $plan = null): array
    {
        return $request->validate([
            'code' => [
                'required', 'string', 'max:50',
                Rule::unique('membership_plans', 'code')
                    ->where('tenant_id', app(\App\Support\TenantContext::class)->id())
                    ->ignore($plan?->id),
            ],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'price' => ['required', 'numeric', 'min:0'],
            'duration_days' => ['required', 'integer', 'min:1', 'max:3650'],
            'discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'loyalty_multiplier' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'benefits' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['nullable', 'boolean'],
        ]);
    }

    private function summary(): array
    {
        return [
            'active' => CustomerMembership::query()->entitling()->count(),
            'expired' => CustomerMembership::where('status', 'expired')->count(),
            'cancelled' => CustomerMembership::where('status', 'cancelled')->count(),
            // The renewal call list: live cards inside their last fortnight.
            'expiring_30d' => CustomerMembership::query()->entitling()
                ->whereDate('ends_on', '<=', now()->addDays(30)->toDateString())
                ->count(),
            'revenue' => round((float) CustomerMembership::sum('price_paid'), 2),
        ];
    }

    private function row(CustomerMembership $m): array
    {
        return [
            'id' => $m->id,
            'member_number' => $m->member_number,
            'customer_id' => $m->customer_id,
            'customer' => $m->customer?->name,
            'phone' => $m->customer?->phone,
            'membership_plan_id' => $m->membership_plan_id,
            'plan' => $m->plan?->name,
            'discount_percent' => (float) ($m->plan?->discount_percent ?? 0),
            'starts_on' => $m->starts_on?->toDateString(),
            'ends_on' => $m->ends_on?->toDateString(),
            'status' => $m->status,
            // Distinct from `status`: a card marked active whose date has passed is
            // still not entitling, and this is the field the till trusts.
            'is_entitling' => $m->isCurrentlyValid(),
            'days_remaining' => $m->daysRemaining(),
            'price_paid' => (float) $m->price_paid,
            'invoice_id' => $m->invoice_id,
            'notes' => $m->notes,
        ];
    }
}
