<?php

declare(strict_types=1);

namespace App\Modules\Industry\Sports\Services;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\Setting;
use App\Models\Warehouse;
use App\Modules\Industry\Sports\Models\Rental;
use App\Services\BrokerCommissionService;
use App\Services\DocumentPricingService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Turning a finished hire into money, through the door every other document in
 * this ERP uses.
 *
 * A `SalesOrder` on the `rental` channel plus an `Invoice`, so receivables, GST,
 * the customer ledger, the cash book and every sales report see a hire exactly as
 * they see a counter sale. Nothing downstream has to learn that hire exists.
 *
 * Two decisions carry the weight here.
 *
 * **The deposit is not a sale.** A security deposit is the customer's money that
 * the shop is holding; it is not a supply, no tax is due on it, and putting it
 * through the sales spine would inflate turnover by the size of the fleet's float
 * and put GST on money that has to be handed back. So the invoice is raised for
 * the charges alone — hire, late fee, damage — and the deposit meets it as a
 * *tender*, on the `deposit` payment mode, for whatever part of it the shop is
 * keeping. What is left goes back to the customer and never appears in the books
 * as anything but the reduction of a liability.
 *
 * **Hire bills as a service, not as the article.** The invoice must never carry
 * the bicycle as a line: that reads as a sale, would tempt every downstream
 * fulfilment path into moving stock, and describes a transaction that did not
 * happen. Three `is_service` products carry the three charges, created on first
 * use, and the contract number in the order's notes is what ties the bill back to
 * the kit — the rental document itself holds the per-asset breakdown.
 */
class RentalBillingService
{
    public function __construct(
        private readonly DocumentPricingService $pricing,
        private readonly BrokerCommissionService $brokerCommissions,
    ) {}

    /**
     * Re-price a contract from its lines and write the totals onto the header.
     *
     * Called at every return so the header is always current, and again before
     * billing. The three charges are priced as three lines rather than summed
     * flat, so a header discount and the tax on it behave exactly as they do on
     * every other document here.
     */
    public function reprice(Rental $rental): Rental
    {
        $rental->loadMissing('items');

        $hire = round($rental->items->sum(fn ($i) => (float) $i->hire_charge), 2);
        $late = round($rental->items->sum(fn ($i) => (float) $i->late_charge), 2);
        $damage = round($rental->items->sum(fn ($i) => (float) $i->damage_charge), 2);

        $lines = [];
        foreach ($this->chargeProducts() as $key => $product) {
            $amount = ['hire' => $hire, 'late' => $late, 'damage' => $damage][$key];

            if ($amount <= 0) {
                continue;
            }

            $lines[] = [
                'quantity' => 1,
                'unit_price' => $amount,
                /**
                 * The tax rate lives on the charge product, not on this document.
                 * A shop sets it once in the catalogue — hire and a damage
                 * recovery are not always rated alike — and nothing here has to
                 * guess a SAC code on somebody's behalf.
                 */
                'tax_percent' => (float) $product->tax_percent,
                'discount_type' => null,
                'discount_value' => 0,
                '_charge' => $key,
                '_product_id' => $product->id,
            ];
        }

        if ($lines === []) {
            $rental->fill([
                'hire_total' => 0, 'late_total' => 0, 'damage_total' => 0,
                'discount_amount' => 0, 'tax_amount' => 0, 'round_off' => 0, 'total' => 0,
            ])->save();

            return $rental;
        }

        $priced = $this->pricing->price($lines, $rental->discount_type, (float) $rental->discount_value);
        $header = $priced['header'];

        $rental->fill([
            'hire_total' => $hire,
            'late_total' => $late,
            'damage_total' => $damage,
            'discount_amount' => $header['discount_amount'],
            'tax_amount' => $header['tax_amount'],
            'round_off' => $header['round_off'],
            'total' => $header['total'],
        ])->save();

        return $rental;
    }

    /**
     * Settle a returned contract: raise the bill, then apply as much of the
     * deposit to it as the shop means to keep.
     *
     * Idempotent — a contract that already carries an invoice returns it
     * untouched, so a double-click at a busy counter cannot bill a hire twice.
     *
     * @param  float|null  $applyDeposit  How much of the deposit to keep against
     *                                    the bill. Null means "as much as it
     *                                    takes", which is what a counter does
     *                                    without thinking about it; pass 0 to
     *                                    refund the deposit whole and let the
     *                                    customer settle the bill separately.
     */
    public function settle(Rental $rental, ?float $applyDeposit = null, ?int $userId = null): ?Invoice
    {
        if ($rental->invoice_id) {
            return $rental->invoice()->first();
        }

        $this->reprice($rental);
        $rental->loadMissing('items.asset');

        $total = (float) $rental->total;
        $deposit = (float) $rental->deposit_amount;

        /**
         * Nothing to bill and nothing held — a courtesy hire, or a booking that
         * was cancelled into a return. Closing it without a zero-value invoice is
         * the honest outcome: an invoice for nothing still lands in the customer's
         * ledger and in the GST return.
         */
        if ($total <= 0 && $deposit <= 0) {
            return null;
        }

        /**
         * Capped at both ends. More deposit than bill would turn the surplus into
         * an overpayment on an invoice, which is a credit the shop then owes and
         * cannot see; more bill than deposit is ordinary and the balance is
         * collected at the counter.
         */
        $applied = $applyDeposit === null
            ? min($deposit, $total)
            : max(0, min($applyDeposit, $deposit, $total));

        return DB::transaction(function () use ($rental, $total, $deposit, $applied, $userId) {
            $invoice = $total > 0 ? $this->raiseInvoice($rental, $userId) : null;

            if ($invoice && $applied > 0) {
                Payment::create([
                    'invoice_id' => $invoice->id,
                    'mode' => 'deposit',
                    'amount' => $applied,
                    'payment_date' => now()->toDateString(),
                    'notes' => "Deposit held on hire {$rental->rental_number}",
                    'created_by' => $userId,
                ]);

                $invoice->forceFill([
                    'paid_amount' => round((float) $invoice->paid_amount + $applied, 2),
                ])->save();
                $invoice->refreshStatus();
            }

            $rental->forceFill([
                'status' => 'closed',
                'deposit_applied' => $applied,
                // Whatever is left of the customer's money goes back. Recorded
                // even when it is the whole deposit, because "we gave it back" is
                // the thing that gets disputed.
                'deposit_refunded' => round($deposit - $applied, 2),
            ])->save();

            return $invoice;
        });
    }

    /** The sales order and invoice that carry a hire's value into the books. */
    private function raiseInvoice(Rental $rental, ?int $userId): Invoice
    {
        $products = $this->chargeProducts();

        $charges = [
            'hire' => (float) $rental->hire_total,
            'late' => (float) $rental->late_total,
            'damage' => (float) $rental->damage_total,
        ];

        $lines = [];
        foreach ($charges as $key => $amount) {
            if ($amount <= 0) {
                continue;
            }

            $lines[] = [
                'quantity' => 1,
                'unit_price' => $amount,
                'tax_percent' => (float) $products[$key]->tax_percent,
                'discount_type' => null,
                'discount_value' => 0,
                '_product_id' => $products[$key]->id,
            ];
        }

        $priced = $this->pricing->price($lines, $rental->discount_type, (float) $rental->discount_value);
        $header = $priced['header'];

        $salesOrder = SalesOrder::create([
            'so_number' => SalesOrder::nextNumber(),
            'customer_id' => $rental->customer_id,
            'warehouse_id' => $rental->warehouse_id ?? $this->fallbackWarehouseId($rental),
            'status' => 'delivered',
            'channel' => 'rental',
            'order_date' => now()->toDateString(),
            // Gross of every discount, the meaning `subtotal` has on every other
            // sales document — the contract's own charge columns are already net.
            'subtotal' => $header['subtotal'],
            'item_discount_total' => $header['item_discount_total'],
            'discount_type' => $rental->discount_type,
            'discount_value' => $rental->discount_value,
            'discount_amount' => $header['discount_amount'],
            'tax_amount' => $header['tax_amount'],
            'round_off' => $header['round_off'],
            'total' => $header['total'],
            'notes' => $this->documentNote($rental),
            'created_by' => $userId,
            'delivered_at' => now(),
        ]);

        foreach ($priced['lines'] as $line) {
            $salesOrder->items()->create([
                'product_id' => $line['source']['_product_id'],
                'variant_id' => null,
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

        $invoice = Invoice::create([
            'invoice_number' => Invoice::nextNumber(),
            'sales_order_id' => $salesOrder->id,
            'customer_id' => $rental->customer_id,
            'invoice_date' => now()->toDateString(),
            'total' => $header['total'],
            'paid_amount' => 0,
            'status' => 'unpaid',
        ]);

        $this->brokerCommissions->accrueForInvoice($invoice);

        $rental->forceFill([
            'sales_order_id' => $salesOrder->id,
            'invoice_id' => $invoice->id,
        ])->save();

        return $invoice;
    }

    /**
     * The three catalogue products a hire bills under, created on first use.
     *
     * A sales line needs a product and `sales_items.product_id` is not nullable,
     * so a charge with no article behind it is not representable — the same
     * constraint service labour and project hours already work within. All three
     * are `is_service`, which is what keeps them out of stock and off every
     * picking list.
     *
     * Split three ways rather than one because they are three different
     * conversations: a hire is income, a late fee is a penalty a customer will
     * query, and a damage recovery is a claim. A shop that wants them all on one
     * SAC can point all three at the same tax rate; a shop that does not, cannot
     * un-merge them afterwards.
     *
     * @return array{hire: Product, late: Product, damage: Product}
     */
    private function chargeProducts(): array
    {
        return [
            'hire' => $this->chargeProduct(
                'sports_rental_hire_sku', 'RENTAL-HIRE',
                'Equipment Hire', 'Hire charges billed on rental contracts.',
            ),
            'late' => $this->chargeProduct(
                'sports_rental_late_sku', 'RENTAL-LATE',
                'Late Return Charge', 'Overdue charges on rental contracts returned past their promised time.',
            ),
            'damage' => $this->chargeProduct(
                'sports_rental_damage_sku', 'RENTAL-DAMAGE',
                'Damage Recovery', 'Damage, loss and cleaning recovered against rental deposits.',
            ),
        ];
    }

    private function chargeProduct(string $settingKey, string $default, string $name, string $description): Product
    {
        $sku = (string) Setting::get($settingKey, $default);

        return Product::firstOrCreate(
            ['sku' => $sku],
            [
                'name' => $name,
                'description' => $description,
                'is_service' => true,
                'is_active' => true,
                'cost_price' => 0,
                'selling_price' => 0,
                // Left at zero deliberately. Guessing a tax rate on a shop's
                // behalf is worse than making it set one — see the note in
                // ServiceJobBillingService::labourProduct().
                'tax_percent' => 0,
            ],
        );
    }

    /** "Hire SPR-000014 — SPB-07, SPB-09 (2 items, 3 days)". */
    private function documentNote(Rental $rental): string
    {
        $rental->loadMissing('items.asset');

        $codes = $rental->items
            ->map(fn ($item) => $item->asset?->asset_code)
            ->filter()
            ->take(6)
            ->implode(', ');

        $more = $rental->items->count() > 6 ? ' …' : '';

        return "Hire {$rental->rental_number} — {$codes}{$more}";
    }

    /**
     * A sales order needs a warehouse even when the contract never named one.
     * Falls back rather than failing the bill — nothing moves through it.
     */
    private function fallbackWarehouseId(Rental $rental): int
    {
        $id = Warehouse::query()->orderBy('id')->value('id');

        if (! $id) {
            throw new RuntimeException("Create a warehouse before settling hire {$rental->rental_number}.");
        }

        return (int) $id;
    }
}
