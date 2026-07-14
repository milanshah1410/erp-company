<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class RfidTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['company_name' => 'Test Co', 'status' => 'active']);
        app(TenantContext::class)->set($this->tenant->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
    }

    private function actAs(string ...$permissions): User
    {
        $user = User::factory()->create(['tenant_id' => $this->tenant->id, 'is_active' => true]);
        foreach ($permissions as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_lookup_resolves_a_product_by_its_rfid_tag(): void
    {
        $this->actAs('barcodes.view');
        $product = Product::forceCreate(['sku' => 'SKU1', 'name' => 'Widget', 'rfid_tag' => 'EPC-ABC-001']);

        $this->getJson('/api/barcode/lookup?code=EPC-ABC-001')
            ->assertOk()
            ->assertJsonPath('type', 'product')
            ->assertJsonPath('product.id', $product->id)
            ->assertJsonPath('product.rfid_tag', 'EPC-ABC-001');
    }

    public function test_lookup_resolves_a_variant_by_its_rfid_tag(): void
    {
        $this->actAs('barcodes.view');
        $product = Product::forceCreate(['sku' => 'SKU2', 'name' => 'Shirt']);
        $variant = ProductVariant::forceCreate([
            'product_id' => $product->id, 'sku' => 'SKU2-RED', 'name' => 'Red', 'rfid_tag' => 'EPC-RED-9',
        ]);

        $this->getJson('/api/barcode/lookup?code=EPC-RED-9')
            ->assertOk()
            ->assertJsonPath('type', 'variant')
            ->assertJsonPath('variant.id', $variant->id)
            ->assertJsonPath('variant.rfid_tag', 'EPC-RED-9');
    }

    public function test_assign_binds_an_rfid_tag_to_a_product(): void
    {
        $this->actAs('products.edit');
        $product = Product::forceCreate(['sku' => 'SKU3', 'name' => 'Cap']);

        $this->postJson('/api/rfid/assign', ['type' => 'product', 'id' => $product->id, 'rfid_tag' => 'EPC-NEW-1'])
            ->assertOk()
            ->assertJsonPath('rfid_tag', 'EPC-NEW-1');

        $this->assertSame('EPC-NEW-1', $product->fresh()->rfid_tag);
    }

    public function test_assign_rejects_a_tag_already_used_by_another_item(): void
    {
        $this->actAs('products.edit');
        Product::forceCreate(['sku' => 'SKU4', 'name' => 'Existing', 'rfid_tag' => 'EPC-DUP']);
        $other = Product::forceCreate(['sku' => 'SKU5', 'name' => 'Other']);

        $this->postJson('/api/rfid/assign', ['type' => 'product', 'id' => $other->id, 'rfid_tag' => 'EPC-DUP'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('rfid_tag');

        $this->assertNull($other->fresh()->rfid_tag);
    }

    public function test_assign_requires_products_edit_permission(): void
    {
        $this->actAs('barcodes.view'); // no products.edit
        $product = Product::forceCreate(['sku' => 'SKU6', 'name' => 'Gloves']);

        $this->postJson('/api/rfid/assign', ['type' => 'product', 'id' => $product->id, 'rfid_tag' => 'EPC-X'])
            ->assertForbidden();
    }

    public function test_duplicate_rfid_tag_is_rejected_on_product_update(): void
    {
        $this->actAs('products.edit');
        Product::forceCreate(['sku' => 'SKU7', 'name' => 'A', 'rfid_tag' => 'EPC-TAKEN']);
        $b = Product::forceCreate(['sku' => 'SKU8', 'name' => 'B']);

        $this->putJson("/api/products/{$b->id}", ['sku' => 'SKU8', 'name' => 'B', 'rfid_tag' => 'EPC-TAKEN'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('rfid_tag');
    }
}
