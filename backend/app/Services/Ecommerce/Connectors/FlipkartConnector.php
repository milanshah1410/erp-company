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
 * Flipkart Seller API connector (https://seller.flipkart.com/api-docs).
 * Uses OAuth 2.0 client-credentials to obtain a bearer token, then queries
 * shipments/orders and updates listing stock.
 */
class FlipkartConnector extends AbstractConnector
{
    private const BASE = 'https://api.flipkart.net/sellers';

    public function platform(): string
    {
        return 'flipkart';
    }

    private function accessToken(): string
    {
        $this->require(['application_id', 'application_secret']);
        $res = Http::withBasicAuth((string) $this->cred('application_id'), (string) $this->cred('application_secret'))
            ->acceptJson()
            ->get('https://api.flipkart.net/oauth-service/oauth/token', [
                'grant_type' => 'client_credentials',
                'scope' => 'Seller_Api',
            ]);
        if ($res->failed()) {
            throw new RuntimeException('Flipkart OAuth failed (HTTP '.$res->status().').');
        }

        return (string) $res->json('access_token');
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl(self::BASE)
            ->withToken($this->accessToken())
            ->acceptJson()->asJson()->timeout(40);
    }

    public function testConnection(): ConnectionResult
    {
        try {
            $token = $this->accessToken();
        } catch (RuntimeException $e) {
            return ConnectionResult::fail($e->getMessage());
        }

        return $token ? ConnectionResult::ok('Authenticated with Flipkart Seller API.')
            : ConnectionResult::fail('Flipkart did not return an access token.');
    }

    public function fetchOrders(?DateTimeInterface $since = null): array
    {
        $since ??= new \DateTimeImmutable('-7 days');
        $res = $this->client()->post('/v3/shipments/filter', [
            'filter' => [
                'type' => 'preDispatch',
                'states' => ['APPROVED', 'PACKING_IN_PROGRESS'],
                'orderDate' => ['from' => $since->format('Y-m-d\TH:i:s') , 'to' => (new \DateTimeImmutable())->format('Y-m-d\TH:i:s')],
            ],
            'pagination' => ['pageSize' => 100],
        ])->throw();

        return array_map(fn (array $s) => $this->mapShipment($s), $res->json('shipments', []));
    }

    private function mapShipment(array $s): NormalizedOrder
    {
        $items = array_map(fn (array $li) => [
            'external_id' => (string) ($li['orderItemId'] ?? ''),
            'sku' => $li['sku'] ?? null,
            'name' => $li['title'] ?? 'Item',
            'quantity' => (float) ($li['quantity'] ?? 1),
            'price' => (float) ($li['priceComponents']['sellingPrice'] ?? 0),
        ], $s['orderItems'] ?? []);

        $total = array_sum(array_map(fn ($i) => $i['price'] * $i['quantity'], $items));

        return new NormalizedOrder(
            externalId: (string) ($s['shipmentId'] ?? $s['orderId'] ?? ''),
            externalNumber: $s['orderId'] ?? null,
            status: $s['status'] ?? ($s['dispatchByDate'] ?? null ? 'pending_dispatch' : null),
            financialStatus: 'paid',
            customerName: $s['deliveryAddress']['addressLine1'] ?? null ? ($s['deliveryAddress']['firstName'] ?? null) : null,
            customerEmail: null,
            customerPhone: $s['deliveryAddress']['contact'] ?? null,
            shippingAddress: array_filter([
                'line1' => $s['deliveryAddress']['addressLine1'] ?? null,
                'line2' => $s['deliveryAddress']['addressLine2'] ?? null,
                'city' => $s['deliveryAddress']['city'] ?? null,
                'state' => $s['deliveryAddress']['state'] ?? null,
                'pincode' => $s['deliveryAddress']['pincode'] ?? null,
            ], fn ($v) => $v !== null && $v !== ''),
            total: (float) $total,
            currency: 'INR',
            placedAt: $s['orderDate'] ?? null,
            items: $items,
            raw: $this->trimRaw($s),
        );
    }

    public function pushInventory(array $levels): array
    {
        $client = $this->client();
        $succeeded = 0;
        $failed = 0;
        foreach ($levels as $level) {
            if (empty($level['sku'])) {
                $failed++;
                continue;
            }
            $res = $client->post("/listings/v3/update/inventory/{$level['sku']}", [
                'locationId' => $this->setting('location_id', 'DEFAULT'),
                'inventory' => (int) round($level['quantity']),
            ]);
            $res->successful() ? $succeeded++ : $failed++;
        }

        return ['succeeded' => $succeeded, 'failed' => $failed, 'message' => null];
    }

    public function fetchListings(): array
    {
        return [];
    }
}
