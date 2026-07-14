<?php

namespace Tests\Feature;

use App\Models\Broker;
use App\Models\BrokerCommission;
use App\Models\BrokerWalletTransaction;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceBroker;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\SalesReturn;
use App\Models\Tenant;
use App\Models\Warehouse;
use App\Services\BrokerCommissionService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class BrokerCommissionTest extends TestCase
{
    use RefreshDatabase;

    private BrokerCommissionService $service;

    private Tenant $tenant;

    private Broker $broker;

    private Customer $customer;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['company_name' => 'Test Co', 'status' => 'active']);
        app(TenantContext::class)->set($this->tenant->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);

        $this->service = app(BrokerCommissionService::class);
        $this->broker = Broker::create([
            'code' => 'BRK1', 'name' => 'Dalal Bhai',
            'commission_percent' => 10, 'fixed_commission' => 50, 'is_active' => true,
        ]);
        $this->customer = Customer::forceCreate(['code' => 'C1', 'name' => 'Buyer', 'type' => 'retail']);
        $this->warehouse = Warehouse::forceCreate(['code' => 'WH1', 'name' => 'Main', 'type' => 'main']);
    }

    private function makeInvoice(float $subtotal, float $tax = 0.0): Invoice
    {
        $total = $subtotal + $tax;
        $so = SalesOrder::create([
            'so_number' => SalesOrder::nextNumber(),
            'customer_id' => $this->customer->id,
            'broker_id' => $this->broker->id,
            'warehouse_id' => $this->warehouse->id,
            'status' => 'delivered',
            'order_date' => now()->toDateString(),
            'subtotal' => $subtotal,
            'tax_amount' => $tax,
            'total' => $total,
        ]);

        return Invoice::create([
            'invoice_number' => Invoice::nextNumber(),
            'sales_order_id' => $so->id,
            'customer_id' => $this->customer->id,
            'broker_id' => $this->broker->id,
            'invoice_date' => now()->toDateString(),
            'total' => $total,
        ]);
    }

    /**
     * Build a delivered invoice whose sales order carries real line items.
     * Each line: ['product_id' => int, 'taxable' => float, 'tax' => float].
     */
    private function makeInvoiceWithItems(array $lines, ?int $brokerId = null): Invoice
    {
        $brokerId ??= $this->broker->id;

        $so = SalesOrder::create([
            'so_number' => SalesOrder::nextNumber(),
            'customer_id' => $this->customer->id,
            'broker_id' => $brokerId,
            'warehouse_id' => $this->warehouse->id,
            'status' => 'delivered',
            'order_date' => now()->toDateString(),
            'subtotal' => 0,
            'tax_amount' => 0,
            'total' => 0,
        ]);

        $subtotal = 0.0;
        $taxTotal = 0.0;
        foreach ($lines as $l) {
            $taxable = (float) $l['taxable'];
            $tax = (float) ($l['tax'] ?? 0);
            $so->items()->create([
                'product_id' => $l['product_id'],
                'quantity' => 1,
                'unit_price' => $taxable,
                'discount_amount' => 0,
                'tax_percent' => $taxable > 0 ? round($tax / $taxable * 100, 2) : 0,
                'tax_amount' => $tax,
                'line_total' => $taxable + $tax,
            ]);
            $subtotal += $taxable;
            $taxTotal += $tax;
        }
        $so->update(['subtotal' => $subtotal, 'tax_amount' => $taxTotal, 'total' => $subtotal + $taxTotal]);

        return Invoice::create([
            'invoice_number' => Invoice::nextNumber(),
            'sales_order_id' => $so->id,
            'customer_id' => $this->customer->id,
            'broker_id' => $brokerId,
            'invoice_date' => now()->toDateString(),
            'total' => $subtotal + $taxTotal,
        ]);
    }

    private function makeProduct(string $sku): Product
    {
        return Product::forceCreate(['sku' => $sku, 'name' => $sku, 'selling_price' => 100]);
    }

    public function test_accrual_uses_sale_value_excl_tax_plus_fixed(): void
    {
        // subtotal 1000 (+ 180 tax that should be ignored for commission).
        $invoice = $this->makeInvoice(1000, 180);

        $commission = $this->service->accrueForInvoice($invoice);

        $this->assertNotNull($commission);
        // 10% of 1000 + 50 fixed = 150 (tax excluded).
        $this->assertSame(150.0, (float) $commission->commission_amount);
        $this->assertSame(1000.0, (float) $commission->base_amount);
        $this->assertSame('pending', $commission->status);
    }

    public function test_accrual_is_idempotent_and_skips_brokerless_invoices(): void
    {
        $invoice = $this->makeInvoice(1000);
        $this->service->accrueForInvoice($invoice);
        $this->service->accrueForInvoice($invoice); // second call: no-op

        $this->assertSame(1, BrokerCommission::where('invoice_id', $invoice->id)->count());

        $noBroker = $this->makeInvoice(500);
        $noBroker->update(['broker_id' => null]);
        $this->assertNull($this->service->accrueForInvoice($noBroker));
    }

    public function test_settlement_fifo_allocates_and_updates_status(): void
    {
        $invoice = $this->makeInvoice(1000); // commission 150
        $this->service->accrueForInvoice($invoice);

        // Partial settlement.
        $this->service->settle($this->broker, ['amount' => 100, 'mode' => 'cash']);
        $commission = BrokerCommission::first();
        $this->assertSame(100.0, (float) $commission->fresh()->settled_amount);
        $this->assertSame('partial', $commission->fresh()->status);
        $this->assertSame(50.0, $commission->fresh()->outstanding());

        // Settle the rest.
        $this->service->settle($this->broker, ['amount' => 50, 'mode' => 'upi']);
        $this->assertSame('paid', $commission->fresh()->status);
        $this->assertSame(0.0, (float) $this->broker->fresh()->outstandingCommission());
    }

    public function test_sales_return_reverses_commission_proportionally(): void
    {
        $invoice = $this->makeInvoice(1000); // commission 150
        $this->service->accrueForInvoice($invoice);

        $return = SalesReturn::create([
            'credit_note_number' => SalesReturn::nextNumber(),
            'invoice_id' => $invoice->id,
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'status' => 'draft',
            'return_date' => now()->toDateString(),
            'subtotal' => 200,
            'tax_amount' => 36,
            'total' => 236,
        ]);

        $reversal = $this->service->reverseForReturn($return);

        $this->assertNotNull($reversal);
        // 10% of 200 (=20) + prorated fixed 50 * 200/1000 (=10) = 30, booked negative.
        $this->assertSame(-30.0, (float) $reversal->commission_amount);
        $this->assertSame('reversal', $reversal->type);

        // Net earned = 150 - 30 = 120.
        $this->assertSame(120.0, (float) $this->broker->fresh()->outstandingCommission());
    }

    public function test_commission_by_product_rate_applies_per_line(): void
    {
        $shirt = $this->makeProduct('SHIRT');
        $pant = $this->makeProduct('PANT');

        // SHIRT gets a broker-specific 15% rate; PANT falls back to the 10% default.
        $this->broker->rates()->create(['scope' => 'product', 'product_id' => $shirt->id, 'commission_percent' => 15]);

        $invoice = $this->makeInvoiceWithItems([
            ['product_id' => $shirt->id, 'taxable' => 1000],
            ['product_id' => $pant->id, 'taxable' => 500],
        ]);

        $this->service->accrueForInvoice($invoice);
        $commission = BrokerCommission::where('invoice_id', $invoice->id)->first();

        // 15% of 1000 + 10% of 500 + 50 fixed = 150 + 50 + 50 = 250.
        $this->assertSame(250.0, (float) $commission->commission_amount);
        $this->assertSame(1500.0, (float) $commission->base_amount);
    }

    public function test_commission_by_customer_rate_overrides_default(): void
    {
        // 8% for this customer, no product rate; use the no-line-items path.
        $this->broker->rates()->create(['scope' => 'customer', 'customer_id' => $this->customer->id, 'commission_percent' => 8]);

        $invoice = $this->makeInvoice(1000);
        $this->service->accrueForInvoice($invoice);
        $commission = BrokerCommission::where('invoice_id', $invoice->id)->first();

        // 8% of 1000 + 50 fixed = 130.
        $this->assertSame(130.0, (float) $commission->commission_amount);
    }

    public function test_product_rate_beats_customer_rate(): void
    {
        $shirt = $this->makeProduct('SHIRT');
        $this->broker->rates()->create(['scope' => 'product', 'product_id' => $shirt->id, 'commission_percent' => 15]);
        $this->broker->rates()->create(['scope' => 'customer', 'customer_id' => $this->customer->id, 'commission_percent' => 8]);

        $invoice = $this->makeInvoiceWithItems([['product_id' => $shirt->id, 'taxable' => 1000]]);
        $this->service->accrueForInvoice($invoice);
        $commission = BrokerCommission::where('invoice_id', $invoice->id)->first();

        // Product rate (15%) wins: 150 + 50 fixed = 200.
        $this->assertSame(200.0, (float) $commission->commission_amount);
    }

    public function test_multi_broker_per_invoice_splits_by_share(): void
    {
        $broker2 = Broker::create([
            'code' => 'BRK2', 'name' => 'Second Dalal',
            'commission_percent' => 0, 'fixed_commission' => 0, 'is_active' => true,
        ]);

        $product = $this->makeProduct('SHIRT');
        $invoice = $this->makeInvoiceWithItems([['product_id' => $product->id, 'taxable' => 1000]]);

        // Broker 1: default 10% + 50 fixed, 60% share. Broker 2: 5% override, 40% share.
        InvoiceBroker::create(['invoice_id' => $invoice->id, 'broker_id' => $this->broker->id, 'share_percent' => 60]);
        InvoiceBroker::create(['invoice_id' => $invoice->id, 'broker_id' => $broker2->id, 'commission_percent' => 5, 'share_percent' => 40]);

        $this->service->accrueForInvoice($invoice);

        $c1 = BrokerCommission::where('invoice_id', $invoice->id)->where('broker_id', $this->broker->id)->first();
        $c2 = BrokerCommission::where('invoice_id', $invoice->id)->where('broker_id', $broker2->id)->first();

        // Broker 1: (10% of 1000 + 50 fixed) * 60% = 150 * 0.6 = 90.
        $this->assertSame(90.0, (float) $c1->commission_amount);
        // Broker 2: 5% of 1000 * 40% = 50 * 0.4 = 20.
        $this->assertSame(20.0, (float) $c2->commission_amount);
    }

    public function test_settlement_withholds_tds(): void
    {
        $this->broker->update(['tds_percent' => 5]);
        $invoice = $this->makeInvoice(1000); // commission 150
        $this->service->accrueForInvoice($invoice);

        $settlement = $this->service->settle($this->broker, ['amount' => 100, 'mode' => 'bank']);

        // Gross 100 clears commission; TDS 5% withheld; net paid 95.
        $this->assertSame(100.0, (float) $settlement->amount);
        $this->assertSame(5.0, (float) $settlement->tds_amount);
        $this->assertSame(95.0, (float) $settlement->net_paid);
        // Outstanding drops by the gross (100), not the net.
        $this->assertSame(50.0, (float) $this->broker->fresh()->outstandingCommission());
    }

    public function test_wallet_tracks_commission_and_settlement(): void
    {
        $invoice = $this->makeInvoice(1000); // commission 150
        $this->service->accrueForInvoice($invoice);

        $this->assertSame(150.0, (float) $this->broker->fresh()->wallet_balance);
        $credit = BrokerWalletTransaction::where('type', 'commission')->first();
        $this->assertSame(150.0, (float) $credit->amount);
        $this->assertSame(150.0, (float) $credit->balance_after);

        $this->service->settle($this->broker, ['amount' => 150, 'mode' => 'cash']);

        $this->assertSame(0.0, (float) $this->broker->fresh()->wallet_balance);
        $debit = BrokerWalletTransaction::where('type', 'settlement')->first();
        $this->assertSame(-150.0, (float) $debit->amount);
        $this->assertSame(0.0, (float) $debit->balance_after);
    }

    public function test_wallet_manual_topup_and_advance(): void
    {
        $this->service->recordWalletManual($this->broker, 'topup', 200, 'Advance float', null);
        $this->assertSame(200.0, (float) $this->broker->fresh()->wallet_balance);

        $this->service->recordWalletManual($this->broker, 'advance', -50, 'Cash given', null);
        $this->assertSame(150.0, (float) $this->broker->fresh()->wallet_balance);

        $this->assertSame(2, BrokerWalletTransaction::where('broker_id', $this->broker->id)->count());
    }

    public function test_reversal_debits_wallet(): void
    {
        $invoice = $this->makeInvoice(1000); // commission 150
        $this->service->accrueForInvoice($invoice);

        $return = SalesReturn::create([
            'credit_note_number' => SalesReturn::nextNumber(),
            'invoice_id' => $invoice->id,
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'status' => 'draft',
            'return_date' => now()->toDateString(),
            'subtotal' => 200,
            'tax_amount' => 36,
            'total' => 236,
        ]);

        $this->service->reverseForReturn($return);

        // 150 credit - 30 reversal = 120.
        $this->assertSame(120.0, (float) $this->broker->fresh()->wallet_balance);
    }
}
