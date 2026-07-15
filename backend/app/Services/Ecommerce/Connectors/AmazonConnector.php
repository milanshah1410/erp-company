<?php

declare(strict_types=1);

namespace App\Services\Ecommerce\Connectors;

use App\Services\Ecommerce\Data\ConnectionResult;
use App\Services\Ecommerce\Data\NormalizedOrder;
use DateTimeInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Amazon Selling Partner API connector (https://developer-docs.amazon.com/sp-api).
 * Uses Login with Amazon (LWA) to exchange the seller refresh token for a
 * short-lived access token, then calls the regional SP-API host. Requires an
 * approved SP-API application.
 */
class AmazonConnector extends AbstractConnector
{
    private const HOSTS = [
        'na' => 'https://sellingpartnerapi-na.amazon.com',
        'eu' => 'https://sellingpartnerapi-eu.amazon.com',
        'fe' => 'https://sellingpartnerapi-fe.amazon.com',
    ];

    public function platform(): string
    {
        return 'amazon';
    }

    private function accessToken(): string
    {
        $this->require(['lwa_client_id', 'lwa_client_secret', 'refresh_token']);
        $res = Http::asForm()->acceptJson()->post('https://api.amazon.com/auth/o2/token', [
            'grant_type' => 'refresh_token',
            'refresh_token' => (string) $this->cred('refresh_token'),
            'client_id' => (string) $this->cred('lwa_client_id'),
            'client_secret' => (string) $this->cred('lwa_client_secret'),
        ]);
        if ($res->failed()) {
            throw new RuntimeException('Amazon LWA token exchange failed (HTTP '.$res->status().').');
        }

        return (string) $res->json('access_token');
    }

    private function client(): PendingRequest
    {
        $this->require(['region', 'marketplace_id']);
        $host = self::HOSTS[$this->setting('region')] ?? null;
        if (! $host) {
            throw new RuntimeException('Unknown Amazon region "'.$this->setting('region').'" (expected na, eu or fe).');
        }

        return Http::baseUrl($host)
            ->withHeaders(['x-amz-access-token' => $this->accessToken()])
            ->acceptJson()->asJson()->timeout(40);
    }

    public function testConnection(): ConnectionResult
    {
        $res = $this->client()->get('/sellers/v1/marketplaceParticipations');
        if ($res->failed()) {
            return ConnectionResult::fail('Amazon SP-API rejected the request (HTTP '.$res->status().').');
        }

        return ConnectionResult::ok('Connected to Amazon SP-API.');
    }

    public function fetchOrders(?DateTimeInterface $since = null): array
    {
        $since ??= new \DateTimeImmutable('-7 days');
        $res = $this->client()->get('/orders/v0/orders', [
            'MarketplaceIds' => (string) $this->setting('marketplace_id'),
            'CreatedAfter' => $since->format(DATE_ATOM),
            'MaxResultsPerPage' => 100,
        ])->throw();

        $orders = $res->json('payload.Orders', []);

        return array_map(fn (array $o) => $this->mapOrder($o), $orders);
    }

    private function mapOrder(array $o): NormalizedOrder
    {
        $ship = $o['ShippingAddress'] ?? [];
        $amount = $o['OrderTotal']['Amount'] ?? 0;

        return new NormalizedOrder(
            externalId: (string) $o['AmazonOrderId'],
            externalNumber: $o['SellerOrderId'] ?? $o['AmazonOrderId'],
            status: $o['OrderStatus'] ?? null,
            financialStatus: ($o['OrderStatus'] ?? '') === 'Shipped' ? 'paid' : ($o['PaymentMethod'] ?? null),
            customerName: $ship['Name'] ?? ($o['BuyerInfo']['BuyerName'] ?? null),
            customerEmail: $o['BuyerInfo']['BuyerEmail'] ?? null,
            customerPhone: $ship['Phone'] ?? null,
            shippingAddress: array_filter([
                'line1' => $ship['AddressLine1'] ?? null,
                'line2' => $ship['AddressLine2'] ?? null,
                'city' => $ship['City'] ?? null,
                'state' => $ship['StateOrRegion'] ?? null,
                'pincode' => $ship['PostalCode'] ?? null,
                'country' => $ship['CountryCode'] ?? null,
            ], fn ($v) => $v !== null && $v !== ''),
            total: (float) $amount,
            currency: $o['OrderTotal']['CurrencyCode'] ?? 'INR',
            placedAt: $o['PurchaseDate'] ?? null,
            items: [], // Line items require a separate /orders/v0/orders/{id}/orderItems call.
            raw: $this->trimRaw($o),
        );
    }

    public function pushInventory(array $levels): array
    {
        // SP-API stock updates go through the Listings Items API (PATCH the
        // fulfillment_availability of each seller SKU).
        $sellerId = $this->setting('seller_id');
        if (! $sellerId) {
            return ['succeeded' => 0, 'failed' => count($levels), 'message' => 'Amazon seller_id is required to push inventory.'];
        }

        $client = $this->client();
        $marketplace = (string) $this->setting('marketplace_id');
        $succeeded = 0;
        $failed = 0;
        foreach ($levels as $level) {
            if (empty($level['sku'])) {
                $failed++;
                continue;
            }
            $sku = rawurlencode((string) $level['sku']);
            $res = $client->patch("/listings/2021-08-01/items/{$sellerId}/{$sku}?marketplaceIds={$marketplace}", [
                'productType' => 'PRODUCT',
                'patches' => [[
                    'op' => 'replace',
                    'path' => '/attributes/fulfillment_availability',
                    'value' => [['fulfillment_channel_code' => 'DEFAULT', 'quantity' => (int) round($level['quantity'])]],
                ]],
            ]);
            $res->successful() ? $succeeded++ : $failed++;
        }

        return ['succeeded' => $succeeded, 'failed' => $failed, 'message' => null];
    }

    public function fetchListings(): array
    {
        // SP-API has no simple "list all my listings" endpoint; listings are
        // mapped by SKU manually.
        return [];
    }
}
