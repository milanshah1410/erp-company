<?php

declare(strict_types=1);

namespace App\Services\PaymentGateways\Drivers;

use App\Models\PaymentTransaction;
use App\Services\PaymentGateways\AbstractGatewayDriver;

/**
 * PayU (India) — classic `_payment` form post. The customer's browser submits a
 * signed form to PayU; PayU posts the result back to our callback (surl/furl),
 * which we verify with the reverse SHA-512 hash. No webhook is used.
 *
 * @see https://devguide.payu.in/
 */
class PayuGatewayDriver extends AbstractGatewayDriver
{
    /** PayU txnid: alphanumeric, <= 25 chars. */
    private function txnId(PaymentTransaction $txn): string
    {
        return substr(preg_replace('/[^A-Za-z0-9]/', '', $txn->gateway_order_id ?: $txn->public_token) ?? '', 0, 25);
    }

    public function createCheckout(PaymentTransaction $txn, array $context): array
    {
        $key = $this->requireCredential('merchant_key');
        $salt = $this->requireCredential('merchant_salt');

        $txnid = $this->txnId($txn);
        $amount = number_format((float) $txn->amount, 2, '.', '');
        $productinfo = $context['description'] ?? 'Invoice payment';
        $firstname = $context['customer']['name'] ?? 'Customer';
        $email = $context['customer']['email'] ?? 'customer@example.com';
        $phone = $context['customer']['phone'] ?? '9999999999';

        // key|txnid|amount|productinfo|firstname|email|udf1..udf5||||||salt
        $parts = [$key, $txnid, $amount, $productinfo, $firstname, $email, '', '', '', '', '', '', '', '', '', ''];
        $hash = strtolower(hash('sha512', implode('|', $parts).'|'.$salt));

        return [
            'flow' => 'form_post',
            'action' => $this->endpoint(),
            'order_id' => $txnid,
            'fields' => [
                'key' => $key,
                'txnid' => $txnid,
                'amount' => $amount,
                'productinfo' => $productinfo,
                'firstname' => $firstname,
                'email' => $email,
                'phone' => $phone,
                'surl' => $context['return_url'],
                'furl' => $context['return_url'],
                'hash' => $hash,
            ],
            'meta' => [],
        ];
    }

    public function verifyCallback(PaymentTransaction $txn, array $params): array
    {
        $key = $this->requireCredential('merchant_key');
        $salt = $this->requireCredential('merchant_salt');

        $status = $params['status'] ?? '';
        $amount = $params['amount'] ?? '';
        $txnid = $params['txnid'] ?? '';
        $productinfo = $params['productinfo'] ?? '';
        $firstname = $params['firstname'] ?? '';
        $email = $params['email'] ?? '';
        $received = $params['hash'] ?? '';

        // Reverse: salt|status||||||udf5|udf4|udf3|udf2|udf1|email|firstname|productinfo|amount|txnid|key
        $parts = [$salt, $status, '', '', '', '', '', '', '', '', '', '', $email, $firstname, $productinfo, $amount, $txnid, $key];
        $expected = strtolower(hash('sha512', implode('|', $parts)));

        if (! hash_equals($expected, (string) $received)) {
            return $this->result('failed', $params['mihpayid'] ?? null, null, $txnid, $params);
        }

        $mapped = $status === 'success' ? 'paid' : ($status === 'failure' ? 'failed' : 'pending');

        return $this->result($mapped, $params['mihpayid'] ?? null, (float) $amount, $txnid, $params);
    }

    public function verifyWebhook(string $rawBody, array $headers): array
    {
        // PayU reconciliation is via the signed browser callback; no webhook.
        return ['verified' => false, 'event' => null, 'order_id' => null, 'payment_id' => null, 'amount' => null, 'status' => 'pending', 'raw' => []];
    }
}
