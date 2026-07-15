<?php

declare(strict_types=1);

namespace App\Services\PaymentGateways\Contracts;

use App\Models\PaymentTransaction;

/**
 * Uniform contract every payment-gateway driver implements so the rest of the
 * app can collect a payment without knowing which provider is behind it.
 *
 * A checkout returns one of two shapes the customer pay page understands:
 *   ['flow' => 'redirect',   'url' => '...']                      // send the browser here
 *   ['flow' => 'form_post',  'action' => '...', 'fields' => [...]] // auto-submit a form here
 * plus 'order_id' (gateway reference) and optional 'meta'.
 *
 * verifyCallback() interprets a browser return; verifyWebhook() interprets a
 * server-to-server notification. Both normalise to:
 *   ['status' => 'paid'|'failed'|'pending', 'payment_id' => ?string,
 *    'amount' => ?float, 'order_id' => ?string, 'raw' => array]
 * verifyWebhook() additionally carries 'verified' => bool for signature checks.
 */
interface PaymentGatewayDriver
{
    /** Create the gateway order/link and return the redirect/form instructions. */
    public function createCheckout(PaymentTransaction $txn, array $context): array;

    /** Interpret the browser-side return (query/post params) from the gateway. */
    public function verifyCallback(PaymentTransaction $txn, array $params): array;

    /** Verify + interpret a server-to-server webhook notification. */
    public function verifyWebhook(string $rawBody, array $headers): array;
}
