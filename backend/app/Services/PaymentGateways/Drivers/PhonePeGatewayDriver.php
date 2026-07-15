<?php

declare(strict_types=1);

namespace App\Services\PaymentGateways\Drivers;

use App\Models\PaymentTransaction;
use App\Services\PaymentGateways\AbstractGatewayDriver;

/**
 * PhonePe PG. Requests carry a base64-encoded JSON payload and an X-VERIFY
 * checksum: SHA256(base64Payload + apiPath + saltKey) + "###" + saltIndex. The
 * hosted PAY_PAGE returns a redirect URL; status is confirmed by calling the
 * status API (never trusting the browser redirect alone).
 *
 * @see https://developer.phonepe.com/v1/reference/pay-api
 */
class PhonePeGatewayDriver extends AbstractGatewayDriver
{
    /** Merchant txn id: alphanumeric, <= 35 chars — derived from our token. */
    private function merchantTxnId(PaymentTransaction $txn): string
    {
        return substr(preg_replace('/[^A-Za-z0-9]/', '', $txn->gateway_order_id ?: $txn->public_token) ?? '', 0, 35);
    }

    private function checksum(string $payloadForHash, string $path): string
    {
        $salt = $this->requireCredential('salt_key');
        $index = $this->requireCredential('salt_index');

        return hash('sha256', $payloadForHash.$path.$salt).'###'.$index;
    }

    public function createCheckout(PaymentTransaction $txn, array $context): array
    {
        $mid = $this->requireCredential('merchant_id');
        $mtid = $this->merchantTxnId($txn);

        $payload = [
            'merchantId' => $mid,
            'merchantTransactionId' => $mtid,
            'merchantUserId' => 'CUST'.($txn->customer_id ?? 'GUEST'),
            'amount' => (int) round((float) $txn->amount * 100),
            'redirectUrl' => $context['return_url'],
            'redirectMode' => 'REDIRECT',
            'callbackUrl' => $context['webhook_url'],
            'paymentInstrument' => ['type' => 'PAY_PAGE'],
        ];

        $base64 = base64_encode(json_encode($payload));
        $xVerify = $this->checksum($base64, '/pg/v1/pay');

        $response = $this->http()
            ->withHeaders(['X-VERIFY' => $xVerify])
            ->post($this->endpoint().'/pg/v1/pay', ['request' => $base64])
            ->throw()
            ->json();

        $url = $response['data']['instrumentResponse']['redirectInfo']['url'] ?? null;

        return [
            'flow' => 'redirect',
            'url' => $url,
            'order_id' => $mtid,
            'meta' => ['pay_response' => $response],
        ];
    }

    public function verifyCallback(PaymentTransaction $txn, array $params): array
    {
        return $this->fetchStatus($txn);
    }

    public function verifyWebhook(string $rawBody, array $headers): array
    {
        $signature = $this->header($headers, 'x-verify');
        $body = json_decode($rawBody, true) ?: [];
        $base64 = $body['response'] ?? '';

        $expected = $this->checksum($base64, '');
        $verified = ! empty($signature) && hash_equals($expected, $signature);

        $decoded = json_decode(base64_decode($base64) ?: '', true) ?: [];
        $code = $decoded['code'] ?? null;
        $data = $decoded['data'] ?? [];

        return [
            'verified' => $verified,
            'event' => $code,
            'order_id' => $data['merchantTransactionId'] ?? null,
            'payment_id' => $data['transactionId'] ?? null,
            'amount' => isset($data['amount']) ? $data['amount'] / 100 : null,
            'status' => $this->mapCode($code),
            'raw' => $decoded,
        ];
    }

    /** Query the PhonePe status API for a definitive result. */
    private function fetchStatus(PaymentTransaction $txn): array
    {
        $mid = $this->requireCredential('merchant_id');
        $mtid = $this->merchantTxnId($txn);
        $path = "/pg/v1/status/{$mid}/{$mtid}";

        $response = $this->http()
            ->withHeaders(['X-VERIFY' => $this->checksum('', $path), 'X-MERCHANT-ID' => $mid])
            ->get($this->endpoint().$path)
            ->json();

        $code = $response['code'] ?? null;
        $data = $response['data'] ?? [];

        return $this->result(
            $this->mapCode($code),
            $data['transactionId'] ?? null,
            isset($data['amount']) ? $data['amount'] / 100 : (float) $txn->amount,
            $mtid,
            $response ?? [],
        );
    }

    private function mapCode(?string $code): string
    {
        return match ($code) {
            'PAYMENT_SUCCESS' => 'paid',
            'PAYMENT_PENDING' => 'pending',
            'PAYMENT_DECLINED', 'PAYMENT_ERROR', 'INTERNAL_SERVER_ERROR' => 'failed',
            default => 'pending',
        };
    }
}
