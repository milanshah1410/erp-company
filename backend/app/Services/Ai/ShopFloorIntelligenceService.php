<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Models\Bom;
use App\Models\ManufacturingOrder;
use App\Models\Product;
use App\Models\PurchaseItem;
use App\Models\WarehouseStock;
use App\Modules\Industry\Manufacturing\Models\Machine;
use App\Modules\Industry\Manufacturing\Models\MachineDowntime;
use App\Modules\Industry\Manufacturing\Models\ProductionScrap;
use App\Modules\Industry\Manufacturing\Models\QualityInspection;
use Carbon\Carbon;

/**
 * The factory's three AI questions (`.claude/shops/_base-factory.md` §10).
 *
 * "Same engines, different questions" is the whole design note. A works does not
 * ask what will sell next month; it asks what it is short of for the orders
 * already on the floor, which machine keeps stopping and why, and which article
 * is quietly scrapping more than the rest.
 *
 * Local statistics, no hosted model — same as every other engine in this folder.
 * The narration seam (AiProviderManager) sits above these and turns the numbers
 * into a sentence; the numbers themselves are arithmetic anyone can check, which
 * is the point when the answer is "go and buy 400 kg of resin".
 */
class ShopFloorIntelligenceService
{
    /**
     * Raw-material shortfall against open manufacturing orders.
     *
     * This is the tile deliberately left off the dashboard (see
     * DashboardController::factoryKpis) — too slow to cache every five minutes,
     * and wrong if it cuts corners. Here it can be done properly.
     *
     * The arithmetic, in order, because each step is a way to get it wrong:
     *
     *  1. **Required** — every open MO's BOM, scaled by the quantity still to
     *     produce (ordered minus produced, not ordered), and inflated by each
     *     line's wastage percent. An MO half-finished needs half the material.
     *  2. **Already issued** — MOs flagged `materials_consumed` have had their
     *     material taken to the floor. Counting them again is how a planner ends
     *     up ordering twice.
     *  3. **On hand** — stock across warehouses.
     *  4. **On order** — what is already on a purchase order and not yet
     *     received. Ignoring this is the single most expensive mistake this
     *     report could make: it sends somebody out to buy what is already coming.
     *
     * Shortfall = required − on hand − on order. Anything ≤ 0 is dropped.
     *
     * @return array{generated_at:string, open_orders:int, items:list<array<string,mixed>>, skipped_no_bom:int}
     */
    public function materialShortfall(): array
    {
        $orders = ManufacturingOrder::query()
            ->whereIn('status', ['draft', 'in_progress'])
            ->get(['id', 'mo_number', 'bom_id', 'quantity_to_produce', 'quantity_produced', 'materials_consumed', 'expected_completion_date']);

        if ($orders->isEmpty()) {
            return ['generated_at' => now()->toIso8601String(), 'open_orders' => 0, 'items' => [], 'skipped_no_bom' => 0];
        }

        $boms = Bom::query()
            ->with('items:id,bom_id,product_id,quantity,wastage_percent')
            ->whereIn('id', $orders->pluck('bom_id')->filter()->unique())
            ->get(['id', 'output_quantity'])
            ->keyBy('id');

        /** @var array<int, array{required: float, orders: array<int, string>, earliest: ?string}> */
        $need = [];
        $skipped = 0;

        foreach ($orders as $mo) {
            $bom = $mo->bom_id ? $boms->get($mo->bom_id) : null;
            if (! $bom || $bom->items->isEmpty()) {
                // An MO with no BOM cannot be exploded. Counted and reported
                // rather than silently ignored — a works with half its orders
                // un-BOM'd should be told, not shown a reassuring short list.
                $skipped++;

                continue;
            }

            // Material already on the floor is not material still to buy.
            if ($mo->materials_consumed) {
                continue;
            }

            $outstanding = max((float) $mo->quantity_to_produce - (float) $mo->quantity_produced, 0);
            if ($outstanding <= 0) {
                continue;
            }

            $output = (float) $bom->output_quantity ?: 1.0;
            $batches = $outstanding / $output;

            foreach ($bom->items as $line) {
                $qty = (float) $line->quantity * $batches;
                // Wastage is part of what has to be bought, not an afterthought:
                // a 5% wastage on a 2-tonne order is 100 kg somebody must own.
                $qty *= 1 + ((float) $line->wastage_percent / 100);

                $need[$line->product_id]['required'] = ($need[$line->product_id]['required'] ?? 0) + $qty;
                $need[$line->product_id]['orders'][$mo->id] = $mo->mo_number;

                $due = $mo->expected_completion_date?->toDateString();
                $current = $need[$line->product_id]['earliest'] ?? null;
                if ($due !== null && ($current === null || $due < $current)) {
                    $need[$line->product_id]['earliest'] = $due;
                }
            }
        }

        if ($need === []) {
            return [
                'generated_at' => now()->toIso8601String(),
                'open_orders' => $orders->count(),
                'items' => [],
                'skipped_no_bom' => $skipped,
            ];
        }

        $productIds = array_keys($need);
        $onHand = $this->onHand($productIds);
        $onOrder = $this->onOrder($productIds);

        $products = Product::query()
            ->with('preferredSupplier:id,name')
            ->whereIn('id', $productIds)
            ->get()
            ->keyBy('id');

        $items = [];
        foreach ($need as $productId => $row) {
            $product = $products->get($productId);
            if (! $product) {
                continue;
            }

            $required = round($row['required'], 3);
            $have = $onHand[$productId] ?? 0.0;
            $incoming = $onOrder[$productId] ?? 0.0;
            $short = round($required - $have - $incoming, 3);

            if ($short <= 0) {
                continue;
            }

            $items[] = [
                'product' => ['id' => $product->id, 'sku' => $product->sku, 'name' => $product->name],
                'required' => $required,
                'on_hand' => round($have, 3),
                'on_order' => round($incoming, 3),
                'shortfall' => $short,
                'estimated_cost' => round($short * (float) $product->cost_price, 2),
                'needed_by' => $row['earliest'] ?? null,
                // What it is short *for*, so a buyer can ring the right planner.
                'for_orders' => array_values($row['orders']),
                'urgency' => $this->shortfallUrgency($row['earliest'] ?? null),
                'supplier' => $product->preferredSupplier
                    ? ['id' => $product->preferredSupplier->id, 'name' => $product->preferredSupplier->name]
                    : null,
            ];
        }

        // Most urgent first, then biggest money.
        usort($items, function (array $a, array $b) {
            $rank = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3];

            return [$rank[$a['urgency']] ?? 9, -$a['estimated_cost']]
                <=> [$rank[$b['urgency']] ?? 9, -$b['estimated_cost']];
        });

        return [
            'generated_at' => now()->toIso8601String(),
            'open_orders' => $orders->count(),
            'items' => $items,
            'skipped_no_bom' => $skipped,
        ];
    }

    /**
     * Machine downtime patterns.
     *
     * Two cuts, because "which machine" and "why" are different purchases: the
     * first buys a service contract, the second fixes a process. A works that
     * only ever sees total hours lost learns nothing it can act on.
     *
     * Availability is computed against *scheduled* hours (a working day, times
     * the days in the window) rather than 24×7 — a press that is down overnight
     * when nobody is running it is not a broken press.
     *
     * @return array{generated_at:string, window_days:int, total_minutes:int, machines:list<array<string,mixed>>, reasons:list<array<string,mixed>>}
     */
    public function machineDowntimePatterns(int $windowDays = 90, int $shiftHoursPerDay = 8): array
    {
        $from = now()->subDays($windowDays)->startOfDay();

        $rows = MachineDowntime::query()
            ->where('started_at', '>=', $from)
            ->selectRaw('machine_id, COUNT(*) as events, SUM(minutes) as minutes')
            ->groupBy('machine_id')
            ->get();

        $totalMinutes = (int) $rows->sum('minutes');
        $scheduledMinutes = $windowDays * $shiftHoursPerDay * 60;

        $machines = Machine::query()
            ->whereIn('id', $rows->pluck('machine_id')->filter())
            ->get(['id', 'code', 'name', 'status', 'hourly_cost'])
            ->keyBy('id');

        $byMachine = [];
        foreach ($rows as $row) {
            $machine = $machines->get($row->machine_id);
            if (! $machine) {
                continue;
            }

            $minutes = (int) $row->minutes;
            $events = (int) $row->events;

            $byMachine[] = [
                'machine' => ['id' => $machine->id, 'code' => $machine->code, 'name' => $machine->name],
                'status' => $machine->status,
                'events' => $events,
                'minutes' => $minutes,
                'hours' => round($minutes / 60, 1),
                // Mean time between stoppages, in days — the number a maintenance
                // engineer actually quotes.
                'days_between_stops' => $events > 1 ? round($windowDays / $events, 1) : null,
                'availability_percent' => $scheduledMinutes > 0
                    ? round(max(0, 100 - ($minutes / $scheduledMinutes * 100)), 2)
                    : null,
                // What the stoppage cost in machine time, where an hourly rate is set.
                'cost' => round($minutes / 60 * (float) $machine->hourly_cost, 2),
            ];
        }
        usort($byMachine, fn ($a, $b) => $b['minutes'] <=> $a['minutes']);

        $reasonRows = MachineDowntime::query()
            ->where('started_at', '>=', $from)
            ->selectRaw('reason, COUNT(*) as events, SUM(minutes) as minutes')
            ->groupBy('reason')
            ->orderByDesc('minutes')
            ->get();

        $byReason = $reasonRows->map(fn ($r) => [
            'reason' => $r->reason ?: 'Unstated',
            'events' => (int) $r->events,
            'minutes' => (int) $r->minutes,
            'hours' => round((int) $r->minutes / 60, 1),
            'share_percent' => $totalMinutes > 0 ? round((int) $r->minutes / $totalMinutes * 100, 1) : 0.0,
        ])->values()->all();

        return [
            'generated_at' => now()->toIso8601String(),
            'window_days' => $windowDays,
            'total_minutes' => $totalMinutes,
            'total_hours' => round($totalMinutes / 60, 1),
            'machines' => $byMachine,
            'reasons' => $byReason,
        ];
    }

    /**
     * Scrap-rate outliers.
     *
     * The question is not "what did we scrap most of" — that is just the
     * highest-volume article — but "what scraps at a rate unlike everything
     * else". So each product's scrap rate is compared against the works' own
     * **median**, not its mean: one catastrophic batch would drag a mean upward
     * and hide every genuine outlier behind it.
     *
     * Rate is scrapped ÷ (produced + scrapped), which is the fraction of what was
     * made that never left as good stock.
     *
     * @return array{generated_at:string, window_days:int, median_rate:?float, items:list<array<string,mixed>>, skipped_no_output:int}
     */
    public function scrapOutliers(int $windowDays = 90, float $minQuantity = 1.0): array
    {
        $from = now()->subDays($windowDays)->startOfDay()->toDateString();

        $scrap = ProductionScrap::query()
            ->whereDate('scrap_date', '>=', $from)
            ->selectRaw('product_id, SUM(quantity) as qty, SUM(total_value) as value, COUNT(*) as events')
            ->groupBy('product_id')
            ->get()
            ->keyBy('product_id');

        if ($scrap->isEmpty()) {
            return [
                'generated_at' => now()->toIso8601String(),
                'window_days' => $windowDays,
                'median_rate' => null,
                'items' => [],
                'skipped_no_output' => 0,
            ];
        }

        $produced = ManufacturingOrder::query()
            ->whereDate('order_date', '>=', $from)
            ->selectRaw('product_id, SUM(quantity_produced) as qty')
            ->groupBy('product_id')
            ->pluck('qty', 'product_id');

        // Rejections at inspection, as a second signal: a line can pass QC and
        // still be scrapped later, and vice versa. Reported, not merged.
        $rejected = QualityInspection::query()
            ->whereDate('inspected_on', '>=', $from)
            ->selectRaw('product_id, SUM(rejected_quantity) as qty')
            ->groupBy('product_id')
            ->pluck('qty', 'product_id');

        $rates = [];
        $noOutput = 0;
        foreach ($scrap as $productId => $row) {
            $scrapQty = (float) $row->qty;
            $goodQty = (float) ($produced[$productId] ?? 0);

            /**
             * No finished output yet means no rate — not a rate of 100%.
             *
             * Scrap is booked against an order while it is still running, so an
             * article with production still in progress would otherwise compute
             * as "everything we made was scrapped" and top the outlier list every
             * time. That is the failure mode this report exists to avoid: an alarm
             * that fires on healthy orders is an alarm a works learns to ignore.
             * Counted and reported instead.
             */
            if ($goodQty <= 0) {
                $noOutput++;

                continue;
            }

            $base = $goodQty + $scrapQty;

            // A product with almost no output has no meaningful rate — two units
            // scrapped out of three is not a trend, it is a Tuesday.
            if ($base < $minQuantity) {
                continue;
            }

            $rates[$productId] = $scrapQty / $base * 100;
        }

        if ($rates === []) {
            return [
                'generated_at' => now()->toIso8601String(),
                'window_days' => $windowDays,
                'median_rate' => null,
                'items' => [],
                'skipped_no_output' => $noOutput,
            ];
        }

        $median = $this->median(array_values($rates));

        $products = Product::query()->whereIn('id', array_keys($rates))->get(['id', 'sku', 'name'])->keyBy('id');

        $items = [];
        foreach ($rates as $productId => $rate) {
            $product = $products->get($productId);
            if (! $product) {
                continue;
            }

            $row = $scrap[$productId];
            // How far above the works' own normal this article sits. A ratio, not
            // a percentage-point gap, so a shop scrapping 1% and one scrapping
            // 12% both get a sensible answer.
            $ratio = $median > 0 ? $rate / $median : null;

            $items[] = [
                'product' => ['id' => $product->id, 'sku' => $product->sku, 'name' => $product->name],
                'scrap_quantity' => round((float) $row->qty, 3),
                'scrap_value' => round((float) $row->value, 2),
                'events' => (int) $row->events,
                'produced' => round((float) ($produced[$productId] ?? 0), 3),
                'rejected_at_qc' => round((float) ($rejected[$productId] ?? 0), 3),
                'scrap_rate_percent' => round($rate, 2),
                'times_median' => $ratio !== null ? round($ratio, 2) : null,
                'severity' => $this->scrapSeverity($ratio),
            ];
        }

        // Outliers first: worst multiple of the median, then biggest money.
        usort($items, fn ($a, $b) => [$b['times_median'] ?? 0, $b['scrap_value']] <=> [$a['times_median'] ?? 0, $a['scrap_value']]);

        return [
            'generated_at' => now()->toIso8601String(),
            'window_days' => $windowDays,
            'median_rate' => round($median, 2),
            'items' => $items,
            // Articles with scrap but no finished output yet — excluded from the
            // comparison rather than shown at 100%. See the loop above.
            'skipped_no_output' => $noOutput,
        ];
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    /** @return array<int, float> product_id => on-hand across warehouses */
    private function onHand(array $productIds): array
    {
        return WarehouseStock::query()
            ->whereIn('product_id', $productIds)
            ->selectRaw('product_id, SUM(quantity) as qty')
            ->groupBy('product_id')
            ->pluck('qty', 'product_id')
            ->map(fn ($q) => (float) $q)
            ->all();
    }

    /**
     * @return array<int, float> product_id => ordered but not yet received
     *
     * Netted per line rather than per order: a partially-received PO still has
     * the balance coming, and treating it as fully received would recreate the
     * double-buying this whole report exists to prevent.
     *
     * Deliberately the same statuses and the same shape as
     * InventoryIntelligenceService::onOrder() — two engines that disagreed about
     * what "on order" means would give a buyer two different answers on the same
     * afternoon.
     */
    private function onOrder(array $productIds): array
    {
        return PurchaseItem::query()
            ->whereIn('product_id', $productIds)
            ->whereHas('purchaseOrder', fn ($q) => $q->whereIn('status', [
                'draft', 'pending_approval', 'approved', 'partially_received',
            ]))
            ->selectRaw('product_id, SUM(quantity - received_quantity) as qty')
            ->groupBy('product_id')
            ->pluck('qty', 'product_id')
            ->map(fn ($v) => max(0.0, (float) $v))
            ->all();
    }

    private function median(array $values): float
    {
        sort($values);
        $n = count($values);
        if ($n === 0) {
            return 0.0;
        }
        $mid = intdiv($n, 2);

        return $n % 2 === 0 ? ($values[$mid - 1] + $values[$mid]) / 2 : $values[$mid];
    }

    /** How soon the material is wanted, which is the only urgency that matters here. */
    private function shortfallUrgency(?string $neededBy): string
    {
        if ($neededBy === null) {
            return 'medium';
        }

        $days = now()->startOfDay()->diffInDays(Carbon::parse($neededBy), false);

        return match (true) {
            $days < 0 => 'critical',   // the order is already late
            $days <= 7 => 'high',
            $days <= 21 => 'medium',
            default => 'low',
        };
    }

    private function scrapSeverity(?float $timesMedian): string
    {
        return match (true) {
            $timesMedian === null => 'low',
            $timesMedian >= 3 => 'critical',
            $timesMedian >= 2 => 'high',
            $timesMedian >= 1.25 => 'medium',
            default => 'low',
        };
    }
}
