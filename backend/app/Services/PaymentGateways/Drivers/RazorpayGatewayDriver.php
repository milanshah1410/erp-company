<?php

declare(strict_types=1);

namespace App\Services\PaymentGateways\Drivers;

use App\Models\PaymentTransaction;
use App\Services\PaymentGateways\AbstractGatewayDriver;

/**
 * Razorpay — Payment Links API. We create a hosted payment link (short_url) and
 * redirect the customer to it; reconciliation happens via the callback
 * signature and/or the `payment_link.paid` webhook (X-Razorpay-Signature).
 *
 * @see https://razorpay.com/docs/payments/payment-links/
 */
class RazorpayGatewayDriver extends AbstractGatewayDriver
{
    public function createCheckout(PaymentTransaction $txn, array $context): array
    {
        $keyId = $this->requireCredential('key_id');
        $keySecret = $this->requireCredential('key_secret');

        $response = $this->http()
            ->withBasicAuth($keyId, $keySecret)
            ->post($this->endpoint().'/payment_links', [
                'amount' => (int) round((float) $txn->amount * 100),
                'currency' => $txn->currency,
                'accept_partial' => false,
                'reference_id' => $txn->public_token,
                'description' => $context['description'] ?? 'Invoice payment',
                'customer' => array_filter([
                    'name' => $context['customer']['name'] ?? null,
                    'email' => $context['customer']['email'] ?? null,
                    'contact' => $context['customer']['phone'] ?? null,
                ]),
                'notify' => ['sms' => false, 'email' => false],
                'reminder_enable' => false,
                'callback_url' => $context['return_url'],
                'callback_method' => 'get',
            ])
            ->throw()
            ->json();

        return [
            'flow' => 'redirect',
            'url' => $response['short_url'],
            'order_id' => $response['id'],
            'meta' => ['payment_link' => $response],
        ];
    }

    public function verifyCallback(PaymentTransaction $txn, array $params): array
    {
        $paymentId = $params['razorpay_payment_id'] ?? null;
        $linkId = $params['razorpay_payment_link_id'] ?? null;
        $refId = $params['razorpay_payment_link_reference_id'] ?? '';
        $linkStatus = $params['razorpay_payment_link_status'] ?? '';
        $signature = $params['razorpay_signature'] ?? '';

        // Razorpay signs "link_id|reference_id|link_status|payment_id" with the key secret.
        $expected = hash_hmac(
            'sha256',
            "{$linkId}|{$refId}|{$linkStatus}|{$paymentId}",
            $this->requireCredential('key_secret'),
        );

        if (! $paymentId || ! hash_equals($expected, $signature)) {
            return $this->result('failed', $paymentId, null, $linkId, $params);
        }

        $status = $linkStatus === 'paid' ? 'paid' : 'pending';

        return $this->result($status, $paymentId, (float) $txn->amount, $linkId, $params);
    }

    public function verifyWebhook(string $rawBody, array $headers): array
    {
        $signature = $this->header($headers, 'x-razorpay-signature');
        $secret = $this->credential('webhook_secret');

        $verified = ! empty($secret) && ! empty($signature)
            && hash_equals(hash_hmac('sha256', $rawBody, $secret), $signature);

        $payload = json_decode($rawBody, true) ?: [];
        $event = $payload['event'] ?? null;
        $link = $payload['payload']['payment_link']['entity'] ?? [];
        $payment = $payload['payload']['payment']['entity'] ?? [];

        $status = match ($event) {
            'payment_link.paid' => 'paid',
            'payment_link.cancelled', 'payment_link.expired' => 'cancelled',
            default => 'pending',
        };

        return [
            'verified' => $verified,
            'event' => $event,
            'order_id' => $link['id'] ?? null,
            'reference_id' => $link['reference_id'] ?? null,
            'payment_id' => $payment['id'] ?? null,
            'amount' => isset($payment['amount']) ? $payment['amount'] / 100 : null,
            'status' => $status,
            'raw' => $payload,
        ];
    }
}
