<?php

declare(strict_types=1);

namespace App\Services\Ecommerce\Connectors;

use App\Services\Ecommerce\Data\ConnectionResult;
use App\Services\Ecommerce\Data\NormalizedOrder;
use DateTimeInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Magento 2 REST API connector (https://developer.adobe.com/commerce/webapi/rest).
 * Auth is a Bearer integration access token against {base_url}/rest/V1.
 */
class MagentoConnector extends AbstractConnector
{
    public function platform(): string
    {
        return 'magento';
    }

    private function client(): PendingRequest
    {
        $this->require(['base_url', 'access_token']);
        $base = rtrim((string) $this->setting('base_url'), '/').'/rest/V1';

        return Http::baseUrl($base)
            ->withToken((string) $this->cred('access_token'))
            ->acceptJson()->asJson()->timeout(30);
    }

    public function testConnection(): ConnectionResult
    {
        $res = $this->client()->get('/store/storeConfigs');
        if ($res->failed()) {
            return ConnectionResult::fail('Magento rejected the token (HTTP '.$res->status().').');
        }

        return ConnectionResult::ok('Connected to Magento.', [
            'currency' => $res->json('0.base_currency_code'),
        ]);
    }

    public function fetchOrders(?DateTimeInterface $since = null): array
    {
        // Magento searchCriteria: page-size 100, optionally filter by updated_at.
        $query = [
            'searchCriteria[pageSize]' => 100,
            'searchCriteria[sortOrders][0][field]' => 'created_at',
            'searchCriteria[sortOrders][0][direction]' => 'DESC',
        ];
        if ($since) {
            $query['searchCriteria[filterGroups][0][filters][0][field]'] = 'updated_at';
            $query['searchCriteria[filterGroups][0][filters][0][conditionType]'] = 'gteq';
            $query['searchCriteria[filterGroups][0][filters][0][value]'] = $since->format('Y-m-d H:i:s');
        } else {
            $query['searchCriteria[filterGroups][0][filters][0][field]'] = 'entity_id';
            $query['searchCriteria[filterGroups][0][filters][0][conditionType]'] = 'gt';
            $query['searchCriteria[filterGroups][0][filters][0][value]'] = 0;
        }

        $res = $this->client()->get('/orders', $query)->throw();

        return array_map(fn (array $o) => $this->mapOrder($o), $res->json('items', []));
    }

    private function mapOrder(array $o): NormalizedOrder
    {
        $ship = $o['extension_attributes']['shipping_assignments'][0]['shipping']['address'] ?? ($o['billing_address'] ?? []);
        $items = array_map(fn (array $li) => [
            'external_id' => (string) ($li['product_id'] ?? ''),
            'sku' => $li['sku'] ?? null,
            'name' => $li['name'] ?? 'Item',
            'quantity' => (float) ($li['qty_ordered'] ?? 1),
            'price' => (float) ($li['price'] ?? 0),
        ], $o['items'] ?? []);

        $street = $ship['street'] ?? [];

        return new NormalizedOrder(
            externalId: (string) $o['entity_id'],
            externalNumber: $o['increment_id'] ?? null,
            status: $o['status'] ?? null,
            financialStatus: (float) ($o['total_paid'] ?? 0) >= (float) ($o['grand_total'] ?? 0) && (float) ($o['grand_total'] ?? 0) > 0 ? 'paid' : 'pending',
            customerName: trim(($o['customer_firstname'] ?? $ship['firstname'] ?? '').' '.($o['customer_lastname'] ?? $ship['lastname'] ?? '')) ?: null,
            customerEmail: $o['customer_email'] ?? ($ship['email'] ?? null),
            customerPhone: $ship['telephone'] ?? null,
            shippingAddress: array_filter([
                'line1' => $street[0] ?? null,
                'line2' => $street[1] ?? null,
                'city' => $ship['city'] ?? null,
                'state' => $ship['region'] ?? null,
                'pincode' => $ship['postcode'] ?? null,
                'country' => $ship['country_id'] ?? null,
            ], fn ($v) => $v !== null && $v !== ''),
            total: (float) ($o['grand_total'] ?? 0),
            currency: $o['order_currency_code'] ?? 'INR',
            placedAt: $o['created_at'] ?? null,
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
            $sku = rawurlencode((string) $level['sku']);
            $res = $client->put("/products/{$sku}/stockItems/1", [
                'stockItem' => [
                    'qty' => (int) round($level['quantity']),
                    'is_in_stock' => $level['quantity'] > 0,
                ],
            ]);
            $res->successful() ? $succeeded++ : $failed++;
        }

        return ['succeeded' => $succeeded, 'failed' => $failed, 'message' => null];
    }

    public function fetchListings(): array
    {
        $res = $this->client()->get('/products', ['searchCriteria[pageSize]' => 100])->throw();

        return array_map(fn (array $p) => [
            'external_id' => (string) ($p['id'] ?? $p['sku']),
            'external_sku' => $p['sku'] ?? null,
            'title' => $p['name'] ?? null,
            'price' => isset($p['price']) ? (float) $p['price'] : null,
        ], $res->json('items', []));
    }
}
