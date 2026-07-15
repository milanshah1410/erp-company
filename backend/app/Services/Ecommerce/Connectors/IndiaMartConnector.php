<?php

declare(strict_types=1);

namespace App\Services\Ecommerce\Connectors;

use App\Services\Ecommerce\Data\ConnectionResult;
use App\Services\Ecommerce\Data\NormalizedOrder;
use DateTimeInterface;
use Illuminate\Support\Facades\Http;

/**
 * IndiaMART Lead Manager (Push/Pull CRM) API connector
 * (https://help.indiamart.com/knowledge-base/lms-crm-integration/).
 * IndiaMART is a B2B enquiry marketplace: there are no fulfillable "orders" or
 * inventory, so buyer enquiries are pulled and surfaced as importable orders
 * (each becomes a lead / quotation-style sales order). Inventory push is not
 * supported.
 */
class IndiaMartConnector extends AbstractConnector
{
    private const BASE = 'https://mapi.indiamart.com/wservce/crm/crmListing/v2';

    public function platform(): string
    {
        return 'indiamart';
    }

    public function testConnection(): ConnectionResult
    {
        $this->require(['crm_key']);
        $res = Http::acceptJson()->timeout(30)->get(self::BASE, [
            'glusr_crm_key' => (string) $this->cred('crm_key'),
            'start_time' => (new \DateTimeImmutable('-1 day'))->format('d-M-Y H:i:s'),
            'end_time' => (new \DateTimeImmutable())->format('d-M-Y H:i:s'),
        ]);
        if ($res->failed()) {
            return ConnectionResult::fail('IndiaMART rejected the CRM key (HTTP '.$res->status().').');
        }
        $code = $res->json('CODE');
        if ($code && (int) $code !== 200) {
            return ConnectionResult::fail('IndiaMART: '.($res->json('MESSAGE') ?? 'CRM key not accepted.'));
        }

        return ConnectionResult::ok('Connected to IndiaMART Lead Manager.');
    }

    public function fetchOrders(?DateTimeInterface $since = null): array
    {
        $this->require(['crm_key']);
        $since ??= new \DateTimeImmutable('-7 days');
        $res = Http::acceptJson()->timeout(40)->get(self::BASE, [
            'glusr_crm_key' => (string) $this->cred('crm_key'),
            'start_time' => $since->format('d-M-Y H:i:s'),
            'end_time' => (new \DateTimeImmutable())->format('d-M-Y H:i:s'),
        ])->throw();

        return array_map(fn (array $l) => $this->mapLead($l), $res->json('RESPONSE', []) ?: []);
    }

    private function mapLead(array $l): NormalizedOrder
    {
        $item = [
            'external_id' => (string) ($l['QUERY_PRODUCT_NAME'] ?? ''),
            'sku' => null,
            'name' => $l['QUERY_PRODUCT_NAME'] ?? $l['QUERY_MCAT_NAME'] ?? 'Enquiry',
            'quantity' => 1.0,
            'price' => 0.0,
        ];

        return new NormalizedOrder(
            externalId: (string) ($l['UNIQUE_QUERY_ID'] ?? ''),
            externalNumber: $l['UNIQUE_QUERY_ID'] ?? null,
            status: $l['QUERY_TYPE'] ?? 'enquiry',
            financialStatus: 'enquiry',
            customerName: $l['SENDER_NAME'] ?? null,
            customerEmail: $l['SENDER_EMAIL'] ?? null,
            customerPhone: $l['SENDER_MOBILE'] ?? ($l['SENDER_PHONE'] ?? null),
            shippingAddress: array_filter([
                'line1' => $l['SENDER_ADDRESS'] ?? null,
                'city' => $l['SENDER_CITY'] ?? null,
                'state' => $l['SENDER_STATE'] ?? null,
                'pincode' => $l['SENDER_PINCODE'] ?? null,
                'country' => $l['SENDER_COUNTRY_ISO'] ?? null,
            ], fn ($v) => $v !== null && $v !== ''),
            total: 0.0,
            currency: 'INR',
            placedAt: $l['QUERY_TIME'] ?? null,
            items: [$item],
            raw: $this->trimRaw($l),
        );
    }

    public function pushInventory(array $levels): array
    {
        return ['succeeded' => 0, 'failed' => 0, 'message' => 'IndiaMART does not support inventory sync (enquiry marketplace).'];
    }

    public function fetchListings(): array
    {
        return [];
    }
}
