<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\BrokerCommissionRateRequest;
use App\Http\Resources\BrokerCommissionRateResource;
use App\Models\Broker;
use App\Models\BrokerCommissionRate;

/**
 * Per-broker commission rate overrides, keyed by product or customer. Consumed
 * by BrokerCommissionService during accrual (product rate beats customer rate
 * beats broker default). Nested under a broker.
 */
class BrokerCommissionRateController extends Controller
{
    public function index(Broker $broker)
    {
        return BrokerCommissionRateResource::collection(
            $broker->rates()->with(['product:id,name,sku', 'customer:id,code,name'])->orderByDesc('id')->get()
        );
    }

    public function store(BrokerCommissionRateRequest $request, Broker $broker)
    {
        $rate = $broker->rates()->create($request->validated());

        return (new BrokerCommissionRateResource($rate->load(['product:id,name,sku', 'customer:id,code,name'])))
            ->response()
            ->setStatusCode(201);
    }

    public function update(BrokerCommissionRateRequest $request, Broker $broker, BrokerCommissionRate $rate)
    {
        abort_unless($rate->broker_id === $broker->id, 404);

        $rate->update($request->validated());

        return new BrokerCommissionRateResource($rate->load(['product:id,name,sku', 'customer:id,code,name']));
    }

    public function destroy(Broker $broker, BrokerCommissionRate $rate)
    {
        abort_unless($rate->broker_id === $broker->id, 404);

        $rate->delete();

        return response()->json(['message' => 'Commission rate deleted.']);
    }
}
