<?php

declare(strict_types=1);

namespace App\Services\PaymentGateways\Drivers;

use App\Models\PaymentTransaction;
use App\Services\PaymentGateways\AbstractGatewayDriver;
use RuntimeException;

/**
 * Paytm "All-in-One" PG.
 *
 * Two legs: an Initiate Transaction REST call returns a `txnToken`, which is
 * then form-posted to Paytm's hosted payment page — so the checkout flow is
 * form_post even though the first leg is JSON over HTTPS.
 *
 * Every request and response carries a Paytm checksum in `head.signature`. The
 * scheme is theirs, not a standard HMAC: salt the payload, SHA-256 it, append
 * the salt, then AES-128-CBC encrypt the result under the merchant key with a
 * fixed IV. {@see checksum()} and {@see verifyChecksum()} implement it in full,
 * so the driver needs none of Paytm's SDK.
 *
 * As with PhonePe, the browser return is never trusted on its own: the callback
 * is confirmed against the Transaction Status API.
 *
 * @see https://business.paytm.com/docs/api/initiate-transaction-api
 */
class PaytmGatewayDriver extends AbstractGatewayDriver
{
    /** Paytm's fixed initialisation vector for the checksum cipher. */
    private const CHECKSUM_IV = '@@@@&&&&####$$$$';

    /**
     * Order id: alphanumeric plus a few separators, <= 50 chars. Derived from
     * our own token so a retry of the same link reaches the same Paytm order.
     */
    private function orderId(PaymentTransaction $txn): string
    {
        $raw = $txn->gateway_order_id ?: $txn->public_token;

        return substr(preg_replace('/[^A-Za-z0-9_-]/', '', $raw) ?? '', 0, 50);
    }

    public function createCheckout(PaymentTransaction $txn, array $context): array
    {
        $mid = $this->requireCredential('merchant_id');
        $website = $this->requireCredential('website');
        $orderId = $this->orderId($txn);

        $body = [
            'requestType' => 'Payment',
            'mid' => $mid,
            'websiteName' => $website,
            'orderId' => $orderId,
            'callbackUrl' => $context['return_url'],
            'txnAmount' => [
                'value' => number_format((float) $txn->amount, 2, '.', ''),
                'currency' => $txn->currency ?: 'INR',
            ],
            'userInfo' => [
                'custId' => 'CUST'.($txn->customer_id ?? 'GUEST'),
            ],
        ];

        if ($industry = $this->credential('industry_type')) {
            $body['industryTypeId'] = $industry;
        }

        $response = $this->http()
            ->post(
                $this->endpoint()."/theia/api/v1/initiateTransaction?mid={$mid}&orderId={$orderId}",
                ['body' => $body, 'head' => ['signature' => $this->checksum($body)]],
            )
            ->throw()
            ->json();

        $token = $response['body']['txnToken'] ?? null;

        if (empty($token)) {
            $message = $response['body']['resultInfo']['resultMsg'] ?? 'Paytm did not return a transaction token.';

            throw new RuntimeException("Paytm checkout could not be started: {$message}");
        }

        return [
            'flow' => 'form_post',
            'action' => $this->endpoint()."/theia/api/v1/showPaymentPage?mid={$mid}&orderId={$orderId}",
            'fields' => ['mid' => $mid, 'orderId' => $orderId, 'txnToken' => $token],
            'order_id' => $orderId,
            'meta' => ['initiate_response' => $response],
        ];
    }

    /**
     * The browser posts back the transaction result, but a redirect is not
     * proof of payment — confirm against the status API before reconciling.
     */
    public function verifyCallback(PaymentTransaction $txn, array $params): array
    {
        return $this->fetchStatus($txn);
    }

    /**
     * Paytm's server-to-server notification posts the same flat parameter set
     * as the callback, signed with a CHECKSUMHASH over the remaining fields.
     */
    public function verifyWebhook(string $rawBody, array $headers): array
    {
        $params = json_decode($rawBody, true);

        // Paytm posts form-encoded rather than JSON on some integrations.
        if (! is_array($params)) {
            parse_str($rawBody, $params);
        }
        $params = is_array($params) ? $params : [];

        $signature = (string) ($params['CHECKSUMHASH'] ?? '');
        unset($params['CHECKSUMHASH']);

        return [
            'verified' => $signature !== '' && $this->verifyChecksum($params, $signature),
            'event' => $params['STATUS'] ?? null,
            'order_id' => $params['ORDERID'] ?? null,
            'payment_id' => $params['TXNID'] ?? null,
            'amount' => isset($params['TXNAMOUNT']) ? (float) $params['TXNAMOUNT'] : null,
            'status' => $this->mapStatus($params['STATUS'] ?? null),
            'raw' => $params,
        ];
    }

    /** Ask Paytm what actually happened to this order. */
    private function fetchStatus(PaymentTransaction $txn): array
    {
        $mid = $this->requireCredential('merchant_id');
        $orderId = $this->orderId($txn);

        $body = ['mid' => $mid, 'orderId' => $orderId];

        $response = $this->http()
            ->post(
                $this->endpoint().'/v3/order/status',
                ['body' => $body, 'head' => ['signature' => $this->checksum($body)]],
            )
            ->json();

        $data = $response['body'] ?? [];

        return $this->result(
            $this->mapStatus($data['resultInfo']['resultStatus'] ?? null),
            $data['txnId'] ?? null,
            isset($data['txnAmount']) ? (float) $data['txnAmount'] : (float) $txn->amount,
            $orderId,
            $response ?? [],
        );
    }

    /**
     * Paytm reports both a coarse result status (TXN_SUCCESS) on the status API
     * and a bare STATUS on the callback; both spell success the same way.
     */
    private function mapStatus(?string $status): string
    {
        return match ($status) {
            'TXN_SUCCESS', 'SUCCESS' => 'paid',
            'PENDING' => 'pending',
            'TXN_FAILURE', 'FAILURE' => 'failed',
            default => 'pending',
        };
    }

    /**
     * Paytm's checksum over a request body.
     *
     * salt(4) → sha256(json|salt) → append salt → AES-128-CBC under the
     * merchant key with Paytm's fixed IV, base64 out.
     */
    private function checksum(array $body): string
    {
        $salt = substr(bin2hex(random_bytes(4)), 0, 4);

        return $this->encrypt($this->hashWithSalt(json_encode($body), $salt).$salt);
    }

    /**
     * Verify an inbound checksum: decrypt it, recover the trailing salt, and
     * re-derive the hash over the parameters Paytm signed.
     *
     * The flat callback/webhook form signs a `|`-joined string of the parameter
     * values in key order, not JSON — which is why this cannot simply call
     * checksum().
     */
    private function verifyChecksum(array $params, string $signature): bool
    {
        $decrypted = $this->decrypt($signature);

        if (strlen($decrypted) < 5) {
            return false;
        }

        $salt = substr($decrypted, -4);
        ksort($params);
        $paramString = implode('|', array_map(
            static fn ($v) => is_scalar($v) ? (string) $v : '',
            $params,
        ));

        return hash_equals($decrypted, $this->hashWithSalt($paramString, $salt).$salt);
    }

    private function hashWithSalt(string $value, string $salt): string
    {
        return hash('sha256', $value.'|'.$salt);
    }

    private function encrypt(string $input): string
    {
        $encrypted = openssl_encrypt(
            $input,
            'AES-128-CBC',
            $this->requireCredential('merchant_key'),
            0,
            self::CHECKSUM_IV,
        );

        if ($encrypted === false) {
            throw new RuntimeException('Paytm checksum could not be generated.');
        }

        return $encrypted;
    }

    private function decrypt(string $input): string
    {
        $decrypted = openssl_decrypt(
            $input,
            'AES-128-CBC',
            $this->requireCredential('merchant_key'),
            0,
            self::CHECKSUM_IV,
        );

        return $decrypted === false ? '' : $decrypted;
    }
}
