<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Company;
use App\Models\Unit;
use App\Models\Warehouse;
use Illuminate\Database\Seeder;

/**
 * Seeds a freshly-provisioned tenant database with baseline data. Unlike the
 * central DatabaseSeeder it does NOT create the hardcoded admin@sims.test user
 * — the tenant's first admin is created by App\Jobs\ProvisionTenant from the
 * signup credentials.
 */
class TenantDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Roles & permissions (per-tenant).
        $this->call(RolePermissionSeeder::class);

        // 2. Baseline units of measure.
        $units = [
            ['name' => 'Pieces', 'short_name' => 'pcs'],
            ['name' => 'Kilogram', 'short_name' => 'kg'],
            ['name' => 'Litre', 'short_name' => 'ltr'],
            ['name' => 'Box', 'short_name' => 'box'],
            ['name' => 'Meter', 'short_name' => 'm'],
        ];
        foreach ($units as $u) {
            Unit::updateOrCreate(['short_name' => $u['short_name']], $u);
        }

        // 3. Default main warehouse.
        Warehouse::updateOrCreate(
            ['code' => 'WH-MAIN'],
            ['name' => 'Main Warehouse', 'type' => 'main', 'is_active' => true]
        );

        // 4. Sample master records.
        Brand::updateOrCreate(['slug' => 'generic'], ['name' => 'Generic']);
        Category::updateOrCreate(['slug' => 'uncategorized'], ['name' => 'Uncategorized']);

        // 5. Default company/branch (intra-tenant sub-org).
        $company = Company::updateOrCreate(
            ['code' => 'MAIN'],
            ['name' => 'Main Company', 'is_active' => true]
        );
        Branch::updateOrCreate(
            ['company_id' => $company->id, 'code' => 'HO'],
            ['name' => 'Head Office', 'is_active' => true]
        );
    }
}
