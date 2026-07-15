<?php

declare(strict_types=1);

namespace App\Services\PaymentGateways\Drivers;

use App\Models\PaymentTransaction;
use App\Services\PaymentGateways\AbstractGatewayDriver;

/**
 * Stripe (export / multi-currency) — Checkout Sessions. We create a hosted
 * Checkout session and redirect to its URL; reconciliation is via the
 * `checkout.session.completed` webhook (Stripe-Signature) and/or fetching the
 * session on the browser return. Amounts are in the currency's minor unit.
 *
 * @see https://stripe.com/docs/api/checkout/sessions
 */
class StripeGatewayDriver extends AbstractGatewayDriver
{
    private function bearer(): string
    {
        return $this->requireCredential('secret_key');
    }

    public function createCheckout(PaymentTransaction $txn, array $context): array
    {
        $return = $context['return_url'];
        $glue = str_contains($return, '?') ? '&' : '?';

        $response = $this->http()
            ->asForm()
            ->withToken($this->bearer())
            ->post($this->endpoint().'/checkout/sessions', [
                'mode' => 'payment',
                'success_url' => $return.$glue.'session_id={CHECKOUT_SESSION_ID}',
                'cancel_url' => $return.$glue.'cancelled=1',
                'client_reference_id' => $txn->public_token,
                'customer_email' => $context['customer']['email'] ?? null,
                'line_items' => [[
                    'quantity' => 1,
                    'price_data' => [
                        'currency' => strtolower($txn->currency),
                        'unit_amount' => (int) round((float) $txn->amount * 100),
                        'product_data' => ['name' => $context['description'] ?? 'Invoice payment'],
                    ],
                ]],
            ])
            ->throw()
            ->json();

        return [
            'flow' => 'redirect',
            'url' => $response['url'],
            'order_id' => $response['id'],
            'meta' => ['session' => ['id' => $response['id'] ?? null]],
        ];
    }

    public function verifyCallback(PaymentTransaction $txn, array $params): array
    {
        $sessionId = $params['session_id'] ?? $txn->gateway_order_id;
        if (! $sessionId) {
            return $this->result('failed', null, null, null, $params);
        }

        $session = $this->http()
            ->withToken($this->bearer())
            ->get($this->endpoint().'/checkout/sessions/'.$sessionId)
            ->json();

        $status = ($session['payment_status'] ?? null) === 'paid' ? 'paid' : 'pending';

        return $this->result(
            $status,
            $session['payment_intent'] ?? null,
            isset($session['amount_total']) ? $session['amount_total'] / 100 : (float) $txn->amount,
            $sessionId,
            $session ?? [],
        );
    }

    public function verifyWebhook(string $rawBody, array $headers): array
    {
        $verified = $this->verifySignature($rawBody, $this->header($headers, 'stripe-signature'));

        $payload = json_decode($rawBody, true) ?: [];
        $event = $payload['type'] ?? null;
        $object = $payload['data']['object'] ?? [];

        $status = match ($event) {
            'checkout.session.completed', 'checkout.session.async_payment_succeeded' =>
                ($object['payment_status'] ?? null) === 'paid' ? 'paid' : 'pending',
            'checkout.session.expired', 'checkout.session.async_payment_failed' => 'failed',
            default => 'pending',
        };

        return [
            'verified' => $verified,
            'event' => $event,
            'order_id' => $object['id'] ?? null,
            'reference_id' => $object['client_reference_id'] ?? null,
            'payment_id' => $object['payment_intent'] ?? null,
            'amount' => isset($object['amount_total']) ? $object['amount_total'] / 100 : null,
            'status' => $status,
            'raw' => $payload,
        ];
    }

    /** Verify Stripe's `t=...,v1=...` signature header against the raw body. */
    private function verifySignature(string $rawBody, ?string $header): bool
    {
        $secret = $this->credential('webhook_secret');
        if (empty($secret) || empty($header)) {
            return false;
        }

        $parts = [];
        foreach (explode(',', $header) as $pair) {
            [$k, $v] = array_pad(explode('=', $pair, 2), 2, '');
            $parts[$k][] = $v;
        }

        $timestamp = $parts['t'][0] ?? null;
        if (! $timestamp) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret);

        foreach ($parts['v1'] ?? [] as $candidate) {
            if (hash_equals($expected, $candidate)) {
                return true;
            }
        }

        return false;
    }
}
