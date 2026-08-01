<?php

declare(strict_types=1);

namespace App\Modules\Industry\Healthcare\Services;

use App\Models\WarehouseStock;
use App\Modules\Industry\Healthcare\Models\HospitalIndent;
use App\Modules\Industry\Healthcare\Models\HospitalIndentItem;
use App\Services\StockService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Issuing stock from the central store to a ward.
 *
 * The whole module comes down to this: goods leave the store, nothing is sold,
 * and the department is recorded so somebody can later ask what the ICU spent
 * last month. It posts straight to the stock ledger under `hospital_issue` — no
 * sales order, no invoice, no receivable that a department could never settle.
 *
 * Two decisions worth stating.
 *
 * **Partial issues are normal, not an error path.** A store releases what it has
 * and the ward chases the rest for days. So `issue()` takes a per-line quantity,
 * accumulates it, and the indent's status is *derived* from the lines rather
 * than set by whoever pressed the button.
 *
 * **Issues post with allowNegative false.** This is the opposite of the choice
 * the restaurant plugin makes, and for the opposite reason: a kitchen that
 * cannot close a bill stops trading, but a hospital store issuing gloves it does
 * not have is a stock record that has quietly stopped describing the shelf — and
 * on a controlled drug that record is the one an inspector reads. Refusing is
 * the safer failure here, and the message says exactly what is short.
 */
class HospitalStoreService
{
    public function __construct(
        private readonly StockService $stock,
    ) {}

    /**
     * Release quantities against an approved indent.
     *
     * @param  array<int, array{id: int, quantity: float}>  $lines  Per indent-item quantities to issue now.
     * @return array{issued: int, value: float}
     */
    public function issue(HospitalIndent $indent, array $lines, ?int $userId = null): array
    {
        if (! $indent->isIssuable()) {
            throw new RuntimeException(
                "Indent {$indent->indent_number} is {$indent->status} — only an approved indent can be issued.",
            );
        }

        $indent->loadMissing('items.product');
        $items = $indent->items->keyBy('id');

        $issued = 0;
        $value = 0.0;

        DB::transaction(function () use ($indent, $lines, $items, $userId, &$issued, &$value) {
            foreach ($lines as $line) {
                /** @var HospitalIndentItem|null $item */
                $item = $items->get($line['id'] ?? null);
                $quantity = round((float) ($line['quantity'] ?? 0), 3);

                if (! $item || $quantity <= 0) {
                    continue;
                }

                $pending = $item->pendingQuantity();

                if ($quantity > $pending) {
                    throw new RuntimeException(sprintf(
                        '%s: only %s left to issue against this indent (asked to issue %s).',
                        $item->product?->name ?? 'Item',
                        rtrim(rtrim(number_format($pending, 3), '0'), '.'),
                        rtrim(rtrim(number_format($quantity, 3), '0'), '.'),
                    ));
                }

                $this->assertAvailable($indent, $item, $quantity);

                $ledger = $this->stock->outward(
                    warehouseId: $indent->warehouse_id,
                    productId: $item->product_id,
                    variantId: $item->variant_id,
                    quantity: $quantity,
                    type: 'hospital_issue',
                    referenceType: HospitalIndent::class,
                    referenceId: $indent->id,
                    referenceNumber: $indent->indent_number,
                    userId: $userId,
                );

                /**
                 * Cost the issue at what the ledger actually charged out, not at
                 * the product's list cost: the workspace may be on FIFO, and a
                 * department's consumption value has to agree with the stock
                 * valuation it came out of or the two reports will not tie.
                 */
                $unitCost = (float) $ledger->unit_cost;
                $lineValue = round($quantity * $unitCost, 2);

                $item->forceFill([
                    'issued_quantity' => round((float) $item->issued_quantity + $quantity, 3),
                    'unit_cost' => $unitCost,
                    'issue_value' => round((float) $item->issue_value + $lineValue, 2),
                ])->save();

                $issued++;
                $value += $lineValue;
            }

            $indent->refreshIssueStatus();
        });

        return ['issued' => $issued, 'value' => round($value, 2)];
    }

    /**
     * Default every approved quantity to what was asked for.
     *
     * Called on approval so a store that agrees to the whole indent never has to
     * retype it, while one that trims a line can still send its own numbers.
     *
     * @param  array<int, array{id: int, quantity: float}>  $overrides
     */
    public function applyApprovedQuantities(HospitalIndent $indent, array $overrides = []): void
    {
        $byId = collect($overrides)->keyBy('id');

        foreach ($indent->items as $item) {
            $override = $byId->get($item->id);

            $approved = $override !== null
                ? round((float) $override['quantity'], 3)
                : (float) ($item->approved_quantity ?? $item->requested_quantity);

            $item->forceFill([
                // A store may cut a line but cannot invent one: approving more
                // than the ward asked for would put stock on a floor nobody
                // requested it for, with the indent as the only record.
                'approved_quantity' => min($approved, (float) $item->requested_quantity),
            ])->save();
        }
    }

    /**
     * Refuse an issue the store cannot physically make.
     *
     * The message names the item and both numbers because the person reading it
     * is standing at a counter with a nurse waiting, and "insufficient stock" on
     * its own tells them nothing they can act on.
     */
    private function assertAvailable(HospitalIndent $indent, HospitalIndentItem $item, float $quantity): void
    {
        $onHand = (float) (WarehouseStock::query()
            ->where('warehouse_id', $indent->warehouse_id)
            ->where('product_id', $item->product_id)
            ->where('variant_id', $item->variant_id)
            ->value('quantity') ?? 0);

        if ($quantity > $onHand) {
            throw new RuntimeException(sprintf(
                '%s: the store holds %s, cannot issue %s.',
                $item->product?->name ?? 'Item',
                rtrim(rtrim(number_format($onHand, 3), '0'), '.'),
                rtrim(rtrim(number_format($quantity, 3), '0'), '.'),
            ));
        }
    }
}
