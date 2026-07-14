<?php

namespace Tests\Feature;

use App\Models\PurchaseInvoice;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use App\Services\PaymentService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PaymentVoucherTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['company_name' => 'Test Co', 'status' => 'active']);
        app(TenantContext::class)->set($this->tenant->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);

        $this->supplier = Supplier::forceCreate(['code' => 'S1', 'name' => 'Acme Traders', 'opening_balance' => 0]);
    }

    private function approvedInvoice(float $total): PurchaseInvoice
    {
        $warehouse = \App\Models\Warehouse::forceCreate(['code' => 'WH1', 'name' => 'Main', 'type' => 'main']);
        $po = \App\Models\PurchaseOrder::forceCreate([
            'po_number' => 'PO-'.uniqid(),
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $warehouse->id,
            'status' => 'approved',
            'order_date' => now()->toDateString(),
            'total' => $total,
        ]);

        return PurchaseInvoice::create([
            'invoice_number' => 'INV-'.uniqid(),
            'purchase_order_id' => $po->id,
            'supplier_id' => $this->supplier->id,
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->toDateString(),
            'subtotal' => $total,
            'total' => $total,
            'status' => 'approved',
        ]);
    }

    private function actAsPayer(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(
            Permission::findOrCreate('payments.view', 'web'),
            Permission::findOrCreate('payments.create', 'web'),
            Permission::findOrCreate('payments.edit', 'web'),
            Permission::findOrCreate('payments.delete', 'web'),
        );
        Sanctum::actingAs($user);
    }

    public function test_outbound_payment_allocated_to_invoice_marks_it_partial(): void
    {
        $invoice = $this->approvedInvoice(1000);
        $this->actAsPayer();

        $this->postJson('/api/payment-vouchers', [
            'direction' => 'outbound',
            'party_type' => 'supplier',
            'party_id' => $this->supplier->id,
            'amount' => 400,
            'mode' => 'bank',
            'reference_no' => 'NEFT123',
            'payment_date' => now()->toDateString(),
            'allocations' => [
                ['allocatable_type' => 'purchase_invoice', 'allocatable_id' => $invoice->id, 'amount' => 400],
            ],
        ])->assertCreated()
            ->assertJsonPath('data.allocated_amount', '400.00')
            ->assertJsonPath('data.unallocated_amount', '0.00')
            ->assertJsonPath('data.is_advance', false);

        $invoice->refresh();
        $this->assertSame(400.0, (float) $invoice->paid_amount);
        $this->assertSame('partial', $invoice->payment_status);
    }

    public function test_advance_stays_unallocated_then_can_be_applied(): void
    {
        $this->actAsPayer();

        // Book a pure advance — no allocations.
        $res = $this->postJson('/api/payment-vouchers', [
            'direction' => 'outbound',
            'party_type' => 'supplier',
            'party_id' => $this->supplier->id,
            'amount' => 500,
            'mode' => 'cash',
            'payment_date' => now()->toDateString(),
        ])->assertCreated()
            ->assertJsonPath('data.is_advance', true)
            ->assertJsonPath('data.unallocated_amount', '500.00');

        $voucherId = $res->json('data.id');

        // The advance is the supplier's ledger credit.
        $this->assertSame(500.0, app(PaymentService::class)
            ->partyAdvanceBalance('supplier', $this->supplier->id, 'outbound'));

        // Apply part of it to an invoice later.
        $invoice = $this->approvedInvoice(1000);
        $this->postJson("/api/payment-vouchers/{$voucherId}/allocations", [
            'allocations' => [
                ['allocatable_type' => 'purchase_invoice', 'allocatable_id' => $invoice->id, 'amount' => 300],
            ],
        ])->assertOk()
            ->assertJsonPath('data.unallocated_amount', '200.00');

        $this->assertSame(300.0, (float) $invoice->fresh()->paid_amount);
        $this->assertSame(200.0, app(PaymentService::class)
            ->partyAdvanceBalance('supplier', $this->supplier->id, 'outbound'));
    }

    public function test_allocation_exceeding_balance_is_rejected(): void
    {
        $invoice = $this->approvedInvoice(1000);
        $this->actAsPayer();

        $this->postJson('/api/payment-vouchers', [
            'direction' => 'outbound',
            'party_type' => 'supplier',
            'party_id' => $this->supplier->id,
            'amount' => 2000,
            'mode' => 'bank',
            'payment_date' => now()->toDateString(),
            'allocations' => [
                ['allocatable_type' => 'purchase_invoice', 'allocatable_id' => $invoice->id, 'amount' => 1500],
            ],
        ])->assertStatus(422);

        // Nothing should have been persisted (transaction rolled back).
        $this->assertSame(0.0, (float) $invoice->fresh()->paid_amount);
        $this->assertDatabaseCount('payment_vouchers', 0);
    }

    public function test_outbound_payment_cannot_settle_a_sales_invoice(): void
    {
        $this->actAsPayer();

        $this->postJson('/api/payment-vouchers', [
            'direction' => 'outbound',
            'party_type' => 'supplier',
            'party_id' => $this->supplier->id,
            'amount' => 100,
            'mode' => 'cash',
            'payment_date' => now()->toDateString(),
            'allocations' => [
                ['allocatable_type' => 'invoice', 'allocatable_id' => 1, 'amount' => 100],
            ],
        ])->assertStatus(422);
    }

    public function test_deleting_a_voucher_reverses_its_allocations(): void
    {
        $invoice = $this->approvedInvoice(1000);
        $this->actAsPayer();

        $res = $this->postJson('/api/payment-vouchers', [
            'direction' => 'outbound',
            'party_type' => 'supplier',
            'party_id' => $this->supplier->id,
            'amount' => 400,
            'mode' => 'bank',
            'payment_date' => now()->toDateString(),
            'allocations' => [
                ['allocatable_type' => 'purchase_invoice', 'allocatable_id' => $invoice->id, 'amount' => 400],
            ],
        ])->assertCreated();

        $this->assertSame(400.0, (float) $invoice->fresh()->paid_amount);

        $this->deleteJson("/api/payment-vouchers/{$res->json('data.id')}")->assertOk();

        $invoice->refresh();
        $this->assertSame(0.0, (float) $invoice->paid_amount);
        $this->assertSame('unpaid', $invoice->payment_status);
        $this->assertDatabaseCount('payment_allocations', 0);
    }

    public function test_cheque_mode_requires_cheque_details(): void
    {
        $this->actAsPayer();

        $this->postJson('/api/payment-vouchers', [
            'direction' => 'outbound',
            'party_type' => 'supplier',
            'party_id' => $this->supplier->id,
            'amount' => 100,
            'mode' => 'cheque',
            'payment_date' => now()->toDateString(),
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['cheque_no', 'cheque_date']);
    }
}
