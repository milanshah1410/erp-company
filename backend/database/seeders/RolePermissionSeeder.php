<?php

namespace Database\Seeders;

use App\Support\TenantRoleCatalog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Seeds the web-guard permission vocabulary and the global role templates
 * (tenant_id NULL). Deliberately set-based: it writes the permission/role/pivot
 * tables directly in a handful of bulk statements instead of going through
 * Spatie's models — Role::syncPermissions() flushes the package cache per call,
 * so seeding ~50 roles that way rebuilds the full cache ~50 times and takes
 * minutes. Semantics are unchanged: each managed role ends up with exactly its
 * defined permission set (extras are detached, like syncPermissions).
 */
class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        // --- Build permissions (vocabulary lives in TenantRoleCatalog) ---
        $permissions = [];
        foreach (TenantRoleCatalog::MODULES as $module) {
            foreach (['view', 'create', 'edit', 'delete'] as $action) {
                $permissions[] = "{$module}.{$action}";
            }
            if (in_array($module, TenantRoleCatalog::APPROVABLE, true)) {
                $permissions[] = "{$module}.approve";
            }
        }
        foreach (TenantRoleCatalog::VIEW_ONLY_MODULES as $module) {
            $permissions[] = "{$module}.view";
        }

        $now = now();

        // Bulk-ensure the vocabulary: one SELECT for what exists, one INSERT
        // for the rest. Name + guard is the whole identity — permissions carry
        // no team. Central + web permissions share one table, so everything
        // below is guard-scoped or a central permission would leak into a web
        // role.
        $existingNames = DB::table('permissions')->where('guard_name', 'web')->pluck('name')->all();
        $missing = array_values(array_diff($permissions, $existingNames));
        if ($missing !== []) {
            DB::table('permissions')->insert(array_map(fn (string $name) => [
                'name' => $name, 'guard_name' => 'web', 'created_at' => $now, 'updated_at' => $now,
            ], $missing));
        }

        // name → id for the FULL web-guard set (may hold more than the
        // vocabulary, e.g. admin-created custom permissions — '*' roles get
        // those too, exactly as the old syncPermissions($webPermissions) did).
        $permissionIds = DB::table('permissions')->where('guard_name', 'web')->pluck('id', 'name');
        $allIds = $permissionIds->values()->all();
        $viewIds = $permissionIds->filter(fn ($id, string $name) => str_ends_with($name, '.view'))->values()->all();

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
                // Import & Export masters — read-only; the logistics desk owns them.
                'countries.view', 'ports.view', 'shipping-lines.view', 'airlines.view',
                'freight-forwarders.view', 'cha-agents.view', 'transporters.view',
                // Inbound cargo lands in the warehouse, so the boxes and the
                // shipment behind them are visible here; the paperwork is not.
                'shipments.view', 'shipment-documents.view',
                'shipment-containers.view', 'shipment-containers.create', 'shipment-containers.edit',
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
                // Import buying: overseas vendors are ordinary suppliers, so the
                // trade masters behind an import PO are read-only here.
                'countries.view', 'ports.view', 'shipping-lines.view', 'airlines.view',
                'freight-forwarders.view', 'cha-agents.view', 'transporters.view',
                // …but the import shipment itself is raised off the PO here.
                'shipments.view', 'shipments.create', 'shipments.edit',
                'shipment-documents.view', 'shipment-documents.create', 'shipment-documents.edit',
                'shipment-containers.view', 'shipment-expenses.view', 'shipment-expenses.create',
                'shipment-customs.view',
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
                // Export selling: the trade masters are read-only, but the export
                // shipment against a sales order is raised here.
                'countries.view', 'ports.view', 'shipping-lines.view', 'airlines.view',
                'freight-forwarders.view', 'cha-agents.view', 'transporters.view',
                'shipments.view', 'shipments.create', 'shipments.edit',
                'shipment-documents.view', 'shipment-documents.create', 'shipment-documents.edit',
                'shipment-containers.view', 'shipment-expenses.view', 'shipment-expenses.create',
                'shipment-customs.view', 'shipment-payments.view',
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
                // Import & Export: the money side of a shipment — logistics bills,
                // duty and the goods-value settlements — is the accounts desk's.
                'shipments.view', 'shipment-customs.view',
                'shipment-expenses.view', 'shipment-expenses.create', 'shipment-expenses.edit', 'shipment-expenses.delete',
                'shipment-payments.view', 'shipment-payments.create', 'shipment-payments.edit', 'shipment-payments.delete',
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

        // --- Desired permission-id set per role ---
        $desired = [];
        foreach ($roleMap as $roleName => $perms) {
            if ($perms === '*') {
                $desired[$roleName] = $allIds;
            } elseif ($roleName === 'auditor') {
                $desired[$roleName] = $viewIds;
            } else {
                $desired[$roleName] = $this->idsFor($perms, $permissionIds);
            }
        }

        // Level-2 departmental roles (TenantRoleCatalog) — the org-chart roles
        // beyond the hand-tuned ones above. A null expansion means "grant
        // everything" (owner / MD / CEO).
        foreach (TenantRoleCatalog::newRoles() as $roleName => $def) {
            $expanded = TenantRoleCatalog::expand($def['spec']);
            $desired[$roleName] = $expanded === null ? $allIds : $this->idsFor($expanded, $permissionIds);
        }

        // --- Bulk-ensure the global role templates (tenant_id NULL, web) ---
        $teamKey = config('permission.column_names.team_foreign_key'); // tenant_id
        $roleNames = array_keys($desired);

        $roleIds = $this->globalRoleIds($teamKey, $roleNames);
        $missingRoles = array_values(array_diff($roleNames, $roleIds->keys()->all()));
        if ($missingRoles !== []) {
            DB::table('roles')->insert(array_map(fn (string $name) => [
                $teamKey => null, 'name' => $name, 'guard_name' => 'web',
                'created_at' => $now, 'updated_at' => $now,
            ], $missingRoles));
            $roleIds = $this->globalRoleIds($teamKey, $roleNames);
        }

        // --- Diff-sync role_has_permissions against the desired sets ---
        // Current pivot rows for the managed roles only (custom tenant roles
        // are never touched), grouped role_id => [permission ids].
        $current = DB::table('role_has_permissions')
            ->whereIn('role_id', $roleIds->values()->all())
            ->get()
            ->groupBy('role_id')
            ->map(fn ($rows) => $rows->pluck('permission_id')->flip());

        $toInsert = [];
        foreach ($desired as $roleName => $ids) {
            $roleId = $roleIds[$roleName];
            $have = $current->get($roleId, collect());

            foreach ($ids as $pid) {
                if (! isset($have[$pid])) {
                    $toInsert[] = ['role_id' => $roleId, 'permission_id' => $pid];
                }
            }

            // syncPermissions semantics: drop grants no longer in the set.
            $stale = $have->keys()->diff($ids);
            if ($stale->isNotEmpty()) {
                DB::table('role_has_permissions')
                    ->where('role_id', $roleId)
                    ->whereIn('permission_id', $stale->all())
                    ->delete();
            }
        }

        foreach (array_chunk($toInsert, 2000) as $chunk) {
            DB::table('role_has_permissions')->insert($chunk);
        }

        // One cache flush at the end (the whole point: not one per role).
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** id-by-name map of the global (tenant_id NULL) web-guard role templates. */
    private function globalRoleIds(string $teamKey, array $roleNames): \Illuminate\Support\Collection
    {
        return DB::table('roles')
            ->where('guard_name', 'web')
            ->whereNull($teamKey)
            ->whereIn('name', $roleNames)
            ->pluck('id', 'name');
    }

    /**
     * Resolve permission names to ids, failing loudly on a name outside the
     * seeded vocabulary (same outcome as Spatie's PermissionDoesNotExist).
     *
     * @param  array<int, string>  $names
     * @return array<int, int>
     */
    private function idsFor(array $names, \Illuminate\Support\Collection $map): array
    {
        $ids = [];
        foreach (array_unique($names) as $name) {
            $ids[] = $map[$name]
                ?? throw new \RuntimeException("RolePermissionSeeder references unknown permission [{$name}].");
        }

        return $ids;
    }
}
