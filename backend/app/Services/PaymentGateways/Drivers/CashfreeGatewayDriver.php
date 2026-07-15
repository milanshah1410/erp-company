<?php

declare(strict_types=1);

namespace App\Services\PaymentGateways\Drivers;

use App\Models\PaymentTransaction;
use App\Services\PaymentGateways\AbstractGatewayDriver;

/**
 * Cashfree Payments — Payment Links API (PG v2023-08-01). We create a hosted
 * link (link_url) and redirect to it; on return / webhook we confirm by
 * fetching the link's status so we never trust unsigned redirect params.
 *
 * @see https://docs.cashfree.com/reference/pg-new-apis-endpoint
 */
class CashfreeGatewayDriver extends AbstractGatewayDriver
{
    private function apiHeaders(): array
    {
        return [
            'x-client-id' => $this->requireCredential('app_id'),
            'x-client-secret' => $this->requireCredential('secret_key'),
            'x-api-version' => $this->account->meta()['api_version'] ?? '2023-08-01',
        ];
    }

    public function createCheckout(PaymentTransaction $txn, array $context): array
    {
        // Cashfree link_id: alphanumeric/underscore/hyphen, <= 50 chars.
        $linkId = substr($txn->public_token, 0, 45);

        $response = $this->http()
            ->withHeaders($this->apiHeaders())
            ->post($this->endpoint().'/links', [
                'link_id' => $linkId,
                'link_amount' => round((float) $txn->amount, 2),
                'link_currency' => $txn->currency,
                'link_purpose' => $context['description'] ?? 'Invoice payment',
                'customer_details' => array_filter([
                    'customer_name' => $context['customer']['name'] ?? null,
                    'customer_email' => $context['customer']['email'] ?? null,
                    'customer_phone' => $context['customer']['phone'] ?? '9999999999',
                ]),
                'link_notify' => ['send_email' => false, 'send_sms' => false],
                'link_meta' => ['return_url' => $context['return_url'], 'notify_url' => $context['webhook_url']],
            ])
            ->throw()
            ->json();

        return [
            'flow' => 'redirect',
            'url' => $response['link_url'],
            'order_id' => $linkId,
            'meta' => ['link' => $response],
        ];
    }

    public function verifyCallback(PaymentTransaction $txn, array $params): array
    {
        return $this->fetchStatus($txn->gateway_order_id ?? $txn->public_token, (float) $txn->amount);
    }

    public function verifyWebhook(string $rawBody, array $headers): array
    {
        $signature = $this->header($headers, 'x-webhook-signature');
        $timestamp = $this->header($headers, 'x-webhook-timestamp');
        $secret = $this->credential('secret_key');

        $verified = ! empty($secret) && ! empty($signature) && ! empty($timestamp)
            && hash_equals(base64_encode(hash_hmac('sha256', $timestamp.$rawBody, $secret, true)), $signature);

        $payload = json_decode($rawBody, true) ?: [];
        $data = $payload['data'] ?? [];
        $linkStatus = $data['link_status'] ?? ($data['order']['order_status'] ?? null);
        $linkId = $data['link_id'] ?? ($data['order']['order_id'] ?? null);

        $status = match ($linkStatus) {
            'PAID', 'SUCCESS' => 'paid',
            'CANCELLED', 'EXPIRED' => 'cancelled',
            'FAILED' => 'failed',
            default => 'pending',
        };

        return [
            'verified' => $verified,
            'event' => $payload['type'] ?? null,
            'order_id' => $linkId,
            'payment_id' => $data['order']['transaction_id'] ?? ($data['link_id'] ?? null),
            'amount' => isset($data['link_amount_paid']) ? (float) $data['link_amount_paid'] : null,
            'status' => $status,
            'raw' => $payload,
        ];
    }

    /** Fetch a link's current status from Cashfree and normalise it. */
    private function fetchStatus(string $linkId, float $amount): array
    {
        $response = $this->http()
            ->withHeaders($this->apiHeaders())
            ->get($this->endpoint()."/links/{$linkId}")
            ->json();

        $linkStatus = $response['link_status'] ?? null;
        $status = match ($linkStatus) {
            'PAID' => 'paid',
            'CANCELLED', 'EXPIRED' => 'cancelled',
            default => 'pending',
        };

        return $this->result($status, $linkId, $amount, $linkId, $response ?? []);
    }
}
