<?php

namespace Tests\Feature;

use App\Models\PurchaseInvoice;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class SupplierPayableTest extends TestCase
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
        // purchase_invoices require a purchase_order_id FK; build a minimal PO.
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
            Permission::findOrCreate('purchase-invoices.create', 'web'),
            Permission::findOrCreate('suppliers.view', 'web'),
            Permission::findOrCreate('reports.view', 'web'),
        );
        Sanctum::actingAs($user);
    }

    public function test_partial_payment_updates_status_and_outstanding(): void
    {
        $invoice = $this->approvedInvoice(1000);
        $this->actAsPayer();

        $this->postJson("/api/purchase-invoices/{$invoice->id}/payments", [
            'amount' => 400, 'mode' => 'bank', 'payment_date' => now()->toDateString(),
        ])->assertOk()->assertJsonPath('data.payment_status', 'partial');

        $invoice->refresh();
        $this->assertSame(400.0, (float) $invoice->paid_amount);
        $this->assertSame(600.0, $invoice->balanceDue());
        $this->assertSame(600.0, $this->supplier->fresh()->outstandingBalance());
    }

    public function test_overpayment_is_rejected(): void
    {
        $invoice = $this->approvedInvoice(1000);
        $this->actAsPayer();

        $this->postJson("/api/purchase-invoices/{$invoice->id}/payments", [
            'amount' => 1200, 'mode' => 'bank', 'payment_date' => now()->toDateString(),
        ])->assertStatus(422);
    }

    public function test_full_payment_marks_paid_and_clears_outstanding(): void
    {
        $invoice = $this->approvedInvoice(1000);
        $this->actAsPayer();

        foreach ([400, 600] as $amount) {
            $this->postJson("/api/purchase-invoices/{$invoice->id}/payments", [
                'amount' => $amount, 'mode' => 'cash', 'payment_date' => now()->toDateString(),
            ])->assertOk();
        }

        $this->assertSame('paid', $invoice->fresh()->payment_status);
        $this->assertSame(0.0, $this->supplier->fresh()->outstandingBalance());
    }

    public function test_ledger_reflects_bill_and_payments(): void
    {
        $invoice = $this->approvedInvoice(1000);
        $this->actAsPayer();
        $this->postJson("/api/purchase-invoices/{$invoice->id}/payments", [
            'amount' => 400, 'mode' => 'bank', 'payment_date' => now()->toDateString(),
        ])->assertOk();

        $res = $this->getJson("/api/suppliers/{$this->supplier->id}/ledger")->assertOk();
        // opening (0) + bill (debit 1000) + payment (credit 400) => closing 600.
        $res->assertJsonPath('summary.closing_balance', 600)
            ->assertJsonPath('summary.outstanding_balance', 600);
    }

    public function test_payables_report_buckets_outstanding(): void
    {
        $this->approvedInvoice(1000);
        $this->actAsPayer();

        $res = $this->getJson('/api/reports/payables')->assertOk();
        $res->assertJsonPath('meta.total_outstanding', 1000);
        $this->assertSame(1000.0, (float) $res->json('data.0.total_outstanding'));
        $this->assertSame(1000.0, (float) $res->json('data.0.bucket_0_30'));
    }
}
