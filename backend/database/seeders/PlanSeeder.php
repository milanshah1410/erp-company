<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'slug' => 'starter',
                'name' => 'Starter',
                'price' => 999,
                'billing_cycle' => 'monthly',
                'is_custom' => false,
                'sort_order' => 1,
                'feature_limits' => [
                    'max_warehouses' => 1,
                    'max_users' => 3,
                    'max_products' => 500,
                    'barcode' => false,
                    'reports' => false,
                    'api_access' => false,
                    'integrations' => false,
                    'support' => 'email',
                ],
            ],
            [
                'slug' => 'growth',
                'name' => 'Growth',
                'price' => 2999,
                'billing_cycle' => 'monthly',
                'is_custom' => false,
                'sort_order' => 2,
                'feature_limits' => [
                    'max_warehouses' => 5,
                    'max_users' => 15,
                    'max_products' => 10000,
                    'barcode' => true,
                    'reports' => true,
                    'api_access' => false,
                    'integrations' => false,
                    'support' => 'email',
                ],
            ],
            [
                'slug' => 'enterprise',
                'name' => 'Enterprise',
                'price' => 0, // custom / contact sales
                'billing_cycle' => 'monthly',
                'is_custom' => true,
                'sort_order' => 3,
                'feature_limits' => [
                    // null = unlimited
                    'max_warehouses' => null,
                    'max_users' => null,
                    'max_products' => null,
                    'barcode' => true,
                    'reports' => true,
                    'api_access' => true,
                    'integrations' => true,
                    'support' => 'dedicated',
                ],
            ],
        ];

        foreach ($plans as $plan) {
            Plan::updateOrCreate(['slug' => $plan['slug']], $plan);
        }
    }
}
