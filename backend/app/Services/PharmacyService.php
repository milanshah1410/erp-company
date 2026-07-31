<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Invoice;
use App\Models\Prescription;
use App\Models\PrescriptionDispense;
use App\Models\Product;
use App\Models\SalesOrder;
use RuntimeException;

/**
 * The rules a chemist works under (SRS: Medical Store — Schedule Drugs).
 *
 * Two of them, and both are legal rather than commercial:
 *
 *  1. A Schedule H, H1 or X medicine may not be handed over without a
 *     registered practitioner's prescription. {@see assertSellable()} is the
 *     one place that decides, so the POS, the sales-order path and anything
 *     added later cannot disagree about it.
 *
 *  2. Every H1 and X sale must be entered in a register kept for three years —
 *     patient, prescriber, drug, quantity, date. {@see recordDispense()} writes
 *     that entry as the sale is made, because a register reconstructed later
 *     from invoices is exactly what an inspection is meant to catch.
 *
 * Schedule G needs a caution label but no slip, which is why it is called out
 * separately from H/H1/X throughout.
 */
class PharmacyService
{
    /**
     * The scheduled products among a set of sale lines, keyed by product id.
     *
     * One query rather than a lookup per line: a pharmacy bill is routinely
     * twenty items long and most of them are not scheduled at all.
     *
     * @param  array<int, array<string, mixed>>  $items  Sale lines carrying product_id.
     * @return \Illuminate\Support\Collection<int, Product>
     */
    public function scheduledProductsIn(array $items)
    {
        $ids = array_values(array_unique(array_filter(array_column($items, 'product_id'))));

        if ($ids === []) {
            return collect();
        }

        return Product::query()
            ->whereIn('id', $ids)
            ->whereIn('drug_schedule', Product::PRESCRIPTION_SCHEDULES)
            ->get(['id', 'sku', 'name', 'drug_schedule'])
            ->keyBy('id');
    }

    /**
     * Refuse a sale that hands over scheduled medicine without a usable
     * prescription behind it.
     *
     * @param  array<int, array<string, mixed>>  $items
     *
     * @throws RuntimeException with a message the counter can act on.
     */
    public function assertSellable(array $items, ?Prescription $prescription): void
    {
        $scheduled = $this->scheduledProductsIn($items);

        if ($scheduled->isEmpty()) {
            return;
        }

        if (! $prescription) {
            $names = $scheduled->map(
                fn (Product $p) => "{$p->name} (Schedule ".strtoupper($p->drug_schedule).')',
            )->implode(', ');

            throw new RuntimeException(
                "A prescription is required to dispense: {$names}.",
            );
        }

        if ($reason = $prescription->blockedReason()) {
            throw new RuntimeException($reason);
        }
    }

    /**
     * Write the register entries for a completed sale.
     *
     * Only H1 and X are entered — those are the schedules the rules require a
     * retained register for. Plain Schedule H needs the prescription but not
     * the book, and padding the register with entries the law does not ask for
     * makes the ones it does ask for harder to find.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return int Number of register entries written.
     */
    public function recordDispense(
        Prescription $prescription,
        array $items,
        ?SalesOrder $salesOrder = null,
        ?Invoice $invoice = null,
        ?int $userId = null,
    ): int {
        $scheduled = $this->scheduledProductsIn($items);

        if ($scheduled->isEmpty()) {
            return 0;
        }

        $written = 0;

        foreach ($items as $item) {
            $product = $scheduled->get($item['product_id'] ?? null);

            if (! $product || ! in_array($product->drug_schedule, PrescriptionDispense::REGISTERED_SCHEDULES, true)) {
                continue;
            }

            PrescriptionDispense::create([
                'prescription_id' => $prescription->id,
                'invoice_id' => $invoice?->id,
                'sales_order_id' => $salesOrder?->id,
                'product_id' => $product->id,
                'variant_id' => $item['variant_id'] ?? null,
                'batch_id' => $item['batch_id'] ?? null,
                // Snapshotted: a later rename or reclassification must not
                // rewrite a register entry an inspector has already seen.
                'product_name' => $product->name,
                'drug_schedule' => $product->drug_schedule,
                'quantity' => $item['quantity'],
                'dispensed_on' => now()->toDateString(),
                'created_by' => $userId,
            ]);

            $written++;
        }

        return $written;
    }

    /**
     * What the counter should be told about a cart before it rings it up:
     * which lines need a slip, and which will land in the register.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array{requires_prescription: bool, register_entries: int, lines: list<array<string, mixed>>}
     */
    public function inspect(array $items): array
    {
        $scheduled = $this->scheduledProductsIn($items);

        $lines = [];
        $registerEntries = 0;

        foreach ($items as $item) {
            $product = $scheduled->get($item['product_id'] ?? null);

            if (! $product) {
                continue;
            }

            $registered = in_array($product->drug_schedule, PrescriptionDispense::REGISTERED_SCHEDULES, true);
            $registerEntries += $registered ? 1 : 0;

            $lines[] = [
                'product_id' => $product->id,
                'sku' => $product->sku,
                'name' => $product->name,
                'drug_schedule' => $product->drug_schedule,
                'enters_register' => $registered,
            ];
        }

        return [
            'requires_prescription' => $lines !== [],
            'register_entries' => $registerEntries,
            'lines' => $lines,
        ];
    }
}
