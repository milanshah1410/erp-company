<?php

declare(strict_types=1);

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Models\PaymentGatewayAccount;
use App\Models\PaymentTransaction;
use App\Models\Tenant;
use App\Services\PaymentGatewayService;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Unauthenticated customer pay page (SRS §16). Runs on the central stack with
 * no tenant resolved from a user — the tenant is derived from the payment
 * link's token and activated explicitly for the duration of each call.
 */
class PublicPaymentController extends Controller
{
    public function __construct(
        private PaymentGatewayService $gateways,
        private TenantContext $tenantContext,
    ) {}

    /** Public details for the pay page + the gateways the customer can choose. */
    public function show(string $token)
    {
        $txn = $this->locate($token);

        return $this->tenantContext->runWith($txn->tenant_id, function () use ($txn) {
            if ($txn->isExpired()) {
                $txn->update(['status' => 'expired']);
            }

            $txn->load('invoice:id,invoice_number');
            $company = Tenant::find($txn->tenant_id);

            return response()->json(['data' => [
                'token' => $txn->public_token,
                'amount' => $txn->amount,
                'currency' => $txn->currency,
                'status' => $txn->status,
                'invoice_number' => $txn->invoice?->invoice_number,
                'company_name' => $company?->company_name,
                'gateways' => $this->availableGateways($txn),
            ]]);
        });
    }

    /** Start checkout at the chosen gateway; returns redirect/form instructions. */
    public function start(Request $request, string $token)
    {
        $data = $request->validate(['gateway' => ['required', 'string']]);
        $txn = $this->locate($token);

        return $this->tenantContext->runWith($txn->tenant_id, function () use ($txn, $data) {
            $account = PaymentGatewayAccount::query()
                ->where('gateway', $data['gateway'])
                ->where('is_active', true)
                ->orderByDesc('is_default')
                ->get()
                ->first(fn (PaymentGatewayAccount $a) => $a->isConfigured());

            if (! $account) {
                return response()->json(['message' => 'That payment method is not available.'], 422);
            }

            try {
                $result = $this->gateways->startCheckout($txn, $account);
            } catch (RuntimeException $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            }

            return response()->json(['data' => [
                'flow' => $result['flow'],
                'url' => $result['url'] ?? null,
                'action' => $result['action'] ?? null,
                'fields' => $result['fields'] ?? null,
            ]]);
        });
    }

    /** Browser return from the gateway (GET or POST); reconciles then redirects. */
    public function callback(Request $request, string $token)
    {
        $txn = $this->locate($token);

        $this->tenantContext->runWith($txn->tenant_id, function () use ($txn, $request) {
            try {
                $this->gateways->handleReturn($txn, $request->all());
            } catch (\Throwable $e) {
                report($e);
            }
        });

        $status = $txn->fresh()?->status ?? 'pending';

        return redirect()->away(config('payments.pay_page_base').'/pay/'.$token.'?status='.$status);
    }

    /** Find a transaction by its public token, ignoring tenant scope. */
    private function locate(string $token): PaymentTransaction
    {
        return PaymentTransaction::withoutGlobalScopes()
            ->where('public_token', $token)
            ->firstOrFail();
    }

    /** Active, configured gateways whose currencies cover this transaction. */
    private function availableGateways(PaymentTransaction $txn): array
    {
        return PaymentGatewayAccount::query()
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->get()
            ->filter(fn (PaymentGatewayAccount $a) => $a->isConfigured()
                && in_array($txn->currency, $a->meta()['currencies'] ?? [], true))
            ->groupBy('gateway')
            ->map(function ($group) {
                $a = $group->first();

                return [
                    'gateway' => $a->gateway,
                    'label' => $a->meta()['label'] ?? ucfirst($a->gateway),
                    'is_default' => (bool) $group->firstWhere('is_default', true),
                ];
            })
            ->values()
            ->all();
    }
}
