<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionSeeder extends Seeder
{
    /**
     * Modules that get a full view/create/edit/delete permission set.
     */
    private array $modules = [
        'products', 'categories', 'brands', 'units', 'warehouses',
        'suppliers', 'customers', 'customer-categories', 'brokers', 'price-lists',
        'stock', 'stock-ledger', 'stock-adjustments', 'stock-transfers', 'stock-returns', 'cycle-counts',
        'gate-entries', 'advanced-inventory', 'barcodes',
        'purchases', 'purchase-invoices', 'sales', 'sales-returns', 'reports', 'users', 'roles', 'settings',
        'companies', 'branches', 'financial-years', 'gst-registrations', 'backups',
        'departments', 'employees', 'attendance', 'salaries',
        'tailors', 'rate-cards', 'piece-rates', 'tailor-works', 'tailor-payments',
        'manufacturing-orders',
    ];

    /**
     * Modules that are view-only (no create/edit/delete) — e.g. an
     * immutable log with no user-facing mutation actions.
     */
    private array $viewOnlyModules = [
        'audit-logs', 'notifications',
    ];

    /**
     * Modules that also support an "approve" permission.
     */
    private array $approvable = [
        'purchases', 'purchase-invoices',
        'sales', 'sales-returns', 'stock-adjustments', 'stock-transfers', 'stock-returns', 'cycle-counts',
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // --- Build permissions ---
        $permissions = [];
        foreach ($this->modules as $module) {
            foreach (['view', 'create', 'edit', 'delete'] as $action) {
                $permissions[] = "{$module}.{$action}";
            }
            if (in_array($module, $this->approvable, true)) {
                $permissions[] = "{$module}.approve";
            }
        }
        foreach ($this->viewOnlyModules as $module) {
            $permissions[] = "{$module}.view";
        }

        foreach ($permissions as $name) {
            Permission::findOrCreate($name, 'web');
        }

        // --- Build roles with their permission sets ---
        $roleMap = [
            'super-admin' => '*', // gets everything
            'admin' => '*',
            'warehouse-manager' => [
                'products.view', 'categories.view', 'brands.view', 'units.view',
                'warehouses.view', 'warehouses.edit',
                'stock.view', 'stock.edit', 'stock-ledger.view',
                'stock-adjustments.view', 'stock-adjustments.create', 'stock-adjustments.edit', 'stock-adjustments.approve',
                'stock-transfers.view', 'stock-transfers.create', 'stock-transfers.edit', 'stock-transfers.approve',
                'stock-returns.view', 'stock-returns.create', 'stock-returns.edit', 'stock-returns.approve',
                'cycle-counts.view', 'cycle-counts.create', 'cycle-counts.edit', 'cycle-counts.approve',
                'gate-entries.view', 'gate-entries.create', 'gate-entries.edit', 'gate-entries.delete',
                'sales.view', 'sales.edit',
                'advanced-inventory.view', 'advanced-inventory.create', 'advanced-inventory.edit', 'advanced-inventory.delete',
                'manufacturing-orders.view', 'manufacturing-orders.create', 'manufacturing-orders.edit', 'manufacturing-orders.delete',
                'reports.view', 'barcodes.view',
            ],
            'purchase-manager' => [
                'products.view', 'categories.view', 'brands.view', 'units.view',
                'suppliers.view', 'suppliers.create', 'suppliers.edit',
                'purchases.view', 'purchases.create', 'purchases.edit', 'purchases.approve',
                'purchase-invoices.view', 'purchase-invoices.create', 'purchase-invoices.edit', 'purchase-invoices.approve',
                'stock-returns.view', 'stock-returns.create', 'stock-returns.edit', 'stock-returns.approve',
                'advanced-inventory.view', 'advanced-inventory.create',
                'stock.view', 'stock-ledger.view', 'reports.view', 'barcodes.view', 'notifications.view',
            ],
            'sales-manager' => [
                'products.view', 'categories.view', 'brands.view', 'units.view',
                'customers.view', 'customers.create', 'customers.edit',
                'customer-categories.view', 'customer-categories.create', 'customer-categories.edit',
                'brokers.view', 'brokers.create', 'brokers.edit',
                'price-lists.view',
                'sales.view', 'sales.create', 'sales.edit', 'sales.approve',
                'stock.view', 'reports.view', 'barcodes.view', 'notifications.view',
                'stock-returns.view', 'stock-returns.create', 'stock-returns.edit', 'stock-returns.approve',
                'sales-returns.view', 'sales-returns.create', 'sales-returns.edit', 'sales-returns.approve',
            ],
            'accountant' => [
                'suppliers.view', 'customers.view', 'customer-categories.view',
                'brokers.view', 'brokers.create', 'brokers.edit',
                'purchases.view', 'sales.view',
                'stock-returns.view',
                'purchase-invoices.view', 'purchase-invoices.approve',
                'reports.view', 'stock.view', 'stock-ledger.view', 'notifications.view',
            ],
            'staff' => [
                'products.view', 'stock.view', 'stock.edit',
                'stock-ledger.view', 'warehouses.view',
                'gate-entries.view', 'gate-entries.create', 'gate-entries.edit',
                'sales.view', 'sales.edit', 'barcodes.view',
            ],
            'auditor' => [
                // read-only: every *.view permission
            ],
        ];

        foreach ($roleMap as $roleName => $perms) {
            $role = Role::findOrCreate($roleName, 'web');

            if ($perms === '*') {
                $role->syncPermissions(Permission::all());
            } elseif ($roleName === 'auditor') {
                $viewOnly = Permission::where('name', 'like', '%.view')->get();
                $role->syncPermissions($viewOnly);
            } else {
                $role->syncPermissions($perms);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
