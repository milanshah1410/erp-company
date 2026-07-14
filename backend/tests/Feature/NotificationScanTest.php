<?php

namespace Tests\Feature;

use App\Models\Broker;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\ManufacturingOrder;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use App\Notifications\BrokerCommissionDueNotification;
use App\Notifications\ExpenseApprovalPendingNotification;
use App\Notifications\ManufacturingOrderDueNotification;
use App\Notifications\OutstandingBalanceNotification;
use App\Support\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class NotificationScanTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // Global role/permission set (team_id null), exactly as tenant provisioning seeds it.
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);
        (new RolePermissionSeeder)->run();

        $this->tenant = Tenant::create(['company_name' => 'Test Co', 'status' => 'active']);
        $this->useTenant($this->tenant);

        $this->admin = $this->makeAdmin($this->tenant);
    }

    /** Activate a tenant's context + permission team (as the scheduler helper does). */
    private function useTenant(Tenant $tenant): void
    {
        app(TenantContext::class)->set($tenant->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
    }

    /** Simulate the scheduler: no tenant/team context at all. */
    private function clearContext(): void
    {
        app(TenantContext::class)->forget();
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    }

    private function makeAdmin(Tenant $tenant): User
    {
        $this->useTenant($tenant);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'is_active' => true]);
        $user->assignRole('admin'); // global role, assigned under this tenant's team

        return $user;
    }

    public function test_pending_manufacturing_notifies_open_overdue_orders_once(): void
    {
        $product = Product::forceCreate(['sku' => 'FG1', 'name' => 'Kids T-shirt', 'cost_price' => 0]);
        $warehouse = Warehouse::forceCreate(['code' => 'WH1', 'name' => 'Main', 'type' => 'main']);
        ManufacturingOrder::forceCreate([
            'mo_number' => 'MO-000001',
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity_to_produce' => 10,
            'status' => 'in_progress',
            'order_date' => now()->subDays(10)->toDateString(),
            'expected_completion_date' => now()->subDay()->toDateString(),
        ]);
        // A completed order must never be flagged.
        ManufacturingOrder::forceCreate([
            'mo_number' => 'MO-000002',
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity_to_produce' => 5,
            'status' => 'completed',
            'order_date' => now()->subDays(10)->toDateString(),
            'expected_completion_date' => now()->subDay()->toDateString(),
        ]);

        $this->clearContext();
        Artisan::call('manufacturing:notify-pending');

        $notes = $this->admin->notifications()->where('type', ManufacturingOrderDueNotification::class)->get();
        $this->assertCount(1, $notes);
        $this->assertSame('critical', $notes->first()->data['severity']); // overdue

        // Second run is idempotent.
        Artisan::call('manufacturing:notify-pending');
        $this->assertSame(1, $this->admin->notifications()->where('type', ManufacturingOrderDueNotification::class)->count());
    }

    public function test_broker_commission_due_notifies_and_respects_cooldown(): void
    {
        // outstandingCommission() = opening_balance + earned - settled; opening alone suffices.
        Broker::create([
            'code' => 'BRK1', 'name' => 'Dalal Bhai',
            'commission_percent' => 10, 'fixed_commission' => 0,
            'opening_balance' => 500, 'is_active' => true,
        ]);
        // A broker with nothing due is skipped.
        Broker::create(['code' => 'BRK2', 'name' => 'Nil Broker', 'opening_balance' => 0, 'is_active' => true]);

        $this->clearContext();
        Artisan::call('brokers:notify-commission-due');

        $notes = $this->admin->notifications()->where('type', BrokerCommissionDueNotification::class)->get();
        $this->assertCount(1, $notes);
        $this->assertSame(500.0, (float) $notes->first()->data['outstanding']);

        // Within cooldown → no duplicate.
        Artisan::call('brokers:notify-commission-due');
        $this->assertSame(1, $this->admin->notifications()->where('type', BrokerCommissionDueNotification::class)->count());
    }

    public function test_customer_and_supplier_over_credit_limit_notify(): void
    {
        Customer::forceCreate([
            'code' => 'C1', 'name' => 'Buyer', 'type' => 'retail',
            'credit_limit' => 1000, 'opening_balance' => 2500, 'is_active' => true,
        ]);
        // Within limit → no alert.
        Customer::forceCreate([
            'code' => 'C2', 'name' => 'Good Buyer', 'type' => 'retail',
            'credit_limit' => 5000, 'opening_balance' => 100, 'is_active' => true,
        ]);
        Supplier::forceCreate([
            'code' => 'S1', 'name' => 'Acme Traders',
            'credit_limit' => 1000, 'opening_balance' => 3000, 'is_active' => true,
        ]);

        $this->clearContext();
        Artisan::call('notifications:outstanding-balances');

        $notes = $this->admin->notifications()->where('type', OutstandingBalanceNotification::class)->get();
        $this->assertCount(2, $notes);
        $kinds = $notes->pluck('data.party_kind')->sort()->values()->all();
        $this->assertSame(['customer', 'supplier'], $kinds);
    }

    public function test_pending_expense_notifies_approvers_once(): void
    {
        $category = ExpenseCategory::forceCreate(['name' => 'Travel', 'is_active' => true]);
        Expense::forceCreate([
            'expense_number' => 'EXP-000001',
            'expense_category_id' => $category->id,
            'expense_date' => now()->toDateString(),
            'amount' => 1500,
            'status' => 'pending',
        ]);
        // Approved expense is ignored.
        Expense::forceCreate([
            'expense_number' => 'EXP-000002',
            'expense_category_id' => $category->id,
            'expense_date' => now()->toDateString(),
            'amount' => 200,
            'status' => 'approved',
        ]);

        $this->clearContext();
        Artisan::call('expenses:notify-pending-approval');

        $this->assertSame(1, $this->admin->notifications()->where('type', ExpenseApprovalPendingNotification::class)->count());

        Artisan::call('expenses:notify-pending-approval');
        $this->assertSame(1, $this->admin->notifications()->where('type', ExpenseApprovalPendingNotification::class)->count());
    }

    public function test_notifications_do_not_leak_across_tenants(): void
    {
        // Tenant A (this->tenant) has a pending expense.
        $catA = ExpenseCategory::forceCreate(['name' => 'Office', 'is_active' => true]);
        Expense::forceCreate([
            'expense_number' => 'EXP-A1', 'expense_category_id' => $catA->id,
            'expense_date' => now()->toDateString(), 'amount' => 100, 'status' => 'pending',
        ]);

        // Tenant B with its own admin + pending expense.
        $tenantB = Tenant::create(['company_name' => 'Other Co', 'status' => 'active']);
        $adminB = $this->makeAdmin($tenantB);
        $this->useTenant($tenantB);
        $catB = ExpenseCategory::forceCreate(['name' => 'Office', 'is_active' => true]);
        Expense::forceCreate([
            'expense_number' => 'EXP-B1', 'expense_category_id' => $catB->id,
            'expense_date' => now()->toDateString(), 'amount' => 999, 'status' => 'pending',
        ]);

        $this->clearContext();
        Artisan::call('expenses:notify-pending-approval');

        // Each admin sees exactly their own tenant's one expense.
        $this->assertSame(1, $this->admin->notifications()->count());
        $this->assertSame(1, $adminB->notifications()->count());
        $this->assertStringContainsString('EXP-A1', $this->admin->notifications()->first()->data['message']);
        $this->assertStringContainsString('EXP-B1', $adminB->notifications()->first()->data['message']);
    }
}
