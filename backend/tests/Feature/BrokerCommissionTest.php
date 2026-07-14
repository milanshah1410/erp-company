<?php

namespace Tests\Feature;

use App\Models\Broker;
use App\Models\BrokerCommission;
use App\Models\Customer;
use App\Models\Invoice;
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
}
