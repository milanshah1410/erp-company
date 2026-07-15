<?php

declare(strict_types=1);

namespace App\Services\Ecommerce\Data;

/**
 * A marketplace order flattened into the shape the ERP stores/imports,
 * independent of which platform it came from. Each connector maps its native
 * payload into one of these.
 *
 * Items are arrays: ['external_id' => ?string, 'sku' => ?string, 'name' => string,
 *                     'quantity' => float, 'price' => float].
 */
class NormalizedOrder
{
    public function __construct(
        public readonly string $externalId,
        public readonly ?string $externalNumber,
        public readonly ?string $status,
        public readonly ?string $financialStatus,
        public readonly ?string $customerName,
        public readonly ?string $customerEmail,
        public readonly ?string $customerPhone,
        public readonly array $shippingAddress,
        public readonly float $total,
        public readonly string $currency,
        public readonly ?string $placedAt,
        public readonly array $items,
        public readonly array $raw = [],
    ) {}

    public function toArray(): array
    {
        return [
            'external_order_id' => $this->externalId,
            'external_order_number' => $this->externalNumber,
            'order_status' => $this->status,
            'financial_status' => $this->financialStatus,
            'customer_name' => $this->customerName,
            'customer_email' => $this->customerEmail,
            'customer_phone' => $this->customerPhone,
            'shipping_address' => $this->shippingAddress,
            'total' => $this->total,
            'currency' => $this->currency,
            'placed_at' => $this->placedAt,
            'items' => $this->items,
            'raw' => $this->raw,
        ];
    }
}
