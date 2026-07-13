<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Company;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // 1. Roles & permissions
        $this->call(RolePermissionSeeder::class);

        // 2. Default admin user
        $admin = User::updateOrCreate(
            ['email' => 'admin@sims.test'],
            [
                'name' => 'System Administrator',
                'password' => Hash::make('password'),
                'is_active' => true,
            ]
        );
        $admin->syncRoles(['super-admin']);

        // 3. Baseline units of measure
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

        // 4. Default main warehouse
        Warehouse::updateOrCreate(
            ['code' => 'WH-MAIN'],
            ['name' => 'Main Warehouse', 'type' => 'main', 'is_active' => true]
        );

        // 5. A couple of sample master records
        Brand::updateOrCreate(['slug' => 'generic'], ['name' => 'Generic']);
        Category::updateOrCreate(['slug' => 'uncategorized'], ['name' => 'Uncategorized']);

        // 6. Default company/branch (SRS §4.12) — every user gets access and an active context.
        $company = Company::updateOrCreate(
            ['code' => 'MAIN'],
            ['name' => 'Main Company', 'is_active' => true]
        );
        $branch = Branch::updateOrCreate(
            ['company_id' => $company->id, 'code' => 'HO'],
            ['name' => 'Head Office', 'is_active' => true]
        );

        User::all()->each(function (User $user) use ($company, $branch) {
            $user->companies()->syncWithoutDetaching([$company->id]);
            $user->branches()->syncWithoutDetaching([$branch->id]);
            if (! $user->current_company_id) {
                $user->update(['current_company_id' => $company->id, 'current_branch_id' => $branch->id]);
            }
        });
    }
}
