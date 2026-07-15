<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Models\PurchaseItem;
use App\Models\StockLedger;
use App\Models\WarehouseStock;
use Carbon\CarbonImmutable;

/**
 * Consumption-velocity intelligence (SRS §22): low-stock (days-to-stockout)
 * prediction and forecast-driven auto purchase suggestions.
 *
 * Where ReorderController fires purely on the static reorder level, this engine
 * derives a *dynamic* reorder point from recent sales velocity, supplier lead
 * time and a safety buffer — so it flags a fast-moving item well before it hits
 * the manual reorder level, and sizes the order to cover lead-time demand.
 */
class InventoryIntelligenceService
{
    public function __construct(private readonly AiProviderManager $providers = new AiProviderManager) {}

    /**
     * Daily consumption rate per product over the velocity window, from sales
     * ledger movements.
     *
     * @return array<int,float> product_id => units/day
     */
    private function dailyConsumption(int $windowDays): array
    {
        $since = CarbonImmutable::now()->subDays($windowDays)->startOfDay();

        $rows = StockLedger::query()
            ->where('type', 'sale')
            ->where('created_at', '>=', $since)
            ->selectRaw('product_id, SUM(quantity_out) as qty')
            ->groupBy('product_id')
            ->pluck('qty', 'product_id');

        $rates = [];
        foreach ($rows as $productId => $qty) {
            $rates[(int) $productId] = (float) $qty / max(1, $windowDays);
        }

        return $rates;
    }

    /**
     * Quantity already on order (ordered − received) per product, from open POs.
     *
     * @return array<int,float>
     */
    private function onOrder(): array
    {
        return PurchaseItem::query()
            ->whereHas('purchaseOrder', fn ($q) => $q->whereIn('status', [
                'draft', 'pending_approval', 'approved', 'partially_received',
            ]))
            ->selectRaw('product_id, SUM(quantity - received_quantity) as qty')
            ->groupBy('product_id')
            ->pluck('qty', 'product_id')
            ->map(fn ($v) => max(0.0, (float) $v))
            ->toArray();
    }

    /**
     * On-hand quantity per product across all warehouses.
     *
     * @return array<int,float>
     */
    private function onHand(): array
    {
        return WarehouseStock::query()
            ->selectRaw('product_id, SUM(quantity) as qty')
            ->groupBy('product_id')
            ->pluck('qty', 'product_id')
            ->map(fn ($v) => (float) $v)
            ->toArray();
    }

    /**
     * Predict when each moving product will run out of stock.
     *
     * @return array{generated_at:string, window_days:int, items:list<array<string,mixed>>}
     */
    public function lowStockPrediction(?int $windowDays = null): array
    {
        $windowDays = $windowDays ?? (int) config('ai.velocity_window_days', 90);
        $rates = $this->dailyConsumption($windowDays);
        $onHand = $this->onHand();

        // Consider every product that either has stock or has been selling.
        $productIds = array_values(array_unique(array_merge(array_keys($rates), array_keys($onHand))));

        $products = \App\Models\Product::query()
            ->whereIn('id', $productIds)
            ->get(['id', 'sku', 'name', 'reorder_level', 'reorder_quantity'])
            ->keyBy('id');

        $items = [];
        foreach ($productIds as $productId) {
            $product = $products->get($productId);
            if (! $product) {
                continue;
            }

            $rate = $rates[$productId] ?? 0.0;
            $stock = $onHand[$productId] ?? 0.0;

            // Not moving — nothing to predict.
            if ($rate <= 0) {
                continue;
            }

            $daysLeft = $stock / $rate;
            $stockoutDate = CarbonImmutable::now()->addDays((int) floor($daysLeft));

            $items[] = [
                'product' => ['id' => $product->id, 'sku' => $product->sku, 'name' => $product->name],
                'on_hand' => round($stock, 3),
                'daily_rate' => round($rate, 3),
                'days_to_stockout' => round($daysLeft, 1),
                'projected_stockout_date' => $stockoutDate->toDateString(),
                'reorder_level' => (float) $product->reorder_level,
                'risk' => $this->riskLevel($daysLeft),
            ];
        }

        usort($items, fn ($a, $b) => $a['days_to_stockout'] <=> $b['days_to_stockout']);

        return [
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
            'window_days' => $windowDays,
            'items' => $items,
        ];
    }

    /**
     * Forecast-driven purchase suggestions. For each product whose projected
     * on-hand at lead-time end falls below the safety stock, recommend an order
     * quantity that covers lead-time + safety demand.
     *
     * @return array{generated_at:string, lead_time_days:int, safety_days:int, window_days:int, items:list<array<string,mixed>>}
     */
    public function purchaseSuggestions(?int $leadTimeDays = null, ?int $safetyDays = null, ?int $windowDays = null): array
    {
        $leadTimeDays = $leadTimeDays ?? (int) (\App\Models\Setting::get('ai_lead_time_days') ?: config('ai.lead_time_days', 7));
        $safetyDays = $safetyDays ?? (int) config('ai.safety_days', 7);
        $windowDays = $windowDays ?? (int) config('ai.velocity_window_days', 90);

        $rates = $this->dailyConsumption($windowDays);
        $onHand = $this->onHand();
        $onOrder = $this->onOrder();

        $productIds = array_values(array_unique(array_merge(array_keys($rates), array_keys($onHand))));

        $products = \App\Models\Product::query()
            ->with('preferredSupplier:id,name')
            ->whereIn('id', $productIds)
            ->get()
            ->keyBy('id');

        $items = [];
        foreach ($productIds as $productId) {
            $product = $products->get($productId);
            if (! $product) {
                continue;
            }

            $rate = $rates[$productId] ?? 0.0;
            $stock = $onHand[$productId] ?? 0.0;
            $incoming = $onOrder[$productId] ?? 0.0;

            $leadDemand = $rate * $leadTimeDays;
            $safetyStock = $rate * $safetyDays;
            $reorderPoint = $leadDemand + $safetyStock;

            $available = $stock + $incoming;

            // A product needs ordering if it will dip below safety stock within
            // the lead time, or is already at/under its manual reorder level.
            $belowReorderLevel = (float) $product->reorder_level > 0 && $stock <= (float) $product->reorder_level;
            if ($available > $reorderPoint && ! $belowReorderLevel) {
                continue;
            }

            // Cover demand up to the end of the lead+safety horizon, netting off
            // stock already on hand or inbound; respect the configured reorder qty.
            $target = $reorderPoint + $leadDemand; // aim a lead-time above the reorder point
            $recommended = max(0.0, $target - $available);
            if ((float) $product->reorder_quantity > 0) {
                $recommended = max($recommended, (float) $product->reorder_quantity);
            }
            $recommended = ceil($recommended);

            if ($recommended <= 0) {
                continue;
            }

            $items[] = [
                'product' => ['id' => $product->id, 'sku' => $product->sku, 'name' => $product->name],
                'on_hand' => round($stock, 3),
                'on_order' => round($incoming, 3),
                'daily_rate' => round($rate, 3),
                'reorder_point' => round($reorderPoint, 3),
                'days_of_cover' => $rate > 0 ? round($available / $rate, 1) : null,
                'recommended_quantity' => (float) $recommended,
                'estimated_cost' => round($recommended * (float) $product->cost_price, 2),
                'cost_price' => (float) $product->cost_price,
                'tax_percent' => (float) $product->tax_percent,
                'urgency' => $this->riskLevel($rate > 0 ? $available / $rate : INF),
                'supplier' => $product->preferredSupplier
                    ? ['id' => $product->preferredSupplier->id, 'name' => $product->preferredSupplier->name]
                    : null,
            ];
        }

        usort($items, fn ($a, $b) => ($a['days_of_cover'] ?? INF) <=> ($b['days_of_cover'] ?? INF));

        return [
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
            'lead_time_days' => $leadTimeDays,
            'safety_days' => $safetyDays,
            'window_days' => $windowDays,
            'items' => $items,
        ];
    }

    private function riskLevel(float $daysLeft): string
    {
        if ($daysLeft <= 7) {
            return 'critical';
        }
        if ($daysLeft <= 21) {
            return 'high';
        }
        if ($daysLeft <= 45) {
            return 'medium';
        }

        return 'low';
    }
}
