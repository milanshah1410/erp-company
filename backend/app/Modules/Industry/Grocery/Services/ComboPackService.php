<?php

declare(strict_types=1);

namespace App\Modules\Industry\Grocery\Services;

use App\Models\WarehouseStock;
use App\Modules\Industry\Grocery\Models\ComboPack;
use App\Modules\Industry\Grocery\Models\ComboPackItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * What a combo pack does to stock.
 *
 * A pack is billed as one product and picked off the shelf as several, so the
 * document (the sales order line, the invoice, the GST return) keeps saying
 * "1 × Diwali Hamper" while the stock ledger has to say "1 × sugar, 1 × ghee,
 * 0.5 × dry fruit". This service is the single translation between the two, and
 * every caller that moves stock for a bill routes through it — sale, return and
 * exchange alike, because anything a sale takes out a return has to put back.
 *
 * Three deliberate choices:
 *
 *  - **The pack itself never moves.** It has no stock of its own; there is
 *    nothing on the shelf called "Diwali Hamper" until the counter hand makes one
 *    up. Posting an outward for it would drive a phantom SKU permanently
 *    negative.
 *  - **Expansion is resolved at billing time, not stored.** If a pack's contents
 *    change next month, last month's bill is still a bill for a pack — but its
 *    stock movements were already written line by line against the real products,
 *    so history stays truthful without a snapshot table.
 *  - **A lapsed pack does not explode.** Outside its window, or deactivated, the
 *    product is an ordinary product again and moves its own (non-existent) stock,
 *    which fails loudly rather than quietly emptying a shelf. That is the right
 *    failure: it means the shop is selling a hamper it has switched off.
 *
 * Only the POS counter expands packs. A pack put on a sales order and delivered
 * through the order lifecycle will be refused by StockService for want of stock
 * on the pack SKU — a loud failure rather than a silent mis-post, and the reason
 * a pack is documented as a counter product.
 */
class ComboPackService
{
    /**
     * Translate billed lines into the lines that should actually move stock.
     *
     * Every input line is either passed through untouched or replaced by its
     * pack's components. Quantities multiply: two hampers take two of everything.
     *
     * Rows are emitted one per source line and deliberately **not** merged. A
     * bill that lists the same product twice already writes two ledger movements
     * today, and folding them together here would quietly change the stock ledger
     * of every workspace on every counter sale — for a tidiness nobody asked for.
     *
     * `unit_cost` is what an *inward* (a return) should restock at. For a
     * pass-through line that is the line's own price, exactly as before; for a
     * component it is the component's cost price, because the pack's discounted
     * price says nothing about what the sugar inside it is worth.
     *
     * @param  iterable<array{product_id: int|string, variant_id: int|string|null, quantity: int|float|string, unit_price?: int|float|string}>  $lines
     * @return list<array{product_id: int, variant_id: int|null, quantity: float, unit_cost: float, from_pack: bool}>
     */
    public function stockLines(iterable $lines, ?Carbon $on = null): array
    {
        $lines = collect($lines);

        if ($lines->isEmpty()) {
            return [];
        }

        $packs = $this->packsFor(
            $lines->pluck('product_id')->map(fn ($id) => (int) $id)->unique()->all(),
            $on,
        );

        $out = [];

        foreach ($lines as $line) {
            $productId = (int) $line['product_id'];
            $quantity = (float) $line['quantity'];
            $pack = $packs[$productId] ?? null;

            if (! $pack) {
                $out[] = [
                    'product_id' => $productId,
                    'variant_id' => isset($line['variant_id']) && $line['variant_id'] !== null
                        ? (int) $line['variant_id']
                        : null,
                    'quantity' => $quantity,
                    'unit_cost' => round((float) ($line['unit_price'] ?? 0), 4),
                    'from_pack' => false,
                ];

                continue;
            }

            foreach ($pack->items as $item) {
                $componentQty = round((float) $item->quantity * $quantity, 4);

                // A zero component would post a movement that says nothing.
                if ($componentQty <= 0) {
                    continue;
                }

                $out[] = [
                    'product_id' => (int) $item->product_id,
                    'variant_id' => $item->variant_id === null ? null : (int) $item->variant_id,
                    'quantity' => $componentQty,
                    'unit_cost' => round((float) ($item->product?->cost_price ?? 0), 4),
                    'from_pack' => true,
                ];
            }
        }

        return $out;
    }

    /**
     * Live packs among the given product ids, keyed by the pack's own product id.
     *
     * One query per bill regardless of how many lines it has. Returns an empty
     * collection when the tenant has no packs at all, which is the common case
     * and the reason this is cheap enough to sit in the POS transaction.
     *
     * @param  list<int>  $productIds
     * @return Collection<int, ComboPack>
     */
    public function packsFor(array $productIds, ?Carbon $on = null): Collection
    {
        if ($productIds === []) {
            return collect();
        }

        return ComboPack::query()
            ->live($on)
            ->whereIn('product_id', $productIds)
            ->with([
                'items:id,grocery_combo_pack_id,product_id,variant_id,quantity',
                // Cost only: what a return should restock a component at.
                'items.product:id,cost_price',
            ])
            ->get()
            ->keyBy(fn (ComboPack $pack) => (int) $pack->product_id);
    }

    /** Is this product a pack the till would break apart today? */
    public function isPack(int $productId, ?Carbon $on = null): bool
    {
        return $this->packsFor([$productId], $on)->has($productId);
    }

    /**
     * How many of each pack could be made up from what is on the shelf right now.
     *
     * The POS grid shows on-hand per product, and a pack has none — so without
     * this every hamper reads as out of stock while the counter is perfectly able
     * to sell one. The answer is the scarcest component: a hamper needing 1kg of
     * ghee when there are two left is two hampers, however much sugar there is.
     *
     * @param  list<int>  $productIds  pack product ids to answer for
     * @return array<int, float>  pack product id => buildable packs
     */
    public function buildableQuantities(array $productIds, int $warehouseId, ?Carbon $on = null): array
    {
        $packs = $this->packsFor($productIds, $on);

        if ($packs->isEmpty()) {
            return [];
        }

        $componentIds = $packs
            ->flatMap(fn (ComboPack $pack) => $pack->items->pluck('product_id'))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->all();

        $onHand = WarehouseStock::query()
            ->where('warehouse_id', $warehouseId)
            ->whereIn('product_id', $componentIds)
            ->whereNull('variant_id')
            ->get(['product_id', 'quantity', 'reserved_quantity'])
            ->keyBy('product_id');

        $out = [];

        foreach ($packs as $packProductId => $pack) {
            $buildable = null;

            foreach ($pack->items as $item) {
                $need = (float) $item->quantity;

                if ($need <= 0) {
                    continue;
                }

                $stock = $onHand->get((int) $item->product_id);
                $available = $stock
                    ? (float) $stock->quantity - (float) $stock->reserved_quantity
                    : 0.0;

                $possible = floor(max(0.0, $available) / $need);
                $buildable = $buildable === null ? $possible : min($buildable, $possible);
            }

            // A pack whose every line is a zero quantity constrains nothing and
            // is a broken definition, not an infinite supply.
            $out[(int) $packProductId] = (float) ($buildable ?? 0);
        }

        return $out;
    }

    /**
     * Where a product is used as a component, for the "may I delete this?"
     * question the product master asks.
     *
     * @return list<string>  pack names
     */
    public function packsContaining(int $productId): array
    {
        return ComboPackItem::query()
            ->where('product_id', $productId)
            ->with('pack:id,name')
            ->get()
            ->map(fn (ComboPackItem $item) => (string) ($item->pack?->name ?? ''))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
