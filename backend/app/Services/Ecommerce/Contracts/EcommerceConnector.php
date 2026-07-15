<?php

declare(strict_types=1);

namespace App\Services\Ecommerce\Contracts;

use App\Services\Ecommerce\Data\ConnectionResult;
use DateTimeInterface;

/**
 * Common surface every e-commerce channel connector implements. Concrete
 * connectors wrap each platform's REST/GraphQL API behind this normalised API
 * so the sync service is platform-agnostic.
 */
interface EcommerceConnector
{
    /** Machine key for the platform (e.g. 'shopify'). */
    public function platform(): string;

    /** Verify the stored credentials by making a lightweight authenticated call. */
    public function testConnection(): ConnectionResult;

    /**
     * Pull orders placed since $since (or a recent window when null).
     *
     * @return \App\Services\Ecommerce\Data\NormalizedOrder[]
     */
    public function fetchOrders(?DateTimeInterface $since = null): array;

    /**
     * Push absolute stock levels to the channel.
     *
     * @param  array<int,array{external_id:?string,sku:?string,quantity:float}>  $levels
     * @return array{succeeded:int,failed:int,message:?string}
     */
    public function pushInventory(array $levels): array;

    /**
     * Pull the channel's product listings for mapping to local products.
     *
     * @return array<int,array{external_id:string,external_sku:?string,title:?string,price:?float}>
     */
    public function fetchListings(): array;
}
