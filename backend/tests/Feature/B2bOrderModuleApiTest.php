<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Notifications\B2bOrderPlacedNotification;
use App\Notifications\B2bOrderStatusNotification;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * HTTP-level coverage for the B2B Order System (SRS §14): portal login,
 * customer-priced ordering, and the staff approval workflow.
 */
class B2bOrderModuleApiTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Customer $customer;

    private Product $product;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['company_name' => 'Test Co', 'status' => 'active']);
        app(TenantContext::class)->set($this->tenant->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);

        $this->customer = Customer::forceCreate([
            'code' => 'CUST1', 'name' => 'Dealer One', 'email' => 'dealer@example.com',
            'type' => 'dealer', 'credit_limit' => 0, 'opening_balance' => 0, 'is_active' => true,
            'portal_enabled' => true, 'portal_password' => Hash::make('secret123'),
        ]);

        $this->warehouse = Warehouse::forceCreate(['code' => 'WH1', 'name' => 'Main', 'is_active' => true]);
        $this->product = Product::forceCreate([
            'sku' => 'P1', 'name' => 'Cotton Shirt', 'selling_price' => 100, 'tax_percent' => 0,
            'reorder_level' => 2, 'is_active' => true,
        ]);
        WarehouseStock::forceCreate([
            'warehouse_id' => $this->warehouse->id, 'product_id' => $this->product->id,
            'variant_id' => null, 'quantity' => 10, 'reserved_quantity' => 0, 'average_cost' => 50,
        ]);
    }

    /** Log in through the portal endpoint and return the bearer token. */
    private function portalToken(): string
    {
        return $this->postJson('/api/portal/login', [
            'email' => 'dealer@example.com', 'password' => 'secret123',
        ])->assertOk()->json('token');
    }

    private function staff(string ...$permissions): User
    {
        $user = User::factory()->create();
        foreach ($permissions as $name) {
            $user->givePermissionTo(Permission::findOrCreate($name, 'web'));
        }
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_portal_login_rejects_bad_credentials(): void
    {
        $this->postJson('/api/portal/login', [
            'email' => 'dealer@example.com', 'password' => 'wrong',
        ])->assertStatus(422);
    }

    public function test_disabled_portal_customer_cannot_use_portal(): void
    {
        $this->customer->forceFill(['portal_enabled' => false])->save();

        $this->postJson('/api/portal/login', [
            'email' => 'dealer@example.com', 'password' => 'secret123',
        ])->assertStatus(422);
    }

    public function test_catalogue_shows_customer_price_and_live_stock(): void
    {
        $token = $this->portalToken();

        $row = $this->withToken($token)->getJson('/api/portal/catalogue')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Cotton Shirt')
            ->json('data.0');

        $this->assertEquals(100, $row['price']);
        $this->assertEquals(10, $row['available_quantity']);
        $this->assertEquals('in', $row['stock_status']);
    }

    public function test_portal_order_is_created_pending_approval_and_alerts_staff(): void
    {
        Notification::fake();
        // A staff recipient with an approver role must exist for the alert.
        $manager = User::factory()->create();
        $manager->assignRole(Role::findOrCreate('super-admin', 'web'));

        $token = $this->portalToken();
        $order = $this->withToken($token)->postJson('/api/portal/orders', [
            'items' => [['product_id' => $this->product->id, 'quantity' => 5]],
            'notes' => 'Please ship fast',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'pending_approval')
            ->assertJsonPath('data.channel', 'b2b')
            ->json('data');

        $this->assertEquals(500, $order['total']);
        Notification::assertSentTo($manager, B2bOrderPlacedNotification::class);
    }

    public function test_staff_approve_reserves_stock_and_confirms(): void
    {
        Notification::fake();
        $token = $this->portalToken();
        $order = $this->withToken($token)->postJson('/api/portal/orders', [
            'items' => [['product_id' => $this->product->id, 'quantity' => 5]],
        ])->assertCreated()->json('data');

        $this->staff('b2b-orders.view', 'b2b-orders.approve');

        $this->postJson("/api/b2b-orders/{$order['id']}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'confirmed');

        $this->assertEquals(5, (float) WarehouseStock::query()
            ->where('warehouse_id', $this->warehouse->id)
            ->where('product_id', $this->product->id)
            ->value('reserved_quantity'));

        Notification::assertSentTo($this->customer, B2bOrderStatusNotification::class);
    }

    public function test_staff_reject_cancels_the_order(): void
    {
        Notification::fake();
        $token = $this->portalToken();
        $order = $this->withToken($token)->postJson('/api/portal/orders', [
            'items' => [['product_id' => $this->product->id, 'quantity' => 5]],
        ])->assertCreated()->json('data');

        $this->staff('b2b-orders.view', 'b2b-orders.approve');

        $this->postJson("/api/b2b-orders/{$order['id']}/reject", ['reason' => 'Out of policy'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $this->assertDatabaseHas('sales_orders', [
            'id' => $order['id'], 'status' => 'cancelled', 'rejected_reason' => 'Out of policy',
        ]);
    }

    public function test_customer_can_cancel_own_pending_order(): void
    {
        $token = $this->portalToken();
        $order = $this->withToken($token)->postJson('/api/portal/orders', [
            'items' => [['product_id' => $this->product->id, 'quantity' => 5]],
        ])->assertCreated()->json('data');

        $this->withToken($token)->postJson("/api/portal/orders/{$order['id']}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');
    }

    public function test_staff_token_cannot_access_portal(): void
    {
        $this->staff('sales.view');

        $this->getJson('/api/portal/catalogue')->assertStatus(403);
    }
}
