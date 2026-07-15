<?php

declare(strict_types=1);

namespace App\Services\Ecommerce\Connectors;

use App\Services\Ecommerce\Data\ConnectionResult;
use App\Services\Ecommerce\Data\NormalizedOrder;
use DateTimeInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Meesho Supplier API connector. Meesho's supplier/partner API is granted per
 * account; the base URL and key are supplied by Meesho. Auth is an API key
 * header. Where the account's plan does not expose a given endpoint the calls
 * fail loudly with the HTTP status.
 */
class MeeshoConnector extends AbstractConnector
{
    private const DEFAULT_BASE = 'https://api.meesho.com/supplier/v1';

    public function platform(): string
    {
        return 'meesho';
    }

    private function client(): PendingRequest
    {
        $this->require(['api_key']);
        $base = rtrim((string) ($this->setting('base_url') ?: self::DEFAULT_BASE), '/');

        return Http::baseUrl($base)
            ->withHeaders(array_filter([
                'Authorization' => 'Bearer '.$this->cred('api_key'),
                'x-api-key' => (string) $this->cred('api_key'),
                'x-api-secret' => $this->cred('api_secret'),
            ]))
            ->acceptJson()->asJson()->timeout(30);
    }

    public function testConnection(): ConnectionResult
    {
        $res = $this->client()->get('/orders', ['limit' => 1]);
        if ($res->failed()) {
            return ConnectionResult::fail('Meesho rejected the request (HTTP '.$res->status().'). Confirm the base URL and API key.');
        }

        return ConnectionResult::ok('Connected to Meesho supplier API.');
    }

    public function fetchOrders(?DateTimeInterface $since = null): array
    {
        $params = ['limit' => 100];
        if ($since) {
            $params['from'] = $since->format('Y-m-d');
        }

        $res = $this->client()->get('/orders', $params)->throw();
        $orders = $res->json('orders', $res->json('data', []));

        return array_map(fn (array $o) => $this->mapOrder($o), $orders);
    }

    private function mapOrder(array $o): NormalizedOrder
    {
        $ship = $o['shipping_address'] ?? $o['customer_address'] ?? [];
        $items = array_map(fn (array $li) => [
            'external_id' => (string) ($li['product_id'] ?? $li['catalog_id'] ?? ''),
            'sku' => $li['sku'] ?? null,
            'name' => $li['name'] ?? $li['product_name'] ?? 'Item',
            'quantity' => (float) ($li['quantity'] ?? 1),
            'price' => (float) ($li['price'] ?? $li['selling_price'] ?? 0),
        ], $o['items'] ?? $o['sub_orders'] ?? []);

        return new NormalizedOrder(
            externalId: (string) ($o['order_id'] ?? $o['id'] ?? ''),
            externalNumber: $o['order_number'] ?? ($o['order_id'] ?? null),
            status: $o['status'] ?? $o['order_status'] ?? null,
            financialStatus: $o['payment_mode'] ?? null,
            customerName: $o['customer_name'] ?? ($ship['name'] ?? null),
            customerEmail: $o['customer_email'] ?? null,
            customerPhone: $ship['phone'] ?? ($o['customer_phone'] ?? null),
            shippingAddress: array_filter([
                'line1' => $ship['address'] ?? $ship['line1'] ?? null,
                'city' => $ship['city'] ?? null,
                'state' => $ship['state'] ?? null,
                'pincode' => $ship['pincode'] ?? $ship['zip'] ?? null,
            ], fn ($v) => $v !== null && $v !== ''),
            total: (float) ($o['total'] ?? $o['order_value'] ?? 0),
            currency: 'INR',
            placedAt: $o['created_at'] ?? $o['order_date'] ?? null,
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
            if (empty($level['sku'])) {
                $failed++;
                continue;
            }
            $res = $client->put('/inventory', [
                'sku' => $level['sku'],
                'quantity' => (int) round($level['quantity']),
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
