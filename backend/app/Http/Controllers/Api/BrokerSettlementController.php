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

    /**
     * Record a payout and FIFO-allocate it across the broker's pending
     * commission. TDS is withheld (default from the broker). Omit `amount` with
     * `settle_outstanding=true` to clear the broker's full pending balance —
     * tag it with `period_month` (YYYY-MM) for a monthly settlement.
     */
    public function store(BrokerSettlementRequest $request, Broker $broker)
    {
        $data = $request->validated();
        $data['created_by'] = $request->user()?->id;

        if (! isset($data['amount'])) {
            $data['amount'] = $broker->outstandingCommission();
        }
        if ((float) $data['amount'] <= 0) {
            return response()->json(['message' => 'Nothing to settle: the broker has no outstanding commission.'], 422);
        }

        $settlement = DB::transaction(fn () => $this->commissions->settle($broker, $data));

        return (new BrokerSettlementResource($settlement->load('allocations')))
            ->response()
            ->setStatusCode(201);
    }
}
