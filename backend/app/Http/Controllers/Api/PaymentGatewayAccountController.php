<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PaymentGatewayAccountResource;
use App\Models\PaymentGatewayAccount;
use App\Services\PaymentGateways\PaymentGatewayManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Manage a tenant's online payment gateways (SRS §16): which providers are
 * enabled, their test/live mode, and their (encrypted) API credentials.
 */
class PaymentGatewayAccountController extends Controller
{
    public function index()
    {
        $accounts = PaymentGatewayAccount::query()->orderBy('gateway')->get();

        return PaymentGatewayAccountResource::collection($accounts);
    }

    /** Catalogue of supported gateways + the credential fields each one needs. */
    public function catalog()
    {
        $catalog = collect(config('payments.gateways'))->map(fn ($g, $key) => [
            'gateway' => $key,
            'label' => $g['label'],
            'currencies' => $g['currencies'],
            'export' => $g['export'] ?? false,
            'flow' => $g['flow'],
            'fields' => collect($g['fields'])->map(fn ($f, $k) => [
                'key' => $k,
                'label' => $f['label'],
                'secret' => $f['secret'] ?? false,
                'required' => $f['required'] ?? false,
            ])->values(),
        ])->values();

        return response()->json(['data' => $catalog]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'gateway' => ['required', 'string', Rule::in(PaymentGatewayManager::gateways())],
            'label' => ['nullable', 'string', 'max:100'],
            'mode' => ['required', Rule::in(['test', 'live'])],
            'is_active' => ['boolean'],
            'is_default' => ['boolean'],
            'credentials' => ['array'],
        ]);

        $credentials = $this->cleanCredentials($data['gateway'], $request->input('credentials', []));

        $account = DB::transaction(function () use ($data, $credentials) {
            $account = PaymentGatewayAccount::create([
                'gateway' => $data['gateway'],
                'label' => $data['label'] ?? null,
                'mode' => $data['mode'],
                'is_active' => $data['is_active'] ?? true,
                'is_default' => $data['is_default'] ?? false,
                'credentials' => $credentials,
            ]);

            $this->syncDefault($account);

            return $account;
        });

        return (new PaymentGatewayAccountResource($account))->response()->setStatusCode(201);
    }

    public function update(Request $request, PaymentGatewayAccount $paymentGateway)
    {
        $data = $request->validate([
            'label' => ['nullable', 'string', 'max:100'],
            'mode' => ['sometimes', Rule::in(['test', 'live'])],
            'is_active' => ['boolean'],
            'is_default' => ['boolean'],
            'credentials' => ['array'],
        ]);

        // Merge only non-empty submitted credentials over the stored ones, so a
        // blank secret field means "keep the existing value".
        if ($request->has('credentials')) {
            $incoming = $this->cleanCredentials($paymentGateway->gateway, $request->input('credentials', []));
            $paymentGateway->credentials = array_merge($paymentGateway->credentials ?? [], $incoming);
        }

        $paymentGateway->fill(array_intersect_key($data, array_flip(['label', 'mode', 'is_active', 'is_default'])));

        DB::transaction(function () use ($paymentGateway) {
            $paymentGateway->save();
            $this->syncDefault($paymentGateway);
        });

        return new PaymentGatewayAccountResource($paymentGateway->fresh());
    }

    public function destroy(PaymentGatewayAccount $paymentGateway)
    {
        $paymentGateway->delete();

        return response()->json(['message' => 'Payment gateway removed.']);
    }

    /** Keep only known, non-empty credential fields for the gateway. */
    private function cleanCredentials(string $gateway, array $input): array
    {
        $allowed = array_keys(config("payments.gateways.{$gateway}.fields", []));

        return collect($input)
            ->only($allowed)
            ->filter(fn ($v) => $v !== null && $v !== '')
            ->map(fn ($v) => (string) $v)
            ->all();
    }

    /** Ensure at most one default account per tenant. */
    private function syncDefault(PaymentGatewayAccount $account): void
    {
        if ($account->is_default) {
            PaymentGatewayAccount::query()
                ->whereKeyNot($account->id)
                ->where('is_default', true)
                ->update(['is_default' => false]);
        }
    }
}
