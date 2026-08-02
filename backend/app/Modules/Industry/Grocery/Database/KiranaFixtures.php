<?php

declare(strict_types=1);

namespace App\Modules\Industry\Grocery\Database;

use App\Models\Product;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Modules\Industry\Grocery\Models\ComboPack;
use Illuminate\Support\Carbon;

/**
 * The kirana half of this vertical's demo fixtures — combo packs and the khata.
 *
 * Kept beside DemoSeeder rather than inside it because the two halves are gated
 * on different modules and are read by different people: everything here is
 * behind `grocery` and belongs to both trades, while the shelf and the lane are
 * behind `supermarket` and belong to one. DemoSeeder is the convention-found
 * entry point and simply calls this first.
 *
 * A grocery login that opens onto an empty khata demonstrates nothing about
 * being a kirana, so this seeds a shop mid-week: two hampers on the shelf (one
 * of them priced badly, because that is the mistake the screen exists to catch)
 * and three distributors in the three states a shopkeeper actually recognises —
 * one clear, one late, and one quietly supplied past their limit.
 *
 * The awkward rows are the point. A pack whose margin has gone negative after a
 * price rise, and a distributor sitting over their limit with a lorry's worth of
 * goods still unbilled, are the two situations that cost this trade money and
 * the two a supplier-wise total in an ageing report cannot show.
 *
 * Idempotent: parties and products are matched on their code or SKU, bills on
 * their number, and a pack on the product behind it — a re-seed refreshes the
 * shop rather than doubling it.
 */
class KiranaFixtures
{
    public function seed(int $userId): void
    {
        $products = $this->catalogue();
        $this->stock($products);
        $this->combos($products, $userId);

        $suppliers = $this->suppliers();
        $this->ledger($suppliers, $userId);
    }

    /**
     * The shelf: six fast movers and two hamper SKUs.
     *
     * The hampers are ordinary products with a price and a barcode and no stock
     * of their own — which is exactly what a combo pack is, and the quickest way
     * to see that the till takes the components off the shelf instead.
     *
     * @return array<string, Product>
     */
    private function catalogue(): array
    {
        $rows = [
            // key => [sku, name, cost, price]
            'sugar' => ['GR-SUG-1', 'Sugar 1kg', 42.00, 48.00],
            'ghee' => ['GR-GHEE-1', 'Pure Ghee 1L', 560.00, 615.00],
            'dryfruit' => ['GR-DRY-500', 'Dry Fruit Mix 500g', 340.00, 390.00],
            'atta' => ['GR-ATTA-5', 'Wheat Atta 5kg', 225.00, 255.00],
            'oil' => ['GR-OIL-1', 'Sunflower Oil 1L', 118.00, 132.00],
            'soap' => ['GR-SOAP-1', 'Bath Soap 100g', 28.00, 34.00],
            /**
             * The two packs. Neither carries a cost of its own: what a hamper
             * costs the shop is what its contents cost, which is snapshotted
             * onto the pack when it is saved.
             */
            'hamper' => ['GR-PK-DIWALI', 'Diwali Hamper', 0.00, 999.00],
            'monthly' => ['GR-PK-MONTHLY', 'Monthly Basics Pack', 0.00, 395.00],
        ];

        $out = [];

        foreach ($rows as $key => [$sku, $name, $cost, $price]) {
            $out[$key] = Product::firstOrCreate(
                ['sku' => $sku],
                [
                    'name' => $name,
                    'cost_price' => $cost,
                    'selling_price' => $price,
                    'tax_percent' => 5,
                    'is_active' => true,
                ],
            );
        }

        return $out;
    }

    /**
     * Something on the shelf, so the pack screen's "buildable" column has an
     * answer.
     *
     * Ghee is deliberately the scarce one: the Diwali hamper needs a litre and
     * there are four, so the pack reads as four buildable however much sugar is
     * behind the counter. That is the whole rule, visible in one number.
     *
     * @param  array<string, Product>  $products
     */
    private function stock(array $products): void
    {
        $warehouse = $this->warehouse();

        if (! $warehouse) {
            return;
        }

        $quantities = [
            'sugar' => 60, 'ghee' => 4, 'dryfruit' => 12,
            'atta' => 30, 'oil' => 25, 'soap' => 80,
        ];

        foreach ($quantities as $key => $quantity) {
            if (! isset($products[$key])) {
                continue;
            }

            WarehouseStock::updateOrCreate(
                [
                    'warehouse_id' => $warehouse->id,
                    'product_id' => $products[$key]->id,
                    'variant_id' => null,
                ],
                ['quantity' => $quantity, 'reserved_quantity' => 0],
            );
        }
    }

    /**
     * Two packs, one of them wrong.
     *
     * The Diwali hamper works: ₹1,053 of goods for ₹999, a ₹54 saving on a ₹57
     * margin. The Monthly Basics pack is the cautionary one — atta, oil and two
     * soaps are ₹455 of shelf value for ₹395, which reads as a generous ₹60
     * saving right up until you notice the contents cost the shop ₹399 and every
     * pack sold loses ₹4. It is the row flagged red under "below cost", and the
     * entire reason this screen carries a cost column at all.
     *
     * @param  array<string, Product>  $products
     */
    private function combos(array $products, int $userId): void
    {
        $definitions = [
            'hamper' => [
                'name' => 'Diwali Hamper',
                'items' => [['sugar', 1], ['ghee', 1], ['dryfruit', 1]],
                /**
                 * Runs for the festival and stops on its own. A lapsed pack
                 * leaves the SKU sellable and simply stops breaking apart, which
                 * is the behaviour worth having on screen.
                 */
                'starts_on' => Carbon::now()->subDays(10)->toDateString(),
                'ends_on' => Carbon::now()->addDays(20)->toDateString(),
            ],
            'monthly' => [
                'name' => 'Monthly Basics Pack',
                'items' => [['atta', 1], ['oil', 1], ['soap', 2]],
                'starts_on' => null,
                'ends_on' => null,
            ],
        ];

        foreach ($definitions as $key => $definition) {
            if (! isset($products[$key])) {
                continue;
            }

            $pack = ComboPack::firstOrNew(['product_id' => $products[$key]->id]);

            $pack->fill([
                'name' => $definition['name'],
                'starts_on' => $definition['starts_on'],
                'ends_on' => $definition['ends_on'],
                'is_active' => true,
                'created_by' => $userId,
            ])->save();

            // Rewritten wholesale, exactly as the controller does — a re-seed
            // must not stack a second set of lines onto the same pack.
            $pack->items()->delete();

            foreach ($definition['items'] as $index => [$componentKey, $quantity]) {
                if (! isset($products[$componentKey])) {
                    continue;
                }

                $pack->items()->create([
                    'product_id' => $products[$componentKey]->id,
                    'quantity' => $quantity,
                    'sort_order' => $index,
                ]);
            }

            $pack->load('items.product:id,selling_price,cost_price');
            $pack->revalue();
        }
    }

    /**
     * Three distributors, each with terms of their own — which is the point. The
     * limit and the credit period have sat unread on the supplier master since
     * the beginning, and a demo where all three are blank shows nothing.
     *
     * @return array<string, Supplier>
     */
    private function suppliers(): array
    {
        $rows = [
            // key => [code, name, phone, credit limit, terms]
            'patel' => ['GR-S01', 'Patel Agencies', '9824033001', 150000, 15],
            'shakti' => ['GR-S02', 'Shakti Distributors', '9824033002', 80000, 21],
            'anand' => ['GR-S03', 'Anand Foods Depot', '9824033003', 40000, 7],
        ];

        $out = [];

        foreach ($rows as $key => [$code, $name, $phone, $limit, $terms]) {
            $out[$key] = Supplier::firstOrCreate(
                ['code' => $code],
                [
                    'name' => $name,
                    'phone' => $phone,
                    'credit_limit' => $limit,
                    'payment_terms_days' => $terms,
                    'is_active' => true,
                ],
            );
        }

        return $out;
    }

    /**
     * The khata itself, in the three states a shopkeeper recognises.
     *
     *  - **Patel Agencies is late.** Two bills, the older three weeks past a
     *    15-day term, which is what puts them at the top of the payment list.
     *  - **Shakti Distributors is clear on the ledger and still over their
     *    limit**, because a lorry's worth of goods is booked and not yet billed.
     *    That gap between outstanding and exposure is the reason the pipeline
     *    column exists, and the one thing a payables report cannot show.
     *  - **Anand Foods Depot is simply due this week**, on the shortest terms of
     *    the three — the ordinary case, for contrast.
     *
     * @param  array<string, Supplier>  $suppliers
     */
    private function ledger(array $suppliers, int $userId): void
    {
        $warehouse = $this->warehouse();

        if (! $warehouse) {
            return;
        }

        $bills = [
            // supplier key => [bill number, days since it was raised, terms, total]
            ['patel', 'GR-B-1041', 36, 15, 62400.00],
            ['patel', 'GR-B-1058', 9, 15, 38150.00],
            ['anand', 'GR-B-2207', 4, 7, 17800.00],
        ];

        foreach ($bills as [$key, $number, $daysAgo, $terms, $total]) {
            if (! isset($suppliers[$key])) {
                continue;
            }

            $invoiceDate = Carbon::now()->subDays($daysAgo)->startOfDay();

            /**
             * A vendor invoice needs an order behind it — the schema requires
             * one, and a bill with no purchase order is not a bill this ERP can
             * raise.
             */
            $order = $this->order($suppliers[$key], $warehouse->id, $number, $invoiceDate, $total, $userId, received: true);

            PurchaseInvoice::firstOrCreate(
                ['supplier_id' => $suppliers[$key]->id, 'invoice_number' => $number],
                [
                    'purchase_order_id' => $order->id,
                    'invoice_date' => $invoiceDate->toDateString(),
                    'due_date' => $invoiceDate->copy()->addDays($terms)->toDateString(),
                    'subtotal' => $total,
                    'tax_amount' => 0,
                    'total' => $total,
                    'paid_amount' => 0,
                    'payment_status' => 'unpaid',
                    'status' => 'approved',
                    'created_by' => $userId,
                    'approved_by' => $userId,
                    'approved_at' => $invoiceDate,
                ],
            );
        }

        // Shakti's unbilled lorry: an approved order with no invoice against it,
        // which is what pushes them past an ₹80,000 limit while their ledger
        // reads clear.
        if (isset($suppliers['shakti'])) {
            $this->order(
                $suppliers['shakti'],
                $warehouse->id,
                'GR-PO-3310',
                Carbon::now()->subDays(3)->startOfDay(),
                92500.00,
                $userId,
                received: false,
            );
        }
    }

    /**
     * One purchase order, matched on its number so a re-seed finds it again.
     *
     * `$received` only sets the status. What makes an order count as pipeline is
     * the absence of a bill against it, not its status — which is exactly how
     * SupplierCreditService measures it.
     */
    private function order(
        Supplier $supplier,
        int $warehouseId,
        string $reference,
        Carbon $date,
        float $total,
        int $userId,
        bool $received,
    ): PurchaseOrder {
        return PurchaseOrder::firstOrCreate(
            ['po_number' => 'PO-'.$reference],
            [
                'supplier_id' => $supplier->id,
                'warehouse_id' => $warehouseId,
                'status' => $received ? 'received' : 'approved',
                'order_date' => $date->toDateString(),
                'subtotal' => $total,
                'tax_amount' => 0,
                'total' => $total,
                'created_by' => $userId,
                'approved_by' => $userId,
                'approved_at' => $date,
            ],
        );
    }

    private function warehouse(): ?Warehouse
    {
        // No default-warehouse flag exists on the master; the oldest active
        // godown stands in, which for a kirana is the only one it has.
        return Warehouse::query()->where('is_active', true)->orderBy('id')->first();
    }
}
