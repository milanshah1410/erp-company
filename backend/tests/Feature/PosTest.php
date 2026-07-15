<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\SalesReturn;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Services\StockService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * HTTP coverage for the Retail POS flows: barcode lookup, one-shot checkout,
 * counter return (credit note + refund) and exchange (net settlement).
 */
class PosTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Warehouse $warehouse;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['company_name' => 'Test Co', 'status' => 'active']);
        app(TenantContext::class)->set($this->tenant->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);

        $this->warehouse = Warehouse::forceCreate(['code' => 'WH1', 'name' => 'Main', 'type' => 'main']);
        $this->product = Product::forceCreate([
            'sku' => 'TSHIRT', 'barcode' => '890123456789', 'name' => 'T-Shirt',
            'selling_price' => 100, 'tax_percent' => 0, 'is_active' => true,
        ]);

        // Seed on-hand stock so a sale can post outward.
        app(StockService::class)->inward(
            warehouseId: $this->warehouse->id,
            productId: $this->product->id,
            variantId: null,
            quantity: 50,
            unitCost: 60,
            type: 'opening',
        );
    }

    private function authorize(string ...$permissions): User
    {
        $user = User::factory()->create();
        foreach ($permissions as $name) {
            $user->givePermissionTo(Permission::findOrCreate($name, 'web'));
        }
        Sanctum::actingAs($user);

        return $user;
    }

    private function onHand(): float
    {
        return (float) WarehouseStock::query()
            ->where('warehouse_id', $this->warehouse->id)
            ->where('product_id', $this->product->id)
            ->whereNull('variant_id')
            ->value('quantity');
    }

    public function test_barcode_lookup_resolves_a_product(): void
    {
        $this->authorize('sales.view');

        $this->getJson("/api/pos/lookup?code=890123456789&warehouse_id={$this->warehouse->id}")
            ->assertOk()
            ->assertJsonPath('data.product_id', $this->product->id)
            ->assertJsonPath('data.unit_price', 100)
            ->assertJsonPath('data.available_qty', 50);

        $this->getJson('/api/pos/lookup?code=NOPE')->assertNotFound();
    }

    public function test_checkout_creates_paid_invoice_and_posts_stock(): void
    {
        $this->authorize('sales.create', 'sales.view');

        $res = $this->postJson('/api/pos/checkout', [
            'warehouse_id' => $this->warehouse->id,
            'items' => [['product_id' => $this->product->id, 'quantity' => 3, 'unit_price' => 100, 'tax_percent' => 0]],
            'payments' => [['mode' => 'cash', 'amount' => 300]],
        ])->assertCreated();

        $res->assertJsonPath('invoice.status', 'paid')
            ->assertJsonPath('invoice.total', 300)
            ->assertJsonPath('invoice.balance_due', 0);

        $this->assertSame(47.0, $this->onHand(), 'sale of 3 should drop on-hand from 50 to 47');
        $this->assertDatabaseHas('sales_orders', ['channel' => 'pos', 'status' => 'delivered', 'total' => 300]);
    }

    public function test_return_credits_invoice_refunds_cash_and_restocks(): void
    {
        $this->authorize('sales.create', 'sales.view', 'sales-returns.create');

        $checkout = $this->postJson('/api/pos/checkout', [
            'warehouse_id' => $this->warehouse->id,
            'items' => [['product_id' => $this->product->id, 'quantity' => 4, 'unit_price' => 100, 'tax_percent' => 0]],
            'payments' => [['mode' => 'cash', 'amount' => 400]],
        ])->assertCreated();

        $invoiceId = $checkout->json('invoice.id');
        $this->assertSame(46.0, $this->onHand());

        // Return 1 of the 4 units for a cash refund.
        $this->postJson('/api/pos/return', [
            'invoice_id' => $invoiceId,
            'refund_mode' => 'cash',
            'items' => [['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 100, 'tax_percent' => 0]],
        ])->assertCreated()
            ->assertJsonPath('data.return_total', 100)
            ->assertJsonPath('data.refund_amount', 100)
            ->assertJsonPath('data.balance_due', 0);

        $this->assertSame(47.0, $this->onHand(), 'returned unit is back on the shelf');

        $invoice = Invoice::find($invoiceId);
        $this->assertEquals(100, $invoice->credited_amount);
        // Refund recorded as a negative cash payment.
        $this->assertTrue(Payment::where('invoice_id', $invoiceId)->where('amount', -100)->exists());
        $this->assertDatabaseHas('sales_returns', ['invoice_id' => $invoiceId, 'status' => 'approved', 'total' => 100]);
    }

    public function test_return_rejects_over_returning(): void
    {
        $this->authorize('sales.create', 'sales.view', 'sales-returns.create');

        $invoiceId = $this->postJson('/api/pos/checkout', [
            'warehouse_id' => $this->warehouse->id,
            'items' => [['product_id' => $this->product->id, 'quantity' => 2, 'unit_price' => 100, 'tax_percent' => 0]],
            'payments' => [['mode' => 'cash', 'amount' => 200]],
        ])->assertCreated()->json('invoice.id');

        $this->postJson('/api/pos/return', [
            'invoice_id' => $invoiceId,
            'items' => [['product_id' => $this->product->id, 'quantity' => 5, 'unit_price' => 100, 'tax_percent' => 0]],
        ])->assertStatus(422)->assertJsonValidationErrors('items');
    }

    public function test_exchange_settles_net_payable(): void
    {
        $this->authorize('sales.create', 'sales.view', 'sales-returns.create');

        // Original sale: 2 units @ 100 = 200, fully paid.
        $original = $this->postJson('/api/pos/checkout', [
            'warehouse_id' => $this->warehouse->id,
            'items' => [['product_id' => $this->product->id, 'quantity' => 2, 'unit_price' => 100, 'tax_percent' => 0]],
            'payments' => [['mode' => 'cash', 'amount' => 200]],
        ])->assertCreated();
        $originalInvoiceId = $original->json('invoice.id');
        $this->assertSame(48.0, $this->onHand());

        // Exchange: return 1 unit (₹100), buy 3 units (₹300). Net payable ₹200.
        $res = $this->postJson('/api/pos/exchange', [
            'warehouse_id' => $this->warehouse->id,
            'original_invoice_id' => $originalInvoiceId,
            'return_items' => [['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 100, 'tax_percent' => 0]],
            'items' => [['product_id' => $this->product->id, 'quantity' => 3, 'unit_price' => 100, 'tax_percent' => 0]],
            'payments' => [['mode' => 'cash', 'amount' => 200]],
        ])->assertCreated();

        $res->assertJsonPath('exchange.return_total', 100)
            ->assertJsonPath('exchange.new_total', 300)
            ->assertJsonPath('exchange.net_payable', 200)
            ->assertJsonPath('invoice.status', 'paid')
            ->assertJsonPath('invoice.balance_due', 0);

        // Stock: 48 + 1 returned - 3 sold = 46.
        $this->assertSame(46.0, $this->onHand());

        // Original invoice is settled back to zero after the exchange consumes its credit.
        $this->assertEquals(0, Invoice::find($originalInvoiceId)->balanceDue());
        // New invoice paid by ₹200 cash + ₹100 exchange credit.
        $newInvoiceId = $res->json('invoice.id');
        $this->assertTrue(Payment::where('invoice_id', $newInvoiceId)->where('mode', 'exchange')->where('amount', 100)->exists());
    }

    public function test_exchange_refunds_net_difference(): void
    {
        $this->authorize('sales.create', 'sales.view', 'sales-returns.create');

        // Original: 3 units @ 100 = 300.
        $originalInvoiceId = $this->postJson('/api/pos/checkout', [
            'warehouse_id' => $this->warehouse->id,
            'items' => [['product_id' => $this->product->id, 'quantity' => 3, 'unit_price' => 100, 'tax_percent' => 0]],
            'payments' => [['mode' => 'cash', 'amount' => 300]],
        ])->assertCreated()->json('invoice.id');

        // Return 3 (₹300), buy 1 (₹100) → net refund ₹200, no tender.
        $res = $this->postJson('/api/pos/exchange', [
            'warehouse_id' => $this->warehouse->id,
            'original_invoice_id' => $originalInvoiceId,
            'refund_mode' => 'cash',
            'return_items' => [['product_id' => $this->product->id, 'quantity' => 3, 'unit_price' => 100, 'tax_percent' => 0]],
            'items' => [['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 100, 'tax_percent' => 0]],
            'payments' => [],
        ])->assertCreated();

        $res->assertJsonPath('exchange.net_refund', 200)
            ->assertJsonPath('exchange.net_payable', 0)
            ->assertJsonPath('invoice.status', 'paid');

        $this->assertEquals(0, Invoice::find($originalInvoiceId)->balanceDue());
    }
}
