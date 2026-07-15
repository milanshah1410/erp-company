<?php

declare(strict_types=1);

namespace App\Services\PaymentGateways\Drivers;

use App\Models\PaymentTransaction;
use App\Services\PaymentGateways\AbstractGatewayDriver;

/**
 * CCAvenue — AES-128-CBC encrypted form post. The request is a URL-encoded
 * parameter string encrypted with the working key (md5 → 16-byte key, fixed
 * 0x00..0x0f IV) and hex-encoded into `encRequest`; the browser posts it with
 * the access code. CCAvenue posts back an encrypted `encResp` to our callback,
 * which we decrypt and read `order_status` from.
 *
 * @see https://www.ccavenue.com/ (Integration kit — Non-Seamless / iframe)
 */
class CcavenueGatewayDriver extends AbstractGatewayDriver
{
    /** CCAvenue order_id: alphanumeric, <= 30 chars. */
    private function orderId(PaymentTransaction $txn): string
    {
        return substr(preg_replace('/[^A-Za-z0-9]/', '', $txn->gateway_order_id ?: $txn->public_token) ?? '', 0, 30);
    }

    private function key(): string
    {
        return hex2bin(md5($this->requireCredential('working_key')));
    }

    private function iv(): string
    {
        return implode('', array_map('chr', range(0, 15)));
    }

    private function encrypt(string $plain): string
    {
        return bin2hex(openssl_encrypt($plain, 'AES-128-CBC', $this->key(), OPENSSL_RAW_DATA, $this->iv()));
    }

    private function decrypt(string $hex): string
    {
        return (string) openssl_decrypt(hex2bin($hex), 'AES-128-CBC', $this->key(), OPENSSL_RAW_DATA, $this->iv());
    }

    public function createCheckout(PaymentTransaction $txn, array $context): array
    {
        $orderId = $this->orderId($txn);

        $params = [
            'merchant_id' => $this->requireCredential('merchant_id'),
            'order_id' => $orderId,
            'currency' => $txn->currency,
            'amount' => number_format((float) $txn->amount, 2, '.', ''),
            'redirect_url' => $context['return_url'],
            'cancel_url' => $context['return_url'],
            'language' => 'EN',
            'billing_name' => $context['customer']['name'] ?? '',
            'billing_email' => $context['customer']['email'] ?? '',
            'billing_tel' => $context['customer']['phone'] ?? '',
        ];

        $encRequest = $this->encrypt(http_build_query($params));

        return [
            'flow' => 'form_post',
            'action' => $this->endpoint(),
            'order_id' => $orderId,
            'fields' => [
                'encRequest' => $encRequest,
                'access_code' => $this->requireCredential('access_code'),
            ],
            'meta' => [],
        ];
    }

    public function verifyCallback(PaymentTransaction $txn, array $params): array
    {
        $encResp = $params['encResp'] ?? '';
        if ($encResp === '') {
            return $this->result('failed', null, null, $this->orderId($txn), $params);
        }

        parse_str($this->decrypt($encResp), $decoded);

        $orderStatus = $decoded['order_status'] ?? '';
        $status = match ($orderStatus) {
            'Success' => 'paid',
            'Aborted' => 'cancelled',
            'Failure' => 'failed',
            default => 'pending',
        };

        return $this->result(
            $status,
            $decoded['tracking_id'] ?? null,
            isset($decoded['amount']) ? (float) $decoded['amount'] : (float) $txn->amount,
            $decoded['order_id'] ?? $this->orderId($txn),
            $decoded,
        );
    }

    public function verifyWebhook(string $rawBody, array $headers): array
    {
        // CCAvenue reconciles via the encrypted browser callback; no webhook.
        return ['verified' => false, 'event' => null, 'order_id' => null, 'payment_id' => null, 'amount' => null, 'status' => 'pending', 'raw' => []];
    }
}
