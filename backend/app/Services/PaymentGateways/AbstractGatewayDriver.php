<?php

declare(strict_types=1);

namespace App\Services\PaymentGateways;

use App\Models\PaymentGatewayAccount;
use App\Services\PaymentGateways\Contracts\PaymentGatewayDriver;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Shared plumbing for the concrete gateway drivers: credential access, the
 * test/live base endpoint, a preconfigured HTTP client, and small helpers for
 * normalising results.
 */
abstract class AbstractGatewayDriver implements PaymentGatewayDriver
{
    public function __construct(protected PaymentGatewayAccount $account) {}

    protected function credential(string $key): ?string
    {
        return $this->account->credential($key);
    }

    protected function requireCredential(string $key): string
    {
        $value = $this->credential($key);
        if (empty($value)) {
            throw new RuntimeException("{$this->account->gateway} is missing the required credential: {$key}.");
        }

        return (string) $value;
    }

    protected function endpoint(): string
    {
        $base = $this->account->endpoint();
        if (empty($base)) {
            throw new RuntimeException("No API endpoint configured for {$this->account->gateway} ({$this->account->mode}).");
        }

        return rtrim($base, '/');
    }

    protected function isLive(): bool
    {
        return $this->account->mode === 'live';
    }

    protected function http(): PendingRequest
    {
        return Http::acceptJson()->asJson()->timeout(30);
    }

    /** Case-insensitive lookup of a (possibly array-wrapped) request header. */
    protected function header(array $headers, string $name): ?string
    {
        $value = $headers[$name] ?? $headers[strtolower($name)] ?? $headers[strtoupper($name)] ?? null;

        return is_array($value) ? ($value[0] ?? null) : $value;
    }

    /** A normalised callback/webhook result the reconciler understands. */
    protected function result(string $status, ?string $paymentId = null, ?float $amount = null, ?string $orderId = null, array $raw = []): array
    {
        return [
            'status' => $status,
            'payment_id' => $paymentId,
            'amount' => $amount,
            'order_id' => $orderId,
            'raw' => $raw,
        ];
    }
}
