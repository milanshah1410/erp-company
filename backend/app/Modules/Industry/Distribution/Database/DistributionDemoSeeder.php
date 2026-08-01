<?php

declare(strict_types=1);

namespace App\Modules\Industry\Distribution\Database;

use App\Models\Beat;
use App\Models\BeatCustomer;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Salesman;
use App\Models\SalesOrder;
use App\Models\Unit;
use App\Models\Warehouse;

/**
 * Demo fixtures for the Distribution & Wholesale trades.
 *
 * Lives inside the plugin, next to its migrations and routes, for the same reason
 * those do: the vertical should be one folder you can drop in. DemoShopSeeder
 * calls this for any distribution trade and knows nothing about credit holds.
 *
 * What it exists to demonstrate is a *book*, not a feature. Credit Control and
 * Outstanding & Ageing are both screens whose whole meaning is the spread across
 * parties — one comfortably inside its limit, one over it and three months late,
 * one stopped outright — so a workspace with five identical well-behaved
 * customers demonstrates nothing at all. Hence three deliberately different
 * parties, each with invoices aged into different buckets.
 *
 * The invoices carry explicit `due_date`s rather than letting the model derive
 * them, because the point is the *age*: an invoice raised 130 days ago on 45-day
 * terms has to land in "90+" for the screen to be worth looking at.
 *
 * Deliberately self-sufficient — it creates the product, parties and beat it
 * needs rather than depending on DEMO_SHOP_DATA having run, so a plain
 * `db:seed --class=DemoShopSeeder` still produces a demo you can click through.
 * Everything is keyed on a `DIST-` prefix, so a re-seed refreshes in place
 * instead of stacking a second set.
 *
 * Must be called inside a TenantContext.
 */
class DistributionDemoSeeder
{
    /** SKU/code prefix for everything this seeder owns, so a re-run is idempotent. */
    private const PREFIX = 'DIST';

    public function __construct(private readonly ?int $userId = null) {}

    /**
     * Seed the fixtures one trade needs.
     *
     * Unknown trades are a no-op rather than an error: DemoShopSeeder passes every
     * profile key it has, and a non-distribution trade has nothing here.
     */
    public function seed(string $trade): void
    {
        match ($trade) {
            // Every distribution trade gets the credit book — it is what the
            // vertical is.
            'wholesale', 'fmcg_distribution', 'hardware_wholesale' => $this->fieldTrade(),
            // A C&F agent holds a principal's stock across godowns, so it also
            // gets a second warehouse to transfer between.
            'super_stockist' => $this->superStockist(),
            /**
             * Cash & carry gets the parties but no beat: the buyer walks in with a
             * GSTIN and carries the goods away himself. Leaving a beat out is the
             * honest demo — this trade has no salesman to send.
             */
            'cash_carry' => $this->parties(),
            default => null,
        };
    }

    /*
    |--------------------------------------------------------------------------
    | Trades
    |--------------------------------------------------------------------------
    */

    /** The credit book plus the beat that reaches it. */
    private function fieldTrade(): void
    {
        $this->parties();
        $this->beat();
    }

    private function superStockist(): void
    {
        $this->fieldTrade();

        // A transit godown, so stock transfers between godowns have somewhere to
        // go and the multi-warehouse story is visible on a fresh workspace.
        Warehouse::updateOrCreate(
            ['code' => self::PREFIX.'-WH-TRANSIT'],
            ['name' => 'Transit Godown', 'type' => 'transit', 'is_active' => true],
        );
    }

    /*
    |--------------------------------------------------------------------------
    | The book
    |--------------------------------------------------------------------------
    */

    /**
     * Three parties, chosen so every standing the Credit Control screen can show
     * is on screen at once: healthy, over-limit-and-overdue, and stopped.
     */
    private function parties(): void
    {
        $product = $this->tradedProduct();

        // 1. A well-run chemist-style account on tight terms, inside its limit.
        //    Its one invoice is not yet due, which is what "Not Due" is for.
        $good = $this->party('A1', 'Shree Traders', 'Rajkot', limit: 200000, terms: 7);
        $this->agedInvoice($good, $product, ageDays: 4, terms: 7, amount: 42000, paidRatio: 0);

        /**
         * 2. The party the screen exists for: over its limit, with money in three
         *    different buckets. The oldest bill is part-paid, which is how these
         *    actually look — a party pays something to keep supply coming and the
         *    balance ages anyway.
         */
        //    The ages are chosen against the 30-day terms so that every bucket on
        //    the ageing screen has something in it — an empty column reads as a
        //    bug in the report rather than as a fact about the party.
        $stretched = $this->party('A2', 'Balaji Distributors', 'Surat', limit: 150000, terms: 30);
        $this->agedInvoice($stretched, $product, ageDays: 140, terms: 30, amount: 88000, paidRatio: 0.4); // 110 late
        $this->agedInvoice($stretched, $product, ageDays: 105, terms: 30, amount: 47000, paidRatio: 0);   //  75 late
        $this->agedInvoice($stretched, $product, ageDays: 75, terms: 30, amount: 64000, paidRatio: 0);    //  45 late
        $this->agedInvoice($stretched, $product, ageDays: 40, terms: 30, amount: 55000, paidRatio: 0);    //  10 late
        $this->agedInvoice($stretched, $product, ageDays: 12, terms: 30, amount: 30000, paidRatio: 0);    //  not due

        /**
         * 3. A stopped party. The hold is the fixture: with it in place, trying to
         *    confirm an order, bill at the counter or ship a packed order all
         *    refuse with the same reason — which is the behaviour worth showing.
         */
        $held = $this->party('A3', 'Nova Agencies', 'Ahmedabad', limit: 100000, terms: 45);
        $this->agedInvoice($held, $product, ageDays: 95, terms: 45, amount: 76000, paidRatio: 0);
        $held->update([
            'credit_hold' => true,
            'credit_hold_reason' => 'Cheque returned twice — cash only until cleared',
            'credit_hold_at' => now()->subDays(3),
            'credit_hold_by' => $this->userId,
        ]);
    }

    /** A salesman with a beat, and all three parties as sequenced stops on it. */
    private function beat(): void
    {
        $salesman = Salesman::updateOrCreate(
            ['code' => self::PREFIX.'-SM-01'],
            [
                'name' => 'Mahesh Rawal',
                'mobile' => '9820011223',
                'territory' => 'Saurashtra',
                'default_incentive_percent' => 1.5,
                'is_active' => true,
            ],
        );

        $beat = Beat::updateOrCreate(
            ['name' => 'Monday Market Beat'],
            [
                'salesman_id' => $salesman->id,
                'day_of_week' => 'mon',
                'area' => 'Rajkot',
                'notes' => 'Wholesale market lane — collections on the same round.',
                'is_active' => true,
            ],
        );

        $codes = [self::PREFIX.'-A1', self::PREFIX.'-A2', self::PREFIX.'-A3'];
        foreach (array_values(Customer::whereIn('code', $codes)->pluck('id')->all()) as $index => $customerId) {
            BeatCustomer::firstOrCreate(
                ['beat_id' => $beat->id, 'customer_id' => $customerId],
                ['sequence' => $index + 1],
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    private function party(string $code, string $name, string $city, float $limit, int $terms): Customer
    {
        return Customer::updateOrCreate(
            ['code' => self::PREFIX.'-'.$code],
            [
                'name' => $name,
                'company_name' => $name,
                'phone' => sprintf('98200%05d', crc32($code) % 100000),
                'type' => 'wholesale',
                'city' => $city,
                'state' => 'Gujarat',
                'country' => 'India',
                'credit_limit' => $limit,
                'payment_terms_days' => $terms,
                'opening_balance' => 0,
                // Cleared explicitly: the fixture that sets a hold sets it after
                // this call, and a re-seed must not leave last run's hold on a
                // party this run means to be healthy.
                'credit_hold' => false,
                'credit_hold_reason' => null,
                'credit_hold_at' => null,
                'credit_hold_by' => null,
                'is_active' => true,
            ],
        );
    }

    /** One bulk line the demo invoices are raised for. */
    private function tradedProduct(): Product
    {
        $category = Category::firstOrCreate(
            ['slug' => 'dist-fmcg'],
            ['name' => 'FMCG / Bulk'],
        );

        return Product::updateOrCreate(
            ['sku' => self::PREFIX.'-CASE-01'],
            [
                'name' => 'Detergent Powder 1kg (Case of 12)',
                'category_id' => $category->id,
                'unit_id' => Unit::where('short_name', 'box')->value('id'),
                'cost_price' => 960,
                'selling_price' => 1100,
                'tax_percent' => 18,
                'is_active' => true,
            ],
        );
    }

    /**
     * A delivered order and the invoice raised against it, dated into the past.
     *
     * No stock is posted, exactly as DemoDataGenerator::makeInvoices does not post
     * it: the fixture is here to age a *receivable*, and moving stock backwards in
     * time would leave the ledger claiming a despatch the warehouse never saw.
     */
    private function agedInvoice(Customer $customer, Product $product, int $ageDays, int $terms, float $amount, float $paidRatio): void
    {
        $date = now()->subDays($ageDays)->startOfDay();
        $number = self::PREFIX.'-'.$customer->code.'-'.$ageDays;
        $warehouseId = Warehouse::where('code', 'WH-MAIN')->value('id')
            ?? Warehouse::value('id');

        $quantity = max(1, (int) round($amount / max(1, (float) $product->selling_price)));
        $subtotal = round($quantity * (float) $product->selling_price, 2);
        $tax = round($subtotal * ((float) $product->tax_percent / 100), 2);

        $order = SalesOrder::updateOrCreate(
            ['so_number' => $number.'-SO'],
            [
                'customer_id' => $customer->id,
                'warehouse_id' => $warehouseId,
                'status' => 'delivered',
                'channel' => 'standard',
                'order_date' => $date->toDateString(),
                'subtotal' => $subtotal,
                'tax_amount' => $tax,
                'total' => round($subtotal + $tax, 2),
                'created_by' => $this->userId,
                'delivered_at' => $date,
            ],
        );

        if ($order->items()->count() === 0) {
            $order->items()->create([
                'product_id' => $product->id,
                'quantity' => $quantity,
                'unit_price' => $product->selling_price,
                'tax_percent' => $product->tax_percent,
                'tax_amount' => $tax,
                'total' => round($subtotal + $tax, 2),
            ]);
        }

        $total = (float) $order->total;
        $paid = round($total * $paidRatio, 2);

        $invoice = Invoice::updateOrCreate(
            ['invoice_number' => $number],
            [
                'sales_order_id' => $order->id,
                'customer_id' => $customer->id,
                'invoice_date' => $date->toDateString(),
                // Explicit, so the age is the fixture's own rather than whatever
                // the workspace's default term happens to be today.
                'due_date' => $date->copy()->addDays($terms)->toDateString(),
                'total' => $total,
                'paid_amount' => $paid,
                'status' => $paid <= 0 ? 'unpaid' : ($paid >= $total ? 'paid' : 'partial'),
            ],
        );

        if ($paid > 0) {
            Payment::updateOrCreate(
                ['invoice_id' => $invoice->id, 'amount' => $paid],
                [
                    'mode' => 'upi',
                    // Part-paid on the due date, which is why the rest is late.
                    'payment_date' => $date->copy()->addDays($terms)->toDateString(),
                    'notes' => 'Part payment against '.$number,
                    'created_by' => $this->userId,
                ],
            );
        }
    }
}
