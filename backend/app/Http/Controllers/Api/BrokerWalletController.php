<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\BrokerWalletTransactionRequest;
use App\Http\Resources\BrokerWalletTransactionResource;
use App\Models\Broker;
use App\Services\BrokerCommissionService;
use Illuminate\Http\Request;

/**
 * The broker wallet: the running money ledger the company keeps per broker.
 * Read the balance + transactions, or post a manual movement (top-up / advance
 * paid / adjustment). Commission, reversal and settlement entries are posted
 * automatically by BrokerCommissionService.
 */
class BrokerWalletController extends Controller
{
    public function __construct(private readonly BrokerCommissionService $commissions)
    {
    }

    public function index(Request $request, Broker $broker)
    {
        $perPage = min((int) $request->query('per_page', 20), 100);

        return BrokerWalletTransactionResource::collection(
            $broker->walletTransactions()->orderByDesc('id')->paginate($perPage)
        )->additional(['balance' => round((float) $broker->wallet_balance, 2)]);
    }

    /**
     * Post a manual wallet movement. `topup` and positive `adjustment` credit
     * the broker; `advance` and negative `adjustment` debit them. The signed
     * amount is derived from the type so callers always send a positive number.
     */
    public function store(BrokerWalletTransactionRequest $request, Broker $broker)
    {
        $data = $request->validated();
        $magnitude = abs((float) $data['amount']);

        $signed = match ($data['type']) {
            'topup' => $magnitude,
            'advance' => -$magnitude,
            'adjustment' => ($data['direction'] ?? 'credit') === 'debit' ? -$magnitude : $magnitude,
        };

        $txn = $this->commissions->recordWalletManual(
            $broker, $data['type'], $signed, $data['notes'] ?? null, $request->user()?->id,
        );

        return (new BrokerWalletTransactionResource($txn))
            ->response()
            ->setStatusCode(201);
    }
}
