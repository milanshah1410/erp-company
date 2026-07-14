<?php

namespace Tests\Feature;

use App\Models\Bom;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class BomTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Product $finished;

    private Product $fabric;

    private Product $thread;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['company_name' => 'Test Co', 'status' => 'active']);
        app(TenantContext::class)->set($this->tenant->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);

        $this->finished = Product::forceCreate(['sku' => 'FG1', 'name' => 'Kids T-shirt', 'cost_price' => 0]);
        $this->fabric = Product::forceCreate(['sku' => 'RM-FAB', 'name' => 'Fabric', 'cost_price' => 10]);
        $this->thread = Product::forceCreate(['sku' => 'RM-THR', 'name' => 'Thread', 'cost_price' => 5]);
    }

    private function authorize(): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo(
            Permission::findOrCreate('boms.view', 'web'),
            Permission::findOrCreate('boms.create', 'web'),
            Permission::findOrCreate('boms.edit', 'web'),
            Permission::findOrCreate('boms.delete', 'web'),
        );
        Sanctum::actingAs($user);

        return $user;
    }

    private function createBom(array $overrides = []): array
    {
        $payload = array_merge([
            'name' => 'T-shirt recipe',
            'product_id' => $this->finished->id,
            'output_quantity' => 10,
            'items' => [
                // unit cost defaults to the product's cost_price (10)
                ['product_id' => $this->fabric->id, 'quantity' => 20],
                ['product_id' => $this->thread->id, 'quantity' => 10, 'unit_cost' => 5],
            ],
        ], $overrides);

        return $this->postJson('/api/boms', $payload)->assertCreated()->json('data');
    }

    public function test_create_seeds_items_and_rolls_up_material_cost(): void
    {
        $this->authorize();
        $bom = $this->createBom();

        $this->assertSame('BOM-000001', $bom['bom_number']);
        // 20 * 10 + 10 * 5 = 250
        $this->assertSame(250.0, (float) $bom['material_cost']);
        $this->assertCount(2, $bom['items']);
    }

    public function test_explode_scales_components_to_target_quantity_with_wastage(): void
    {
        $this->authorize();
        // Recipe yields 10 units; fabric 20 per 10 units, +10% wastage.
        $bom = $this->createBom([
            'output_quantity' => 10,
            'items' => [
                ['product_id' => $this->fabric->id, 'quantity' => 20, 'wastage_percent' => 10],
            ],
        ]);

        $data = $this->getJson("/api/boms/{$bom['id']}/explode?quantity=25")
            ->assertOk()->json('data');

        // 20 / 10 * 25 = 50, +10% = 55
        $this->assertSame(55.0, (float) $data['materials'][0]['quantity']);
        $this->assertSame($this->fabric->id, $data['materials'][0]['product_id']);
    }

    public function test_marking_default_clears_it_on_sibling_boms_for_the_same_product(): void
    {
        $this->authorize();
        $first = $this->createBom(['is_default' => true]);
        $second = $this->createBom(['name' => 'Alt recipe', 'is_default' => true]);

        $this->assertTrue((bool) Bom::find($second['id'])->is_default);
        // The first BOM's default flag was cleared when the second claimed it.
        $this->assertFalse((bool) Bom::find($first['id'])->is_default);
    }

    public function test_update_replaces_component_lines(): void
    {
        $this->authorize();
        $bom = $this->createBom();

        $this->putJson("/api/boms/{$bom['id']}", [
            'name' => 'T-shirt recipe',
            'product_id' => $this->finished->id,
            'output_quantity' => 10,
            'items' => [
                ['product_id' => $this->fabric->id, 'quantity' => 5, 'unit_cost' => 10],
            ],
        ])->assertOk()->assertJsonPath('data.material_cost', '50.00');

        $this->assertSame(1, Bom::find($bom['id'])->items()->count());
    }

    public function test_requires_at_least_one_component(): void
    {
        $this->authorize();

        $this->postJson('/api/boms', [
            'name' => 'Empty',
            'product_id' => $this->finished->id,
            'output_quantity' => 1,
            'items' => [],
        ])->assertStatus(422)->assertJsonValidationErrors('items');
    }
}
