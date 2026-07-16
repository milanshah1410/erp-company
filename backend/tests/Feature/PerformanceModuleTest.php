<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantCache;
use App\Support\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * §17 Performance — covers the version-tagged cache layer, cache-backed
 * settings reads with write-invalidation, and the admin diagnostics endpoint.
 */
class PerformanceModuleTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['company_name' => 'Perf Co', 'status' => 'active']);
        app(TenantContext::class)->set($this->tenant->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
    }

    private function actingAsAdmin(): User
    {
        (new RolePermissionSeeder)->run();
        $admin = User::forceCreate([
            'name' => 'Admin', 'email' => 'perf-admin@acme.test',
            'password' => bcrypt('password'), 'is_active' => true, 'tenant_id' => $this->tenant->id,
        ]);
        $admin->assignRole('super-admin');
        Sanctum::actingAs($admin, ['*']);

        return $admin;
    }

    public function test_versioned_cache_is_invalidated_by_a_bump(): void
    {
        $calls = 0;
        $build = function () use (&$calls) {
            $calls++;

            return "value-$calls";
        };

        $first = TenantCache::rememberVersioned('widgets', 'list', 300, $build);
        $second = TenantCache::rememberVersioned('widgets', 'list', 300, $build);

        // Same version → served from cache, callback ran once.
        $this->assertSame('value-1', $first);
        $this->assertSame('value-1', $second);
        $this->assertSame(1, $calls);

        // Bump the group → next read misses and rebuilds.
        TenantCache::bump('widgets');
        $third = TenantCache::rememberVersioned('widgets', 'list', 300, $build);

        $this->assertSame('value-2', $third);
        $this->assertSame(2, $calls);
    }

    public function test_settings_reads_are_cached_and_busted_on_write(): void
    {
        Setting::set('currency_code', 'INR');
        $this->assertSame('INR', Setting::get('currency_code'));

        // Change the row directly on the DB (bypassing the model events) to prove
        // the value is being served from cache until an invalidation happens.
        DB::table('settings')
            ->where('tenant_id', $this->tenant->id)
            ->where('key', 'currency_code')
            ->update(['value' => 'USD']);

        $this->assertSame('INR', Setting::get('currency_code'), 'stale read expected while cached');

        // A write through the model bumps the cache version and reveals the DB value.
        Setting::set('unrelated_key', 'x');
        $this->assertSame('USD', Setting::get('currency_code'), 'cache should refresh after a Setting write');
    }

    public function test_performance_metrics_endpoint_reports_cache_queue_and_index_health(): void
    {
        $this->actingAsAdmin();

        $response = $this->getJson('/api/system-tools/performance')->assertOk();

        $response->assertJsonStructure([
            'cache' => ['store', 'versions' => ['settings', 'menu_items', 'dashboard']],
            'queue' => ['connection', 'driver', 'pending', 'failed', 'by_queue'],
            'database' => ['driver', 'connection_driver', 'tables', 'indexes', 'unindexed_tables'],
            'generated_at',
        ]);

        // The schema has migrated many tables with many indexes.
        $this->assertGreaterThan(0, $response->json('database.tables'));
        $this->assertGreaterThan(0, $response->json('database.indexes'));
    }

    public function test_performance_metrics_endpoint_requires_permission(): void
    {
        $this->getJson('/api/system-tools/performance')->assertUnauthorized();
    }
}
