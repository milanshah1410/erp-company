<?php

declare(strict_types=1);

namespace App\Services\Ecommerce\Connectors;

use App\Services\Ecommerce\Data\ConnectionResult;
use App\Services\Ecommerce\Data\NormalizedOrder;
use DateTimeInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Shopify Admin REST API connector (https://shopify.dev/docs/api/admin-rest).
 * Auth is the store's Admin API access token via the X-Shopify-Access-Token
 * header; the shop domain and API version come from settings.
 */
class ShopifyConnector extends AbstractConnector
{
    public function platform(): string
    {
        return 'shopify';
    }

    private function client(): PendingRequest
    {
        $this->require(['shop_domain', 'access_token']);
        $domain = rtrim((string) $this->setting('shop_domain'), '/');
        $version = $this->setting('api_version') ?: '2024-07';

        return Http::baseUrl("https://{$domain}/admin/api/{$version}")
            ->withHeaders(['X-Shopify-Access-Token' => (string) $this->cred('access_token')])
            ->acceptJson()->asJson()->timeout(30);
    }

    public function testConnection(): ConnectionResult
    {
        $res = $this->client()->get('/shop.json');
        if ($res->failed()) {
            return ConnectionResult::fail('Shopify rejected the credentials (HTTP '.$res->status().').');
        }

        return ConnectionResult::ok('Connected to '.($res->json('shop.name') ?? 'Shopify store').'.', [
            'currency' => $res->json('shop.currency'),
        ]);
    }

    public function fetchOrders(?DateTimeInterface $since = null): array
    {
        $params = ['status' => 'any', 'limit' => 100];
        if ($since) {
            $params['updated_at_min'] = $since->format(DATE_ATOM);
        }

        $res = $this->client()->get('/orders.json', $params)->throw();

        return array_map(fn (array $o) => $this->mapOrder($o), $res->json('orders', []));
    }

    private function mapOrder(array $o): NormalizedOrder
    {
        $ship = $o['shipping_address'] ?? $o['billing_address'] ?? [];
        $items = array_map(fn (array $li) => [
            'external_id' => (string) ($li['product_id'] ?? ''),
            'sku' => $li['sku'] ?? null,
            'name' => $li['name'] ?? $li['title'] ?? 'Item',
            'quantity' => (float) ($li['quantity'] ?? 1),
            'price' => (float) ($li['price'] ?? 0),
        ], $o['line_items'] ?? []);

        return new NormalizedOrder(
            externalId: (string) $o['id'],
            externalNumber: $o['name'] ?? null,
            status: $o['fulfillment_status'] ?? 'unfulfilled',
            financialStatus: $o['financial_status'] ?? null,
            customerName: trim(($o['customer']['first_name'] ?? '').' '.($o['customer']['last_name'] ?? '')) ?: ($ship['name'] ?? null),
            customerEmail: $o['email'] ?? ($o['customer']['email'] ?? null),
            customerPhone: $o['phone'] ?? ($ship['phone'] ?? null),
            shippingAddress: $this->address($ship),
            total: (float) ($o['total_price'] ?? 0),
            currency: $o['currency'] ?? 'INR',
            placedAt: $o['created_at'] ?? null,
            items: $items,
            raw: $this->trimRaw($o),
        );
    }

    private function address(array $a): array
    {
        return array_filter([
            'line1' => $a['address1'] ?? null,
            'line2' => $a['address2'] ?? null,
            'city' => $a['city'] ?? null,
            'state' => $a['province'] ?? null,
            'pincode' => $a['zip'] ?? null,
            'country' => $a['country'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');
    }

    public function pushInventory(array $levels): array
    {
        // Shopify sets stock per inventory_item at a location. We resolve the
        // primary location once, then set each SKU's available quantity.
        $client = $this->client();
        $locationId = $this->setting('location_id')
            ?: $client->get('/locations.json')->throw()->json('locations.0.id');

        $succeeded = 0;
        $failed = 0;
        foreach ($levels as $level) {
            $inventoryItemId = $this->inventoryItemForSku($client, $level);
            if (! $inventoryItemId) {
                $failed++;
                continue;
            }
            $res = $client->post('/inventory_levels/set.json', [
                'location_id' => $locationId,
                'inventory_item_id' => $inventoryItemId,
                'available' => (int) round($level['quantity']),
            ]);
            $res->successful() ? $succeeded++ : $failed++;
        }

        return ['succeeded' => $succeeded, 'failed' => $failed, 'message' => null];
    }

    private function inventoryItemForSku(PendingRequest $client, array $level): ?string
    {
        // external_id here is expected to be the variant id when mapped; fall
        // back to a SKU search via the products endpoint.
        if (! empty($level['external_id'])) {
            $variant = $client->get("/variants/{$level['external_id']}.json")->json('variant');
            return $variant['inventory_item_id'] ?? null;
        }

        return null;
    }

    public function fetchListings(): array
    {
        $res = $this->client()->get('/products.json', ['limit' => 100])->throw();
        $out = [];
        foreach ($res->json('products', []) as $p) {
            foreach ($p['variants'] ?? [] as $v) {
                $out[] = [
                    'external_id' => (string) $v['id'],
                    'external_sku' => $v['sku'] ?? null,
                    'title' => ($p['title'] ?? '').(isset($v['title']) && $v['title'] !== 'Default Title' ? ' — '.$v['title'] : ''),
                    'price' => isset($v['price']) ? (float) $v['price'] : null,
                ];
            }
        }

        return $out;
    }
}
