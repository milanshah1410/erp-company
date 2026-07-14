<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Tailor;
use App\Models\TailorWork;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TailorWorkService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * HTTP-level coverage for the tailor-module extensions: contractor rollup,
 * alteration lifecycle, bonus calculate/approve and the performance report.
 */
class TailorModuleApiTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Tailor $tailor;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['company_name' => 'Test Co', 'status' => 'active']);
        app(TenantContext::class)->set($this->tenant->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);

        $this->tailor = Tailor::create(['code' => 'T1', 'name' => 'Ramesh', 'opening_balance' => 0, 'is_active' => true]);
        $this->product = Product::forceCreate(['sku' => 'SHIRT', 'name' => 'Party Shirt']);
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

    public function test_contractor_crud_and_performance_rollup(): void
    {
        $this->authorize('contractors.view', 'contractors.create');

        $contractor = $this->postJson('/api/contractors', [
            'code' => 'C1', 'name' => 'Acme Tailors', 'is_active' => true,
        ])->assertCreated()->json('data');

        $this->tailor->update(['contractor_id' => $contractor['id']]);

        // Earn something so the rollup is non-zero.
        app(TailorWorkService::class)->complete(
            TailorWork::create([
                'work_number' => TailorWork::nextNumber(),
                'tailor_id' => $this->tailor->id,
                'product_id' => $this->product->id,
                'issued_qty' => 10, 'rate' => 100, 'status' => 'pending',
                'issue_date' => now()->toDateString(),
            ]),
            ['qty' => 10]
        );

        $res = $this->getJson("/api/contractors/{$contractor['id']}/performance")
            ->assertOk()
            ->assertJsonPath('data.0.tailor', 'Ramesh');
        $this->assertEquals(1000, $res->json('summary.outstanding_balance'));
    }

    public function test_alteration_create_then_complete(): void
    {
        $this->authorize('tailor-alterations.view', 'tailor-alterations.create', 'tailor-alterations.edit');

        $alteration = $this->postJson('/api/tailor-alterations', [
            'tailor_id' => $this->tailor->id,
            'product_id' => $this->product->id,
            'qty' => 2,
            'reason' => 'Sleeve length',
        ])->assertCreated()->assertJsonPath('data.status', 'pending')->json('data');

        $this->postJson("/api/tailor-alterations/{$alteration['id']}/complete")
            ->assertOk()
            ->assertJsonPath('data.status', 'done')
            ->assertJsonPath('data.completed_date', now()->toDateString());
    }

    public function test_bonus_calculate_then_create_and_approve(): void
    {
        $this->authorize('tailor-bonuses.view', 'tailor-bonuses.create', 'tailor-bonuses.edit');

        app(TailorWorkService::class)->complete(
            TailorWork::create([
                'work_number' => TailorWork::nextNumber(),
                'tailor_id' => $this->tailor->id,
                'product_id' => $this->product->id,
                'issued_qty' => 100, 'rate' => 100, 'status' => 'pending',
                'issue_date' => now()->toDateString(),
            ]),
            ['qty' => 80]
        );

        // (80 - 50) * 5 = 150
        $calc = $this->getJson('/api/tailor-bonuses/calculate?'.http_build_query([
            'tailor_id' => $this->tailor->id, 'basis' => 'pieces', 'rate' => 5, 'target' => 50,
        ]))->assertOk();
        $this->assertEquals(150, $calc->json('data.amount'));

        $bonus = $this->postJson('/api/tailor-bonuses', [
            'tailor_id' => $this->tailor->id, 'basis' => 'pieces', 'rate' => 5, 'amount' => 150,
        ])->assertCreated()->assertJsonPath('data.status', 'draft')->json('data');

        $this->postJson("/api/tailor-bonuses/{$bonus['id']}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $this->assertSame(150.0, $this->tailor->fresh()->totalBonus());
    }

    public function test_performance_report_returns_a_row_per_tailor(): void
    {
        $this->authorize('reports.view');

        app(TailorWorkService::class)->complete(
            TailorWork::create([
                'work_number' => TailorWork::nextNumber(),
                'tailor_id' => $this->tailor->id,
                'product_id' => $this->product->id,
                'issued_qty' => 50, 'rate' => 100, 'status' => 'pending',
                'issue_date' => now()->toDateString(),
            ]),
            ['qty' => 45, 'rejected_qty' => 5, 'reject_action' => 'scrap']
        );

        $perf = $this->getJson('/api/reports/tailor-performance')
            ->assertOk()
            ->assertJsonPath('data.0.tailor', 'Ramesh');
        $this->assertEquals(45, $perf->json('data.0.completed_qty'));
        $this->assertEquals(5, $perf->json('data.0.rejected_qty'));
    }
}
