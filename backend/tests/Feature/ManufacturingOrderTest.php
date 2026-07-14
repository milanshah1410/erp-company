<?php

namespace Tests\Feature;

use App\Models\ManufacturingOrder;
use App\Models\Product;
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

class ManufacturingOrderTest extends TestCase
{
    use RefreshDatabase;

    private StockService $stock;

    private Tenant $tenant;

    private Warehouse $warehouse;

    private Product $finished;

    private Product $fabric;

    private Product $thread;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['company_name' => 'Test Co', 'status' => 'active']);
        app(TenantContext::class)->set($this->tenant->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);

        $this->stock = app(StockService::class);
        $this->warehouse = Warehouse::forceCreate(['code' => 'WH1', 'name' => 'Main', 'type' => 'main']);
        $this->finished = Product::forceCreate(['sku' => 'FG1', 'name' => 'Kids T-shirt', 'cost_price' => 0]);
        $this->fabric = Product::forceCreate(['sku' => 'RM-FAB', 'name' => 'Fabric', 'cost_price' => 10]);
        $this->thread = Product::forceCreate(['sku' => 'RM-THR', 'name' => 'Thread', 'cost_price' => 5]);

        // Seed raw-material stock.
        $this->stock->inward($this->warehouse->id, $this->fabric->id, null, 100, 10.0, 'purchase');
        $this->stock->inward($this->warehouse->id, $this->thread->id, null, 100, 5.0, 'purchase');
    }

    private function authorize(): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo(
            Permission::findOrCreate('manufacturing-orders.view', 'web'),
            Permission::findOrCreate('manufacturing-orders.create', 'web'),
            Permission::findOrCreate('manufacturing-orders.edit', 'web'),
            Permission::findOrCreate('manufacturing-orders.delete', 'web'),
        );
        Sanctum::actingAs($user);

        return $user;
    }

    private function createOrder(array $overrides = []): array
    {
        $payload = array_merge([
            'product_id' => $this->finished->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity_to_produce' => 10,
            'order_date' => now()->toDateString(),
            'materials' => [
                ['product_id' => $this->fabric->id, 'quantity' => 20], // unit cost defaults to 10
                ['product_id' => $this->thread->id, 'quantity' => 10, 'unit_cost' => 5],
            ],
            'stages' => ['order_received', 'cutting', 'ready_stock'],
        ], $overrides);

        return $this->postJson('/api/manufacturing-orders', $payload)
            ->assertCreated()
            ->json('data');
    }

    private function stockQty(Product $product): float
    {
        return (float) (WarehouseStock::query()
            ->where('warehouse_id', $this->warehouse->id)
            ->where('product_id', $product->id)
            ->value('quantity') ?? 0);
    }

    public function test_create_seeds_selected_stages_and_material_cost(): void
    {
        $this->authorize();
        $order = $this->createOrder();

        $this->assertSame('draft', $order['status']);
        $this->assertSame('MO-000001', $order['mo_number']);
        // 20 * 10 + 10 * 5 = 250
        $this->assertSame(250.0, (float) $order['material_cost']);
        $this->assertCount(3, $order['stages']);
        // Stages come back in pipeline order regardless of request order.
        $this->assertSame(['order_received', 'cutting', 'ready_stock'],
            array_column($order['stages'], 'stage_key'));
    }

    public function test_start_consumes_raw_materials_from_stock(): void
    {
        $this->authorize();
        $order = $this->createOrder();

        $this->postJson("/api/manufacturing-orders/{$order['id']}/start")
            ->assertOk()
            ->assertJsonPath('data.status', 'in_progress')
            ->assertJsonPath('data.materials_consumed', true);

        $this->assertSame(80.0, $this->stockQty($this->fabric)); // 100 - 20
        $this->assertSame(90.0, $this->stockQty($this->thread)); // 100 - 10
    }

    public function test_complete_produces_finished_goods_at_computed_unit_cost(): void
    {
        $this->authorize();
        // A labour bucket of 50 on top of 250 raw material -> total cost 300.
        $order = $this->createOrder(['tailor_cost' => 50]);
        $this->assertSame('300.00', $order['total_cost']);
        $this->postJson("/api/manufacturing-orders/{$order['id']}/start")->assertOk();

        $this->postJson("/api/manufacturing-orders/{$order['id']}/complete")
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.finished_received', true);

        // 10 finished units received at 300 / 10 = 30 each.
        $this->assertSame(10.0, $this->stockQty($this->finished));
        $this->assertSame(30.0, (float) WarehouseStock::query()
            ->where('warehouse_id', $this->warehouse->id)
            ->where('product_id', $this->finished->id)
            ->value('average_cost'));
    }

    public function test_start_refuses_when_stock_is_insufficient(): void
    {
        $this->authorize();
        // Ask for more fabric than the 100 on hand.
        $order = $this->createOrder([
            'materials' => [['product_id' => $this->fabric->id, 'quantity' => 500]],
            'stages' => ['cutting'],
        ]);

        $this->postJson("/api/manufacturing-orders/{$order['id']}/start")
            ->assertStatus(422)
            ->assertJsonValidationErrors('materials');

        // Nothing moved; order still draft.
        $this->assertSame(100.0, $this->stockQty($this->fabric));
        $this->assertSame('draft', ManufacturingOrder::find($order['id'])->status);
    }

    public function test_cancel_in_progress_returns_consumed_materials(): void
    {
        $this->authorize();
        $order = $this->createOrder();
        $this->postJson("/api/manufacturing-orders/{$order['id']}/start")->assertOk();
        $this->assertSame(80.0, $this->stockQty($this->fabric));

        $this->postJson("/api/manufacturing-orders/{$order['id']}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        // Consumed fabric returned to stock.
        $this->assertSame(100.0, $this->stockQty($this->fabric));
        $this->assertSame(100.0, $this->stockQty($this->thread));
    }

    public function test_completed_order_cannot_be_edited_or_deleted(): void
    {
        $this->authorize();
        $order = $this->createOrder();
        $this->postJson("/api/manufacturing-orders/{$order['id']}/start")->assertOk();
        $this->postJson("/api/manufacturing-orders/{$order['id']}/complete")->assertOk();

        $this->deleteJson("/api/manufacturing-orders/{$order['id']}")->assertStatus(422);
    }
}
