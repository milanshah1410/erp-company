<?php

declare(strict_types=1);

namespace App\Services\Ecommerce\Connectors;

use App\Services\Ecommerce\Data\ConnectionResult;
use App\Services\Ecommerce\Data\NormalizedOrder;
use DateTimeInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * WooCommerce REST API v3 connector (https://woocommerce.github.io/woocommerce-rest-api-docs).
 * Auth is HTTP Basic with the consumer key/secret over HTTPS.
 */
class WooCommerceConnector extends AbstractConnector
{
    public function platform(): string
    {
        return 'woocommerce';
    }

    private function client(): PendingRequest
    {
        $this->require(['store_url', 'consumer_key', 'consumer_secret']);
        $base = rtrim((string) $this->setting('store_url'), '/').'/wp-json/wc/v3';

        return Http::baseUrl($base)
            ->withBasicAuth((string) $this->cred('consumer_key'), (string) $this->cred('consumer_secret'))
            ->acceptJson()->asJson()->timeout(30);
    }

    public function testConnection(): ConnectionResult
    {
        $res = $this->client()->get('/system_status');
        if ($res->failed()) {
            return ConnectionResult::fail('WooCommerce rejected the credentials (HTTP '.$res->status().').');
        }

        return ConnectionResult::ok('Connected to WooCommerce store.', [
            'currency' => $res->json('settings.currency'),
        ]);
    }

    public function fetchOrders(?DateTimeInterface $since = null): array
    {
        $params = ['per_page' => 100, 'orderby' => 'date', 'order' => 'desc'];
        if ($since) {
            $params['after'] = $since->format(DATE_ATOM);
        }

        $res = $this->client()->get('/orders', $params)->throw();

        return array_map(fn (array $o) => $this->mapOrder($o), $res->json());
    }

    private function mapOrder(array $o): NormalizedOrder
    {
        $ship = $o['shipping'] ?? [];
        $bill = $o['billing'] ?? [];
        $items = array_map(fn (array $li) => [
            'external_id' => (string) ($li['product_id'] ?? ''),
            'sku' => $li['sku'] ?? null,
            'name' => $li['name'] ?? 'Item',
            'quantity' => (float) ($li['quantity'] ?? 1),
            'price' => (float) ($li['price'] ?? 0),
        ], $o['line_items'] ?? []);

        $name = trim(($ship['first_name'] ?? $bill['first_name'] ?? '').' '.($ship['last_name'] ?? $bill['last_name'] ?? ''));

        return new NormalizedOrder(
            externalId: (string) $o['id'],
            externalNumber: $o['number'] ?? null,
            status: $o['status'] ?? null,
            financialStatus: ($o['date_paid'] ?? null) ? 'paid' : 'pending',
            customerName: $name ?: null,
            customerEmail: $bill['email'] ?? null,
            customerPhone: $bill['phone'] ?? null,
            shippingAddress: array_filter([
                'line1' => $ship['address_1'] ?? null,
                'line2' => $ship['address_2'] ?? null,
                'city' => $ship['city'] ?? null,
                'state' => $ship['state'] ?? null,
                'pincode' => $ship['postcode'] ?? null,
                'country' => $ship['country'] ?? null,
            ], fn ($v) => $v !== null && $v !== ''),
            total: (float) ($o['total'] ?? 0),
            currency: $o['currency'] ?? 'INR',
            placedAt: $o['date_created'] ?? null,
            items: $items,
            raw: $this->trimRaw($o),
        );
    }

    public function pushInventory(array $levels): array
    {
        $client = $this->client();
        $succeeded = 0;
        $failed = 0;
        foreach ($levels as $level) {
            if (empty($level['external_id'])) {
                $failed++;
                continue;
            }
            $res = $client->put("/products/{$level['external_id']}", [
                'manage_stock' => true,
                'stock_quantity' => (int) round($level['quantity']),
            ]);
            $res->successful() ? $succeeded++ : $failed++;
        }

        return ['succeeded' => $succeeded, 'failed' => $failed, 'message' => null];
    }

    public function fetchListings(): array
    {
        $res = $this->client()->get('/products', ['per_page' => 100])->throw();

        return array_map(fn (array $p) => [
            'external_id' => (string) $p['id'],
            'external_sku' => $p['sku'] ?? null,
            'title' => $p['name'] ?? null,
            'price' => isset($p['price']) ? (float) $p['price'] : null,
        ], $res->json());
    }
}
