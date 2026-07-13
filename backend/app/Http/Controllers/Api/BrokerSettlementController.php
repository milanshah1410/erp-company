<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\BrokerSettlementRequest;
use App\Http\Resources\BrokerSettlementResource;
use App\Models\Broker;
use App\Services\BrokerCommissionService;
use Illuminate\Support\Facades\DB;

class BrokerSettlementController extends Controller
{
    public function __construct(private BrokerCommissionService $commissions)
    {
    }

    /** Record a payout and FIFO-allocate it across the broker's pending commission. */
    public function store(BrokerSettlementRequest $request, Broker $broker)
    {
        $data = $request->validated();
        $data['created_by'] = $request->user()?->id;

        $settlement = DB::transaction(fn () => $this->commissions->settle($broker, $data));

        return (new BrokerSettlementResource($settlement->load('allocations')))
            ->response()
            ->setStatusCode(201);
    }
}
