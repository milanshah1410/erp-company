<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Company;
use App\Models\ExpenseCategory;
use App\Models\Setting;
use App\Models\Unit;
use App\Models\Warehouse;
use Illuminate\Database\Seeder;

/**
 * Seeds baseline data for a freshly-provisioned tenant. MUST run inside a
 * TenantContext (ProvisionTenant wraps it in runWith), so every row created
 * here is auto-stamped with the tenant's id.
 *
 * Roles & permissions are NOT seeded here — they are global (shared across all
 * tenants, tenant_id = NULL) and seeded once by RolePermissionSeeder. Nor is
 * the admin user, which ProvisionTenant creates from the signup credentials.
 */
class TenantDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Baseline units of measure.
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

        // 6. Default company expense heads.
        $expenseCategories = [
            'Office Rent', 'Electricity', 'Internet', 'Petrol', 'Diesel', 'Salary',
            'Food', 'Courier', 'Transport', 'Machine Repair', 'Maintenance',
            'Marketing', 'Advertisement', 'GST Payment', 'Bank Charges', 'Miscellaneous',
        ];
        foreach ($expenseCategories as $name) {
            ExpenseCategory::updateOrCreate(['name' => $name], ['is_active' => true]);
        }

        // 7. Default cash account (feeds the Cash Book out of the box).
        BankAccount::updateOrCreate(
            ['name' => 'Cash in Hand'],
            ['type' => 'cash', 'opening_balance' => 0, 'is_active' => true]
        );

        // 8. Baseline settings.
        Setting::updateOrCreate(['key' => 'costing_method'], ['value' => 'weighted_average']);

        // 9. Baseline HSN/SAC master (GST integration).
        (new HsnCodeSeeder)->run();

        // 10. Baseline Import & Export masters (countries, ports, carriers).
        (new EximMasterSeeder)->run();
    }
}
