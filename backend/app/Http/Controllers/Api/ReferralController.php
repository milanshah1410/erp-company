<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReferralRequest;
use App\Http\Resources\ReferralResource;
use App\Models\Customer;
use App\Models\Referral;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReferralController extends Controller
{
    public function index(Request $request)
    {
        $query = Referral::query()->with(['referrer:id,code,name', 'referredCustomer:id,code,name']);

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('referred_name', 'like', "%{$search}%")
                    ->orWhere('referred_phone', 'like', "%{$search}%")
                    ->orWhereHas('referrer', fn ($r) => $r->where('name', 'like', "%{$search}%"));
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        if ($request->filled('referrer_customer_id')) {
            $query->where('referrer_customer_id', $request->query('referrer_customer_id'));
        }

        $query->orderByDesc('id');

        $perPage = min((int) $request->query('per_page', 15), 100);

        return ReferralResource::collection($query->paginate($perPage));
    }

    public function store(ReferralRequest $request)
    {
        $data = $request->validated();
        $data['status'] = 'pending';
        $data['reward_points'] = $data['reward_points'] ?? 0;
        $data['created_by'] = $request->user()?->id;

        $referral = Referral::create($data);

        return (new ReferralResource($referral->load('referrer')))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Referral $referral)
    {
        return new ReferralResource($referral->load('referrer', 'referredCustomer'));
    }

    public function update(ReferralRequest $request, Referral $referral)
    {
        $referral->update($request->validated());

        return new ReferralResource($referral->load('referrer', 'referredCustomer'));
    }

    public function destroy(Referral $referral)
    {
        $referral->delete();

        return response()->json(['message' => 'Referral deleted.']);
    }

    /**
     * Mark a referral as joined and link it to a customer (existing or the one
     * created from the referred person). Also stamps referred_by on the customer.
     */
    public function markJoined(Request $request, Referral $referral)
    {
        $validated = $request->validate([
            'referred_customer_id' => ['required', 'integer', 'exists:customers,id'],
        ]);

        if (in_array($referral->status, ['rewarded'], true)) {
            return response()->json(['message' => 'This referral has already been rewarded.'], 422);
        }

        DB::transaction(function () use ($referral, $validated) {
            $referral->update([
                'referred_customer_id' => $validated['referred_customer_id'],
                'status' => 'joined',
                'joined_at' => now(),
            ]);

            Customer::query()->whereKey($validated['referred_customer_id'])
                ->update(['referred_by_customer_id' => $referral->referrer_customer_id]);
        });

        return new ReferralResource($referral->fresh()->load('referrer', 'referredCustomer'));
    }

    /**
     * Reward the referrer: credit the configured loyalty points to the
     * referrer's ledger and mark the referral rewarded. Idempotent.
     */
    public function reward(Request $request, Referral $referral)
    {
        if ($referral->status === 'rewarded') {
            return response()->json(['message' => 'This referral has already been rewarded.'], 422);
        }
        if ($referral->reward_points <= 0) {
            throw ValidationException::withMessages([
                'reward_points' => 'Set the reward points on this referral before rewarding.',
            ]);
        }

        DB::transaction(function () use ($referral, $request) {
            $referral->referrer->loyaltyTransactions()->create([
                'type' => 'adjust',
                'points' => $referral->reward_points,
                'description' => "Referral reward — {$referral->referred_name}",
                'created_by' => $request->user()?->id,
            ]);

            $referral->update(['status' => 'rewarded', 'rewarded_at' => now()]);
        });

        return new ReferralResource($referral->fresh()->load('referrer', 'referredCustomer'));
    }
}
