<?php

declare(strict_types=1);

namespace App\Services\Ecommerce\Connectors;

use App\Models\ChannelIntegration;
use App\Services\Ecommerce\Contracts\EcommerceConnector;
use RuntimeException;

/**
 * Shared plumbing for the concrete channel connectors: credential/setting
 * access and a guard that throws a clear "not configured" error (mirroring the
 * RazorpayService pattern) so unconfigured integrations fail loudly rather than
 * making half-authenticated calls.
 */
abstract class AbstractConnector implements EcommerceConnector
{
    public function __construct(protected readonly ChannelIntegration $integration) {}

    protected function cred(string $key): ?string
    {
        return $this->integration->credential($key);
    }

    protected function setting(string $key, mixed $default = null): mixed
    {
        return $this->integration->setting($key, $default);
    }

    /**
     * Ensure the given credential/setting keys are all present, else throw with
     * a message naming the connector and the missing field.
     */
    protected function require(array $keys): void
    {
        foreach ($keys as $key) {
            if (empty($this->cred($key)) && empty($this->setting($key))) {
                throw new RuntimeException(sprintf(
                    '%s is not fully configured: missing "%s".',
                    ucfirst($this->platform()),
                    $key,
                ));
            }
        }
    }

    /** Trim to a bounded slice of the raw payload so audit JSON stays small. */
    protected function trimRaw(array $payload): array
    {
        $json = json_encode($payload);
        if ($json !== false && strlen($json) <= 8000) {
            return $payload;
        }

        return ['_truncated' => true, 'id' => $payload['id'] ?? $payload['order_id'] ?? null];
    }
}
