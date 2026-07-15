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
        'leads', 'follow-ups', 'loyalty', 'coupons', 'referrals', 'whatsapp', 'sms', 'email', 'marketing-campaigns',
        'salesmen', 'salesman-targets', 'beats', 'salesman-visits',
        'salesman-locations', 'salesman-collections', 'salesman-expenses',
        'stock', 'stock-ledger', 'stock-adjustments', 'stock-transfers', 'stock-returns', 'cycle-counts',
        'gate-entries', 'advanced-inventory', 'barcodes',
        'purchase-indents', 'rfqs', 'vendor-ratings',
        'purchases', 'purchase-invoices', 'sales', 'sales-returns', 'b2b-orders',
        'ecommerce-integrations', 'channel-orders', 'channel-listings',
        'reports', 'users', 'roles', 'settings',
        'companies', 'branches', 'financial-years', 'gst-registrations', 'backups',
        'hsn-codes', 'e-invoices', 'e-way-bills',
        'departments', 'employees', 'attendance', 'salaries',
        'leave-types', 'leave-applications', 'overtime', 'employee-bonuses',
        'salary-advances', 'employee-loans', 'biometric-devices', 'biometric-punches',
        'salary-structures', 'payroll',
        'tailors', 'contractors', 'rate-cards', 'piece-rates', 'tailor-works', 'tailor-payments',
        'tailor-alterations', 'tailor-bonuses',
        'embroidery-vendors', 'embroidery-works', 'embroidery-payments',
        'boms', 'manufacturing-orders', 'colour-printing', 'job-works',
        'textile',
        'expenses', 'expense-categories',
        'vehicles', 'assets',
        'employee-claims', 'vehicle-expenses', 'fuel-expenses',
        'electricity-bills', 'factory-maintenances', 'asset-maintenances',
        'drivers', 'delivery-routes', 'dispatch-plans', 'dispatch-pods', 'transport-charges',
        'payments', 'payment-gateways',
        'bank-accounts', 'vouchers', 'tds-tcs-rates',
    ];

    /**
     * Modules that are view-only (no create/edit/delete) — e.g. an
     * immutable log with no user-facing mutation actions.
     */
    private array $viewOnlyModules = [
        'audit-logs', 'notifications', 'accounts', 'channel-sync-logs',
        // AI Features (SRS §22) — read-only analytics + assistant.
        'ai',
    ];

    /**
     * Modules that also support an "approve" permission.
     */
    private array $approvable = [
        'purchase-indents', 'purchases', 'purchase-invoices',
        'sales', 'sales-returns', 'b2b-orders', 'channel-orders', 'stock-adjustments', 'stock-transfers', 'stock-returns', 'cycle-counts',
        'job-works', 'expenses',
        'employee-claims', 'vehicle-expenses', 'fuel-expenses',
        'electricity-bills', 'factory-maintenances', 'asset-maintenances',
        'leave-applications', 'overtime', 'employee-bonuses', 'salary-advances', 'employee-loans',
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
                'boms.view', 'boms.create', 'boms.edit', 'boms.delete',
                'manufacturing-orders.view', 'manufacturing-orders.create', 'manufacturing-orders.edit', 'manufacturing-orders.delete',
                'job-works.view', 'job-works.create', 'job-works.edit', 'job-works.delete', 'job-works.approve',
                'textile.view', 'textile.create', 'textile.edit', 'textile.delete',
                'suppliers.view',
                // Transport & Logistics — dispatch is warehouse-adjacent.
                'vehicles.view', 'vehicles.create', 'vehicles.edit', 'vehicles.delete',
                'drivers.view', 'drivers.create', 'drivers.edit', 'drivers.delete',
                'delivery-routes.view', 'delivery-routes.create', 'delivery-routes.edit', 'delivery-routes.delete',
                'dispatch-plans.view', 'dispatch-plans.create', 'dispatch-plans.edit', 'dispatch-plans.delete',
                'dispatch-pods.view', 'dispatch-pods.create', 'dispatch-pods.edit', 'dispatch-pods.delete',
                'transport-charges.view', 'transport-charges.create', 'transport-charges.edit', 'transport-charges.delete',
                'reports.view', 'barcodes.view', 'ai.view',
            ],
            'purchase-manager' => [
                'products.view', 'categories.view', 'brands.view', 'units.view',
                'suppliers.view', 'suppliers.create', 'suppliers.edit',
                'purchase-indents.view', 'purchase-indents.create', 'purchase-indents.edit', 'purchase-indents.delete', 'purchase-indents.approve',
                'rfqs.view', 'rfqs.create', 'rfqs.edit', 'rfqs.delete',
                'vendor-ratings.view', 'vendor-ratings.create', 'vendor-ratings.delete',
                'purchases.view', 'purchases.create', 'purchases.edit', 'purchases.approve',
                'purchase-invoices.view', 'purchase-invoices.create', 'purchase-invoices.edit', 'purchase-invoices.approve',
                'email.view', 'email.create',
                'payments.view', 'payments.create',
                'stock-returns.view', 'stock-returns.create', 'stock-returns.edit', 'stock-returns.approve',
                'advanced-inventory.view', 'advanced-inventory.create',
                'stock.view', 'stock-ledger.view', 'reports.view', 'barcodes.view', 'notifications.view', 'ai.view',
            ],
            'sales-manager' => [
                'products.view', 'categories.view', 'brands.view', 'units.view',
                'customers.view', 'customers.create', 'customers.edit',
                'customer-categories.view', 'customer-categories.create', 'customer-categories.edit',
                'brokers.view', 'brokers.create', 'brokers.edit',
                'price-lists.view',
                'leads.view', 'leads.create', 'leads.edit', 'leads.delete',
                'follow-ups.view', 'follow-ups.create', 'follow-ups.edit', 'follow-ups.delete',
                'loyalty.view', 'loyalty.create', 'loyalty.edit', 'loyalty.delete',
                'coupons.view', 'coupons.create', 'coupons.edit', 'coupons.delete',
                'referrals.view', 'referrals.create', 'referrals.edit', 'referrals.delete',
                'whatsapp.view', 'whatsapp.create', 'whatsapp.edit', 'whatsapp.delete',
                'sms.view', 'sms.create', 'sms.edit', 'sms.delete',
                'email.view', 'email.create', 'email.edit', 'email.delete',
                'marketing-campaigns.view', 'marketing-campaigns.create', 'marketing-campaigns.edit', 'marketing-campaigns.delete',
                'sales.view', 'sales.create', 'sales.edit', 'sales.approve',
                'b2b-orders.view', 'b2b-orders.create', 'b2b-orders.edit', 'b2b-orders.delete', 'b2b-orders.approve',
                'payments.view', 'payments.create',
                'stock.view', 'reports.view', 'barcodes.view', 'notifications.view', 'ai.view',
                'stock-returns.view', 'stock-returns.create', 'stock-returns.edit', 'stock-returns.approve',
                'sales-returns.view', 'sales-returns.create', 'sales-returns.edit', 'sales-returns.approve',
                // Field-sales team management
                'salesmen.view', 'salesmen.create', 'salesmen.edit', 'salesmen.delete',
                'salesman-targets.view', 'salesman-targets.create', 'salesman-targets.edit', 'salesman-targets.delete',
                'beats.view', 'beats.create', 'beats.edit', 'beats.delete',
                'salesman-visits.view', 'salesman-visits.create', 'salesman-visits.edit', 'salesman-visits.delete',
                'salesman-locations.view', 'salesman-locations.create', 'salesman-locations.edit', 'salesman-locations.delete',
                'salesman-collections.view', 'salesman-collections.create', 'salesman-collections.edit', 'salesman-collections.delete',
                'salesman-expenses.view', 'salesman-expenses.create', 'salesman-expenses.edit', 'salesman-expenses.delete',
            ],
            'accountant' => [
                'suppliers.view', 'customers.view', 'customer-categories.view',
                'brokers.view', 'brokers.create', 'brokers.edit',
                'purchases.view', 'sales.view',
                'stock-returns.view',
                'purchase-invoices.view', 'purchase-invoices.approve',
                'expense-categories.view', 'expense-categories.create', 'expense-categories.edit', 'expense-categories.delete',
                'expenses.view', 'expenses.create', 'expenses.edit', 'expenses.delete', 'expenses.approve',
                'vehicles.view', 'vehicles.create', 'vehicles.edit', 'vehicles.delete',
                'assets.view', 'assets.create', 'assets.edit', 'assets.delete',
                'employee-claims.view', 'employee-claims.create', 'employee-claims.edit', 'employee-claims.delete', 'employee-claims.approve',
                'vehicle-expenses.view', 'vehicle-expenses.create', 'vehicle-expenses.edit', 'vehicle-expenses.delete', 'vehicle-expenses.approve',
                'fuel-expenses.view', 'fuel-expenses.create', 'fuel-expenses.edit', 'fuel-expenses.delete', 'fuel-expenses.approve',
                'electricity-bills.view', 'electricity-bills.create', 'electricity-bills.edit', 'electricity-bills.delete', 'electricity-bills.approve',
                'factory-maintenances.view', 'factory-maintenances.create', 'factory-maintenances.edit', 'factory-maintenances.delete', 'factory-maintenances.approve',
                'asset-maintenances.view', 'asset-maintenances.create', 'asset-maintenances.edit', 'asset-maintenances.delete', 'asset-maintenances.approve',
                'payments.view', 'payments.create', 'payments.edit', 'payments.delete',
                'payment-gateways.view', 'payment-gateways.create', 'payment-gateways.edit', 'payment-gateways.delete',
                'bank-accounts.view', 'bank-accounts.create', 'bank-accounts.edit', 'bank-accounts.delete',
                'vouchers.view', 'vouchers.create', 'vouchers.edit', 'vouchers.delete',
                'tds-tcs-rates.view', 'tds-tcs-rates.create', 'tds-tcs-rates.edit', 'tds-tcs-rates.delete',
                'accounts.view',
                // GST integration (§20): HSN master, e-invoice, e-way bill.
                'gst-registrations.view', 'gst-registrations.create', 'gst-registrations.edit', 'gst-registrations.delete',
                'hsn-codes.view', 'hsn-codes.create', 'hsn-codes.edit', 'hsn-codes.delete',
                'e-invoices.view', 'e-invoices.create', 'e-invoices.edit',
                'e-way-bills.view', 'e-way-bills.create', 'e-way-bills.edit',
                'reports.view', 'stock.view', 'stock-ledger.view', 'notifications.view', 'ai.view',
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
