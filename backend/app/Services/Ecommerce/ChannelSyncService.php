<?php

declare(strict_types=1);

namespace App\Services\Ecommerce;

use App\Models\ChannelIntegration;
use App\Models\ChannelListing;
use App\Models\ChannelOrder;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Services\DocumentPricingService;
use App\Services\Ecommerce\Data\ConnectionResult;
use App\Services\SalesOrderFulfillmentService;
use RuntimeException;
use Throwable;
use Illuminate\Support\Facades\DB;

/**
 * Orchestrates all e-commerce channel sync work: connection tests, pulling
 * marketplace orders into the inbox, importing an inbox order into the sales
 * pipeline, pushing stock levels out, and pulling/auto-mapping listings. Every
 * run is recorded in channel_sync_logs and reflected on the integration's
 * status / last_*_at timestamps.
 */
class ChannelSyncService
{
    public function __construct(
        private readonly ConnectorFactory $factory,
        private readonly DocumentPricingService $pricing,
        private readonly SalesOrderFulfillmentService $fulfillment,
    ) {}

    /** Test the stored credentials and record the outcome on the integration. */
    public function testConnection(ChannelIntegration $integration): ConnectionResult
    {
        try {
            $result = $this->factory->make($integration)->testConnection();
        } catch (Throwable $e) {
            $result = ConnectionResult::fail($e->getMessage());
        }

        $integration->update([
            'status' => $result->ok ? 'connected' : 'error',
            'last_error' => $result->ok ? null : $result->message,
        ]);

        $this->log($integration, 'connection', 'pull', $result->ok ? 'success' : 'failed', 0, 0, 0, $result->message);

        return $result;
    }

    /**
     * Pull orders since the last sync into the channel_orders inbox
     * (idempotent on external_order_id).
     *
     * @return array{processed:int,new:int}
     */
    public function syncOrders(ChannelIntegration $integration): array
    {
        $connector = $this->factory->make($integration);
        $since = $integration->last_order_sync_at?->toDateTimeImmutable();

        try {
            $orders = $connector->fetchOrders($since);
        } catch (Throwable $e) {
            $integration->update(['status' => 'error', 'last_error' => $e->getMessage()]);
            $this->log($integration, 'orders', 'pull', 'failed', 0, 0, 0, $this->reason($e));
            throw $e;
        }

        $new = 0;
        foreach ($orders as $order) {
            $record = ChannelOrder::updateOrCreate(
                ['channel_integration_id' => $integration->id, 'external_order_id' => $order->externalId],
                array_merge($order->toArray(), ['tenant_id' => $integration->tenant_id]),
            );
            if ($record->wasRecentlyCreated) {
                $new++;
            }
        }

        $integration->update([
            'status' => 'connected',
            'last_error' => null,
            'last_order_sync_at' => now(),
        ]);

        $this->log($integration, 'orders', 'pull', 'success', count($orders), $new, 0,
            "{$new} new of ".count($orders).' fetched');

        return ['processed' => count($orders), 'new' => $new];
    }

    /**
     * Import an inbox order into the sales pipeline as a SalesOrder on the
     * 'ecommerce' channel, reserving stock via the shared fulfillment service.
     */
    public function importOrder(ChannelOrder $channelOrder): SalesOrder
    {
        if ($channelOrder->import_status === 'imported' && $channelOrder->sales_order_id) {
            return $channelOrder->salesOrder;
        }
        if (empty($channelOrder->items)) {
            $this->failImport($channelOrder, 'No line items to import.');
            throw new RuntimeException('This channel order has no line items to import.');
        }

        $integration = $channelOrder->integration;
        $warehouseId = $this->resolveWarehouseId($integration);
        if (! $warehouseId) {
            $this->failImport($channelOrder, 'No warehouse configured.');
            throw new RuntimeException('No warehouse is available to receive imported orders.');
        }

        $customer = $this->resolveCustomer($integration);
        $lines = $this->resolveLines($channelOrder);
        if (empty($lines)) {
            $this->failImport($channelOrder, 'None of the order items could be matched to a product. Map the listings first.');
            throw new RuntimeException('None of the order items map to a local product. Map the channel listings first.');
        }

        $priced = $this->pricing->price($lines);

        $salesOrder = DB::transaction(function () use ($priced, $customer, $warehouseId, $channelOrder, $integration) {
            $header = $priced['header'];
            $order = SalesOrder::create([
                'so_number' => SalesOrder::nextNumber(),
                'customer_id' => $customer->id,
                'warehouse_id' => $warehouseId,
                'status' => 'confirmed',
                'channel' => 'ecommerce',
                'order_date' => ($channelOrder->placed_at ?? now())->toDateString(),
                'subtotal' => $header['subtotal'],
                'item_discount_total' => $header['item_discount_total'],
                'discount_type' => $header['discount_type'],
                'discount_value' => $header['discount_value'],
                'discount_amount' => $header['discount_amount'],
                'tax_amount' => $header['tax_amount'],
                'round_off' => $header['round_off'],
                'total' => $header['total'],
                'notes' => $this->orderNote($channelOrder, $integration),
            ]);

            foreach ($priced['lines'] as $line) {
                $order->items()->create([
                    'product_id' => $line['product_id'],
                    'variant_id' => $line['variant_id'],
                    'quantity' => $line['quantity'],
                    'unit_price' => $line['unit_price'],
                    'discount_type' => $line['discount_type'],
                    'discount_value' => $line['discount_value'],
                    'discount_amount' => $line['discount_amount'],
                    'tax_percent' => $line['tax_percent'],
                    'tax_amount' => $line['tax_amount'],
                    'line_total' => $line['line_total'],
                ]);
            }

            $channelOrder->update([
                'import_status' => 'imported',
                'sales_order_id' => $order->id,
                'import_error' => null,
            ]);

            return $order;
        });

        // Reserve available stock and settle to confirmed/backordered — same
        // path as internal SO confirm and B2B approve.
        $this->fulfillment->reserveAndConfirm($salesOrder);

        return $salesOrder->fresh();
    }

    /**
     * Push current available stock for the integration's mapped, active
     * listings out to the channel.
     *
     * @return array{succeeded:int,failed:int,skipped:int}
     */
    public function pushInventory(ChannelIntegration $integration): array
    {
        $connector = $this->factory->make($integration);
        $listings = $integration->listings()->where('status', 'active')->get();

        $levels = [];
        $pushed = [];
        foreach ($listings as $listing) {
            if (empty($listing->external_id) && empty($listing->external_sku)) {
                continue;
            }
            $levels[] = [
                'external_id' => $listing->external_id,
                'sku' => $listing->external_sku,
                'quantity' => $this->availableStock($integration, $listing->product_id, $listing->variant_id),
            ];
            $pushed[] = $listing->id;
        }

        if (empty($levels)) {
            $this->log($integration, 'inventory', 'push', 'success', 0, 0, 0, 'No mapped listings to push.');

            return ['succeeded' => 0, 'failed' => 0, 'skipped' => $listings->count()];
        }

        try {
            $result = $connector->pushInventory($levels);
        } catch (Throwable $e) {
            $integration->update(['status' => 'error', 'last_error' => $e->getMessage()]);
            $this->log($integration, 'inventory', 'push', 'failed', count($levels), 0, count($levels), $this->reason($e));
            throw $e;
        }

        $integration->update(['last_inventory_sync_at' => now()]);
        ChannelListing::whereIn('id', $pushed)->update(['last_synced_at' => now()]);

        $status = $result['failed'] === 0 ? 'success' : ($result['succeeded'] === 0 ? 'failed' : 'partial');
        $this->log($integration, 'inventory', 'push', $status, count($levels), $result['succeeded'], $result['failed'], $result['message'] ?? null);

        return ['succeeded' => $result['succeeded'], 'failed' => $result['failed'], 'skipped' => $listings->count() - count($levels)];
    }

    /**
     * Pull the channel's listings and auto-map any whose SKU matches a local
     * product/variant SKU and isn't mapped yet.
     *
     * @return array{fetched:int,mapped:int}
     */
    public function syncListings(ChannelIntegration $integration): array
    {
        $connector = $this->factory->make($integration);

        try {
            $remote = $connector->fetchListings();
        } catch (Throwable $e) {
            $this->log($integration, 'listings', 'pull', 'failed', 0, 0, 0, $this->reason($e));
            throw $e;
        }

        $mapped = 0;
        foreach ($remote as $r) {
            if (empty($r['external_id'])) {
                continue;
            }
            $match = $this->matchLocalBySku($r['external_sku'] ?? null);
            if (! $match) {
                continue;
            }
            $listing = ChannelListing::updateOrCreate(
                ['channel_integration_id' => $integration->id, 'external_id' => (string) $r['external_id']],
                [
                    'tenant_id' => $integration->tenant_id,
                    'product_id' => $match['product_id'],
                    'variant_id' => $match['variant_id'],
                    'external_sku' => $r['external_sku'] ?? null,
                    'listing_title' => $r['title'] ?? null,
                    'price' => $r['price'] ?? null,
                    'last_synced_at' => now(),
                ],
            );
            if ($listing->wasRecentlyCreated) {
                $mapped++;
            }
        }

        $this->log($integration, 'listings', 'pull', 'success', count($remote), $mapped, 0,
            "{$mapped} auto-mapped of ".count($remote).' fetched');

        return ['fetched' => count($remote), 'mapped' => $mapped];
    }

    // --- helpers -----------------------------------------------------------

    /** Available (unreserved) quantity for a product/variant at the integration's warehouse (or across all). */
    private function availableStock(ChannelIntegration $integration, int $productId, ?int $variantId): float
    {
        $query = WarehouseStock::query()
            ->where('product_id', $productId)
            ->when($variantId !== null, fn ($q) => $q->where('variant_id', $variantId))
            ->when($integration->warehouse_id, fn ($q) => $q->where('warehouse_id', $integration->warehouse_id));

        return max(0.0, (float) $query->selectRaw('COALESCE(SUM(quantity - reserved_quantity), 0) as available')->value('available'));
    }

    /** Resolve channel-order lines to local products via listings, then SKU. */
    private function resolveLines(ChannelOrder $channelOrder): array
    {
        $listings = $channelOrder->integration->listings()->get();
        $byExternalId = $listings->keyBy('external_id');
        $byExternalSku = $listings->filter(fn ($l) => $l->external_sku)->keyBy('external_sku');

        $lines = [];
        foreach ($channelOrder->items as $item) {
            $listing = ($item['external_id'] ?? null) ? $byExternalId->get($item['external_id']) : null;
            $listing ??= ($item['sku'] ?? null) ? $byExternalSku->get($item['sku']) : null;

            $product = null;
            $variantId = null;
            if ($listing) {
                $product = Product::find($listing->product_id);
                $variantId = $listing->variant_id;
            } elseif (! empty($item['sku'])) {
                $product = Product::where('sku', $item['sku'])->first();
            }
            if (! $product) {
                continue;
            }

            $lines[] = [
                'product_id' => $product->id,
                'variant_id' => $variantId,
                'quantity' => (float) ($item['quantity'] ?? 1),
                'unit_price' => (float) ($item['price'] ?? 0),
                'tax_percent' => (float) $product->tax_percent,
            ];
        }

        return $lines;
    }

    /** Find a local product/variant by exact SKU match. */
    private function matchLocalBySku(?string $sku): ?array
    {
        if (empty($sku)) {
            return null;
        }
        $variant = ProductVariant::where('sku', $sku)->first();
        if ($variant) {
            return ['product_id' => $variant->product_id, 'variant_id' => $variant->id];
        }
        $product = Product::where('sku', $sku)->first();

        return $product ? ['product_id' => $product->id, 'variant_id' => null] : null;
    }

    /** One reusable bill-to customer per integration, so imported marketplace orders don't clutter the CRM. */
    private function resolveCustomer(ChannelIntegration $integration): Customer
    {
        $code = 'ECOM-'.strtoupper($integration->platform).'-'.$integration->id;

        return Customer::firstOrCreate(
            ['code' => $code],
            [
                'tenant_id' => $integration->tenant_id,
                'name' => $integration->name.' ('.ucfirst($integration->platform).')',
                'type' => 'retail',
                'is_active' => true,
            ],
        );
    }

    private function resolveWarehouseId(ChannelIntegration $integration): ?int
    {
        return $integration->warehouse_id ?: Warehouse::query()->orderBy('id')->value('id');
    }

    private function orderNote(ChannelOrder $channelOrder, ChannelIntegration $integration): string
    {
        $parts = [ucfirst($integration->platform).' order '.($channelOrder->external_order_number ?: $channelOrder->external_order_id)];
        if ($channelOrder->customer_name) {
            $parts[] = 'Buyer: '.$channelOrder->customer_name;
        }
        if ($channelOrder->customer_phone) {
            $parts[] = 'Ph: '.$channelOrder->customer_phone;
        }

        return implode(' | ', $parts);
    }

    private function failImport(ChannelOrder $channelOrder, string $reason): void
    {
        $channelOrder->update(['import_status' => 'failed', 'import_error' => $reason]);
    }

    private function reason(Throwable $e): string
    {
        return mb_substr($e->getMessage(), 0, 250);
    }

    private function log(ChannelIntegration $integration, string $type, string $direction, string $status, int $processed, int $succeeded, int $failed, ?string $message): void
    {
        $integration->syncLogs()->create([
            'tenant_id' => $integration->tenant_id,
            'type' => $type,
            'direction' => $direction,
            'status' => $status,
            'processed' => $processed,
            'succeeded' => $succeeded,
            'failed' => $failed,
            'message' => $message ? mb_substr($message, 0, 250) : null,
        ]);
    }
}
