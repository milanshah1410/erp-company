<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Setting;
use App\Models\StockCostLayer;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class CostingMethodTest extends TestCase
{
    use RefreshDatabase;

    private StockService $stock;

    private Warehouse $warehouse;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stock = app(StockService::class);
        $this->warehouse = Warehouse::forceCreate(['code' => 'WH1', 'name' => 'Main', 'type' => 'main']);
        $this->product = Product::forceCreate(['sku' => 'SKU1', 'name' => 'Widget']);
    }

    /** Receive 10 @ 10 then 10 @ 20 for the test product. */
    private function receiveTwoLots(): void
    {
        $this->stock->inward($this->warehouse->id, $this->product->id, null, 10, 10.0, 'purchase');
        $this->stock->inward($this->warehouse->id, $this->product->id, null, 10, 20.0, 'purchase');
    }

    private function currentStock(): WarehouseStock
    {
        return WarehouseStock::query()
            ->where('warehouse_id', $this->warehouse->id)
            ->where('product_id', $this->product->id)
            ->firstOrFail();
    }

    public function test_default_costing_method_is_weighted_average(): void
    {
        $this->assertSame(Setting::COSTING_WEIGHTED_AVERAGE, Setting::costingMethod());
    }

    public function test_inward_movements_create_cost_layers_and_update_average(): void
    {
        $this->receiveTwoLots();

        $layers = StockCostLayer::query()->orderBy('id')->get();
        $this->assertCount(2, $layers);
        $this->assertEquals([10.0, 20.0], $layers->map(fn ($l) => (float) $l->unit_cost)->all());
        $this->assertEquals([10.0, 10.0], $layers->map(fn ($l) => (float) $l->remaining_quantity)->all());

        $this->assertSame(15.0, (float) $this->currentStock()->average_cost);
    }

    public function test_fifo_issue_consumes_oldest_layers_first(): void
    {
        Setting::set('costing_method', Setting::COSTING_FIFO);
        $this->receiveTwoLots();

        $ledger = $this->stock->outward($this->warehouse->id, $this->product->id, null, 15, 'sale');

        // 10 @ 10 + 5 @ 20 = 200 for 15 units.
        $this->assertSame(13.3333, (float) $ledger->unit_cost);

        $layers = StockCostLayer::query()->orderBy('id')->get();
        $this->assertSame(0.0, (float) $layers[0]->remaining_quantity);
        $this->assertSame(5.0, (float) $layers[1]->remaining_quantity);
        $this->assertSame(5.0, (float) $this->currentStock()->quantity);
    }

    public function test_lifo_issue_consumes_newest_layers_first(): void
    {
        Setting::set('costing_method', Setting::COSTING_LIFO);
        $this->receiveTwoLots();

        $ledger = $this->stock->outward($this->warehouse->id, $this->product->id, null, 15, 'sale');

        // 10 @ 20 + 5 @ 10 = 250 for 15 units.
        $this->assertSame(16.6667, (float) $ledger->unit_cost);

        $layers = StockCostLayer::query()->orderBy('id')->get();
        $this->assertSame(5.0, (float) $layers[0]->remaining_quantity);
        $this->assertSame(0.0, (float) $layers[1]->remaining_quantity);
    }

    public function test_weighted_average_issue_costs_at_running_average(): void
    {
        Setting::set('costing_method', Setting::COSTING_WEIGHTED_AVERAGE);
        $this->receiveTwoLots();

        $ledger = $this->stock->outward($this->warehouse->id, $this->product->id, null, 15, 'sale');

        $this->assertSame(15.0, (float) $ledger->unit_cost);

        // Layers still drain (oldest first) so a later method switch is clean.
        $layers = StockCostLayer::query()->orderBy('id')->get();
        $this->assertSame(0.0, (float) $layers[0]->remaining_quantity);
        $this->assertSame(5.0, (float) $layers[1]->remaining_quantity);
    }

    public function test_shortfall_beyond_layers_is_costed_at_average(): void
    {
        Setting::set('costing_method', Setting::COSTING_FIFO);
        $this->stock->inward($this->warehouse->id, $this->product->id, null, 10, 10.0, 'purchase');

        $ledger = $this->stock->outward(
            $this->warehouse->id, $this->product->id, null, 15, 'sale',
            allowNegative: true,
        );

        // 10 @ 10 from the layer + 5 @ 10 (average) for the shortfall.
        $this->assertSame(10.0, (float) $ledger->unit_cost);
        $this->assertSame(-5.0, (float) $this->currentStock()->quantity);
    }

    public function test_switching_method_mid_stream_affects_subsequent_issues_only(): void
    {
        Setting::set('costing_method', Setting::COSTING_FIFO);
        $this->receiveTwoLots();

        $fifoIssue = $this->stock->outward($this->warehouse->id, $this->product->id, null, 10, 'sale');
        $this->assertSame(10.0, (float) $fifoIssue->unit_cost);

        Setting::set('costing_method', Setting::COSTING_LIFO);
        $lifoIssue = $this->stock->outward($this->warehouse->id, $this->product->id, null, 5, 'sale');

        // Only the 10 @ 20 lot remains; LIFO takes from it.
        $this->assertSame(20.0, (float) $lifoIssue->unit_cost);
        $this->assertSame(10.0, (float) $fifoIssue->fresh()->unit_cost, 'past entries are never restated');
    }

    public function test_settings_api_reads_and_updates_costing_method(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(
            Permission::findOrCreate('settings.view', 'web'),
            Permission::findOrCreate('settings.edit', 'web'),
        );
        Sanctum::actingAs($user);

        $this->getJson('/api/settings')
            ->assertOk()
            ->assertJsonPath('data.costing_method', 'weighted_average');

        $this->putJson('/api/settings', ['costing_method' => 'fifo'])
            ->assertOk()
            ->assertJsonPath('data.costing_method', 'fifo');

        $this->assertSame('fifo', Setting::costingMethod());

        $this->putJson('/api/settings', ['costing_method' => 'bogus'])
            ->assertStatus(422);
    }

    public function test_settings_update_requires_permission(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->putJson('/api/settings', ['costing_method' => 'fifo'])->assertForbidden();
    }
}
