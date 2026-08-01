<?php

declare(strict_types=1);

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Models\PlatformPaymentGateway;
use App\Services\RazorpayService;
use App\Support\PlatformGateways;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * The platform's own payment-gateway configuration (Volume 11 §Billing → Payment
 * Gateways). Guarded by `gateways.view` / `gateways.manage`.
 *
 * Three rules the shape of this controller enforces:
 *
 *  - **Secrets are write-only.** A stored secret is never returned — the payload
 *    reports whether one is set, not what it is. Submitting a blank secret keeps
 *    the stored value, so an operator can flip test→live without re-typing keys
 *    out of a password manager.
 *  - **Nothing goes live half-configured.** Activation requires every required
 *    credential for that gateway, checked here rather than discovered by a
 *    customer at checkout.
 *  - **Only Razorpay can actually collect.** The others are storable and
 *    switchable — the credentials are real, the panel is honest that recurring
 *    collection through them is manual until a driver exists.
 */
class BillingGatewayController extends Controller
{
    public function index(): JsonResponse
    {
        $rows = PlatformPaymentGateway::all()->keyBy('gateway');
        $razorpay = app(RazorpayService::class);

        return response()->json([
            'data' => collect(PlatformGateways::options())->map(function (array $meta) use ($rows): array {
                /** @var PlatformPaymentGateway|null $row */
                $row = $rows->get($meta['gateway']);

                return $meta + [
                    'id' => $row?->id,
                    'configured' => (bool) $row?->isConfigured(),
                    'is_active' => (bool) $row?->is_active,
                    'is_default' => (bool) $row?->is_default,
                    'mode' => $row?->mode ?? 'test',
                    'label' => $row?->displayLabel() ?? $meta['label'],
                    'notes' => $row?->notes,
                    'last_verified_at' => $row?->last_verified_at?->toDateTimeString(),
                    // Which credentials are on file. Never the values.
                    'credentials_set' => collect($meta['fields'])
                        ->mapWithKeys(fn (array $f) => [$f['name'] => $row?->credential($f['name']) !== null])
                        ->all(),
                    // Non-secret values are echoed back so the form is editable
                    // without retyping the key id.
                    'credentials' => collect($meta['fields'])
                        ->reject(fn (array $f) => $f['secret'])
                        ->mapWithKeys(fn (array $f) => [$f['name'] => $row?->credential($f['name'])])
                        ->all(),
                ];
            })->values(),
            // What subscription checkout is actually using right now — the panel
            // says so plainly, because .env is still a valid source and an operator
            // debugging a live payment needs to know which wins.
            'active_source' => [
                'razorpay' => [
                    'configured' => $razorpay->configured(),
                    'source' => $razorpay->credentials()['source'],
                    'mode' => $razorpay->mode(),
                ],
            ],
        ]);
    }

    /**
     * Create or update one gateway's configuration. Keyed by gateway, so the panel
     * can submit a card without tracking whether a row exists yet.
     */
    public function upsert(Request $request, string $gateway): JsonResponse
    {
        if (! PlatformGateways::supports($gateway)) {
            return response()->json(['message' => 'Unknown payment gateway.'], 404);
        }

        $data = $request->validate([
            'label' => ['sometimes', 'nullable', 'string', 'max:255'],
            'mode' => ['sometimes', Rule::in(['test', 'live'])],
            'notes' => ['sometimes', 'nullable', 'string', 'max:500'],
            'credentials' => ['sometimes', 'array'],
            'credentials.*' => ['nullable', 'string', 'max:500'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $row = PlatformPaymentGateway::firstOrNew(['gateway' => $gateway]);

        $credentials = $row->credentials ?? [];
        foreach (PlatformGateways::fields($gateway) as $name => $field) {
            if (! array_key_exists($name, $data['credentials'] ?? [])) {
                continue;
            }

            $value = trim((string) ($data['credentials'][$name] ?? ''));

            // A blank submission means "leave the stored secret alone" for a secret
            // field, and "clear it" for a visible one — you can see the visible one
            // is empty, so clearing is what you asked for.
            if ($value === '') {
                if (! $field['secret']) {
                    unset($credentials[$name]);
                }

                continue;
            }

            $credentials[$name] = $value;
        }

        $row->fill([
            'label' => $data['label'] ?? $row->label,
            'mode' => $data['mode'] ?? $row->mode ?? 'test',
            'notes' => $data['notes'] ?? $row->notes,
            'created_by' => $row->created_by ?? $request->user()?->id,
        ]);
        $row->credentials = $credentials;
        $row->save();

        if (array_key_exists('is_active', $data)) {
            $activation = $this->setActive($row, (bool) $data['is_active']);
            if ($activation !== null) {
                return $activation;
            }
        }

        activity('central-billing')->causedBy($request->user())
            ->withProperties([
                'ip' => $request->ip(),
                'gateway' => $gateway,
                'mode' => $row->mode,
                // Names only — never the values.
                'credentials_updated' => array_keys($data['credentials'] ?? []),
            ])
            ->event('gateway-configured')
            ->log('Operator updated the '.PlatformGateways::label($gateway).' gateway configuration');

        return $this->index();
    }

    /** Switch a configured gateway on or off. */
    public function toggle(Request $request, string $gateway): JsonResponse
    {
        $row = $this->configured($gateway);
        if (! $row instanceof PlatformPaymentGateway) {
            return $row;
        }

        $activation = $this->setActive($row, ! $row->is_active);
        if ($activation !== null) {
            return $activation;
        }

        activity('central-billing')->causedBy($request->user())
            ->withProperties(['ip' => $request->ip(), 'gateway' => $row->gateway, 'is_active' => $row->is_active])
            ->event('gateway-toggled')
            ->log('Operator '.($row->is_active ? 'enabled' : 'disabled').' the '.$row->displayLabel().' gateway');

        return $this->index();
    }

    /** Make this the gateway new checkouts start at. */
    public function setDefault(Request $request, string $gateway): JsonResponse
    {
        $row = $this->configured($gateway);
        if (! $row instanceof PlatformPaymentGateway) {
            return $row;
        }

        if (! $row->is_active || ! $row->isConfigured()) {
            return response()->json(['message' => 'Only an active, fully-configured gateway can be the default.'], 422);
        }

        $row->markDefault();

        activity('central-billing')->causedBy($request->user())
            ->withProperties(['ip' => $request->ip(), 'gateway' => $row->gateway])
            ->event('gateway-default-set')
            ->log('Operator made '.$row->displayLabel().' the default payment gateway');

        return $this->index();
    }

    /**
     * Confirm the stored credentials work, by making the cheapest real call the
     * gateway offers. Only Razorpay has a driver; for the rest this reports that
     * plainly instead of pretending to check.
     */
    public function verify(Request $request, string $gateway): JsonResponse
    {
        $row = $this->configured($gateway);
        if (! $row instanceof PlatformPaymentGateway) {
            return $row;
        }

        if ($row->gateway !== 'razorpay') {
            return response()->json([
                'message' => PlatformGateways::label($row->gateway).' has no automated check — credentials are stored but not verified.',
                'verified' => false,
            ], 422);
        }

        if (! $row->isConfigured()) {
            return response()->json(['message' => 'Add the key id and secret first.', 'verified' => false], 422);
        }

        try {
            // A one-item plan listing: authenticated, read-only, and it creates
            // nothing on the merchant account.
            app(RazorpayService::class)->ping();
        } catch (Throwable $e) {
            return response()->json([
                'message' => 'Razorpay rejected those credentials: '.$e->getMessage(),
                'verified' => false,
            ], 422);
        }

        $row->update(['last_verified_at' => now()]);

        activity('central-billing')->causedBy($request->user())
            ->withProperties(['ip' => $request->ip(), 'gateway' => $row->gateway, 'mode' => $row->mode])
            ->event('gateway-verified')
            ->log('Operator verified the '.$row->displayLabel().' credentials');

        return response()->json(['message' => 'Credentials verified.', 'verified' => true]);
    }

    public function destroy(Request $request, string $gateway): JsonResponse
    {
        $row = $this->configured($gateway);
        if (! $row instanceof PlatformPaymentGateway) {
            return $row;
        }

        $name = $row->displayLabel();
        $key = $row->gateway;
        $row->delete();

        activity('central-billing')->causedBy($request->user())
            ->withProperties(['ip' => $request->ip(), 'gateway' => $key])
            ->event('gateway-deleted')
            ->log("Operator removed the {$name} gateway configuration");

        return response()->json(['message' => $name.' configuration removed. Collection falls back to the .env credentials, if any.']);
    }

    /**
     * Resolve a gateway key to its stored row.
     *
     * Every endpoint here is addressed by gateway name — `/gateways/razorpay` — not
     * by row id, so the panel never has to know whether a configuration row exists
     * before it can act on a card. Returns a JsonResponse to send back when there
     * is nothing configured under that name.
     */
    private function configured(string $gateway): PlatformPaymentGateway|JsonResponse
    {
        if (! PlatformGateways::supports($gateway)) {
            return response()->json(['message' => 'Unknown payment gateway.'], 404);
        }

        $row = PlatformPaymentGateway::where('gateway', $gateway)->first();

        return $row ?? response()->json([
            'message' => PlatformGateways::label($gateway).' has not been configured yet.',
        ], 404);
    }

    /**
     * Apply an activation change, refusing to switch on a gateway that is missing
     * a required credential.
     *
     * Returns a JsonResponse to send back on refusal, or null on success — so the
     * three callers share both the check and its message.
     */
    private function setActive(PlatformPaymentGateway $gateway, bool $active): ?JsonResponse
    {
        if ($active && ! $gateway->isConfigured()) {
            $missing = collect(PlatformGateways::fields($gateway->gateway))
                ->filter(fn (array $f, string $name) => $f['required'] && $gateway->credential($name) === null)
                ->map(fn (array $f) => $f['label'])
                ->values();

            return response()->json([
                'message' => 'Add the missing credentials before enabling this gateway: '.$missing->implode(', ').'.',
                'errors' => ['credentials' => [$missing->implode(', ').' required.']],
            ], 422);
        }

        $gateway->update(['is_active' => $active]);

        // The first gateway switched on becomes the default; switching off the
        // default hands the flag to whatever is left, so "default" never points at
        // a disabled row.
        if ($active && ! PlatformPaymentGateway::query()->where('is_default', true)->exists()) {
            $gateway->markDefault();
        } elseif (! $active && $gateway->is_default) {
            $gateway->update(['is_default' => false]);
            PlatformPaymentGateway::query()->where('is_active', true)->first()?->markDefault();
        }

        return null;
    }
}
