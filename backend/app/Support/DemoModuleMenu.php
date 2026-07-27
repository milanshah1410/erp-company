<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\MenuItem;
use App\Models\Subscription;
use App\Services\DemoDataGenerator;
use Illuminate\Support\Collection;

/**
 * Projects the demo-data module catalogue (DemoDataGenerator::MODULES) onto the
 * navigation tree (project-stack.md §3), so the operator picker on
 * /platform/ops groups its chips exactly the way the ERP menu groups its
 * screens — Masters → Products / Business Partners, Sales → Sales / CRM …
 *
 * Only the "which nav leaf owns this module" mapping lives here; the section /
 * group titles, icons and order all come from menu_items at runtime, so a menu
 * restructure moves the demo chips with it and nothing has to be re-listed.
 */
final class DemoModuleMenu
{
    /**
     * Demo module key => the menu route it hangs off. Several modules may share
     * one leaf (Invoices and POS Sales both live in the Sales group; the menu
     * has no separate Invoices screen). Modules with no entry — or whose leaf is
     * missing from the catalogue — fall into the trailing "Other" section.
     */
    private const ROUTES = [
        // Organisation structure (Administration → Organization)
        'companies' => '/companies',
        'branches' => '/branches',
        'users' => '/users',
        // Masters → Products
        'categories' => '/categories',
        'brands' => '/brands',
        'units' => '/units',
        'products' => '/products',
        'price_lists' => '/price-lists',
        // Masters → Business Partners
        'customers' => '/customers',
        'customer_categories' => '/customer-categories',
        'suppliers' => '/suppliers',
        'brokers' => '/brokers',
        // Masters → Warehouses
        'warehouses' => '/warehouses',
        // Masters → Import & Export
        'countries' => '/countries',
        'ports' => '/ports',
        'shipping_lines' => '/shipping-lines',
        'airlines' => '/airlines',
        'freight_forwarders' => '/freight-forwarders',
        'cha_agents' => '/cha-agents',
        'transporters' => '/transporters',
        'overseas_suppliers' => '/overseas-suppliers',
        'overseas_customers' => '/overseas-customers',
        // Sales
        'sales_quotations' => '/sales-quotations',
        'sales_orders' => '/sales-orders',
        'invoices' => '/sales-orders',
        'pos_sales' => '/pos',
        'b2b_orders' => '/b2b-orders',
        'sales_returns' => '/sales-returns',
        // Sales → CRM
        'leads' => '/leads',
        'follow_ups' => '/follow-ups',
        'referrals' => '/referrals',
        'coupons' => '/coupons',
        'marketing_campaigns' => '/marketing-campaigns',
        'whatsapp_messages' => '/whatsapp',
        'sms_messages' => '/sms',
        'email_logs' => '/email-logs',
        // Sales → Field Sales
        'salesmen' => '/salesmen',
        'beats' => '/beats',
        // Sales → E-Commerce
        'channel_integrations' => '/ecommerce-integrations',
        'channel_orders' => '/channel-orders',
        'channel_listings' => '/channel-listings',
        'channel_sync_logs' => '/channel-sync-logs',
        // Purchase
        'purchase_indents' => '/purchase-indents',
        'rfqs' => '/rfqs',
        'purchase_orders' => '/purchase-orders',
        'vendor_invoices' => '/purchase-invoices',
        'vendor_ratings' => '/vendor-ratings',
        // Inventory → Stock
        'stock_adjustments' => '/stock-adjustments',
        'stock_transfers' => '/stock-transfers',
        'stock_returns' => '/stock-returns',
        'gate_entries' => '/gate-entries',
        'cycle_counts' => '/cycle-counts',
        // Inventory → Tracking
        'batches' => '/batches',
        'serial_numbers' => '/serial-numbers',
        // Manufacturing → Production
        'boms' => '/boms',
        'manufacturing_orders' => '/manufacturing-orders',
        'colour_printings' => '/colour-printings',
        'job_works' => '/job-works',
        // Manufacturing → Textile
        'fabric_rolls' => '/fabric-rolls',
        'fabric_inspections' => '/fabric-inspections',
        'shrinkage_records' => '/shrinkage-records',
        'fabric_cuttings' => '/fabric-cuttings',
        // Manufacturing → Tailoring
        'tailors' => '/tailors',
        'contractors' => '/contractors',
        'rate_cards' => '/rate-cards',
        'piece_rates' => '/piece-rates',
        'tailor_works' => '/tailor-works/pending',
        'tailor_alterations' => '/tailor-alterations',
        'tailor_bonuses' => '/tailor-bonuses',
        'tailor_payments' => '/tailor-payments',
        // Manufacturing → Embroidery
        'embroidery_vendors' => '/embroidery-vendors',
        'embroidery_works' => '/embroidery-works/pending',
        'embroidery_payments' => '/embroidery-payments',
        // Finance → Accounts
        'bank_accounts' => '/bank-accounts',
        'vouchers' => '/vouchers',
        // Finance → Expenses
        'expenses' => '/expenses',
        'expense_categories' => '/expense-categories',
        'employee_claims' => '/employee-claims',
        'vehicle_expenses' => '/vehicle-expenses',
        'fuel_expenses' => '/fuel-expenses',
        'electricity_bills' => '/electricity-bills',
        'factory_maintenances' => '/factory-maintenances',
        'asset_maintenances' => '/asset-maintenances',
        'assets' => '/assets',
        // Finance → Payments
        'payment_transactions' => '/payment-transactions',
        'payment_gateway_accounts' => '/payment-gateways',
        // Finance → GST & Compliance
        'gst_registrations' => '/gst-registrations',
        'hsn_codes' => '/hsn-codes',
        'tds_tcs_rates' => '/tds-tcs-rates',
        // HR & Payroll → Human Resources
        'departments' => '/departments',
        'employees' => '/employees',
        'attendance' => '/attendance',
        'biometric_devices' => '/biometric-devices',
        'biometric_punches' => '/biometric-punches',
        'leave_types' => '/leave-types',
        'leave_applications' => '/leave-applications',
        'overtime_records' => '/overtime',
        // HR & Payroll → Payroll
        'salary_structures' => '/salary-structures',
        'salaries' => '/salaries',
        'employee_bonuses' => '/employee-bonuses',
        'salary_advances' => '/salary-advances',
        'employee_loans' => '/employee-loans',
        // Logistics
        'vehicles' => '/vehicles',
        'drivers' => '/drivers',
        'delivery_routes' => '/delivery-routes',
        'dispatch_plans' => '/dispatch-plans',
        'dispatch_pods' => '/proof-of-delivery',
        'transport_charges' => '/transport-charges',
        // Logistics → Import & Export Logistics. The boards that own a child
        // table get their own chip; the ones that are pure header projections
        // (dashboard, ETA/ETD, pending customs, shipment profit) are fed by the
        // two shipment modules and have no rows of their own to generate.
        'import_shipments' => '/import-export/import-shipments',
        'export_shipments' => '/import-export/export-shipments',
        'shipment_events' => '/import-export/timeline',
        'shipment_documents' => '/import-export/documents',
        'shipment_containers' => '/import-export/containers',
        'shipment_payments' => '/import-export/payments',
        'shipment_expenses' => '/import-export/expenses',
    ];

    /**
     * The picker tree: menu section → sub-group → demo modules.
     *
     * With a $tenantId the tree is the one that tenant actually sees: its own
     * cloned catalogue (global rows for tenants provisioned before per-tenant
     * menus), gated by its plan features and its enabled module set exactly as
     * MenuController gates the nav. So a tenant without Tailoring gets no
     * Tailoring chips. Must run inside that tenant's TenantContext — the module
     * selection is a tenant-scoped setting.
     *
     * @return list<array{label:string,icon:?string,groups:list<array{label:string,icon:?string,modules:list<array{key:string,label:string,icon:?string}>}>}>
     */
    public static function sections(?int $tenantId = null): array
    {
        $byRoute = self::modulesByRoute();
        $items = self::catalogue($tenantId);

        // The catalogue has not been seeded (fresh install) — one flat section
        // keeps the picker usable instead of dumping everything into "Other".
        if ($items->isEmpty()) {
            return [self::flatSection('All Modules', 'pi pi-database', array_keys(DemoDataGenerator::MODULES))];
        }

        // Every route the catalogue knows about, gating aside. A module whose
        // route is in here but did not get placed was hidden on purpose (the
        // tenant has that module switched off), so it stays out of "Other".
        $known = $items->pluck('route')->filter()->unique()->all();

        // No tenant in play (nothing selected yet) → no gating at all, so the
        // picker shows the whole catalogue rather than dropping the plan-gated
        // sections for want of a plan to read.
        if ($tenantId === null) {
            $visible = fn () => true;
        } else {
            $enabled = EnabledModules::forTenant($tenantId);
            $features = self::planFeatures($tenantId);
            $visible = fn (MenuItem $i) => self::visible($i, $enabled, $features);
        }

        /** @var Collection<int, Collection<int, MenuItem>> $children */
        $children = $items->groupBy(fn (MenuItem $i) => (int) $i->parent_id);
        $placed = [];
        $sections = [];

        foreach ($children->get(0, collect()) as $root) {
            if (! $visible($root)) {
                continue;
            }
            $groups = [];

            foreach ($children->get((int) $root->id, collect()) as $child) {
                if (! $visible($child)) {
                    continue;
                }
                // A leaf sitting directly under the section (Masters →
                // Warehouses) gets an unnamed group so the UI does not repeat
                // the section title; anything deeper folds into its sub-group,
                // however many levels below it lives.
                $isLeaf = $child->route !== null;
                $label = $isLeaf ? '' : $child->label;
                $leaves = $isLeaf ? collect([$child]) : self::leaves($children, $child, $visible);
                $modules = self::collectModules($leaves, $byRoute, $placed);

                if (empty($modules)) {
                    continue;
                }
                $groups[$label] ??= ['label' => $label, 'icon' => $isLeaf ? null : $child->icon, 'modules' => []];
                $groups[$label]['modules'] = array_merge($groups[$label]['modules'], $modules);
            }

            // Leaves of a section that has no children at all (Dashboard).
            if ($root->route !== null) {
                $direct = self::collectModules(collect([$root]), $byRoute, $placed);
                if ($direct) {
                    $groups[''] ??= ['label' => '', 'icon' => null, 'modules' => []];
                    $groups['']['modules'] = array_merge($groups['']['modules'], $direct);
                }
            }

            if ($groups) {
                $sections[] = ['label' => $root->label, 'icon' => $root->icon, 'groups' => array_values($groups)];
            }
        }

        // Modules with no nav screen at all (or whose leaf was renamed away) —
        // never silently dropped, so a new generator module always shows up.
        $left = array_values(array_filter(
            array_diff(array_keys(DemoDataGenerator::MODULES), $placed),
            fn (string $key) => ! in_array(self::ROUTES[$key] ?? '', $known, true),
        ));
        if ($left) {
            $sections[] = self::flatSection('Other', 'pi pi-ellipsis-h', $left);
        }

        return $sections;
    }

    /**
     * The rows to project onto: the tenant's own catalogue when it has one,
     * otherwise the shared global rows (same fallback MenuController uses).
     *
     * @return Collection<int, MenuItem>
     */
    private static function catalogue(?int $tenantId): Collection
    {
        $base = fn (?int $tid) => MenuItem::query()
            ->where('is_active', true)
            ->when($tid === null, fn ($q) => $q->whereNull('tenant_id'), fn ($q) => $q->where('tenant_id', $tid))
            ->orderBy('sort_order')
            ->get(['id', 'parent_id', 'label', 'icon', 'route', 'feature', 'module']);

        if ($tenantId === null) {
            return $base(null);
        }

        $owned = $base($tenantId);

        return $owned->isNotEmpty() ? $owned : $base(null);
    }

    /**
     * Same gates MenuController applies, minus the permission check (this is an
     * operator screen, not the tenant's own nav): the plan must sell the node's
     * feature, and the tenant must have its module switched on. A null module
     * set means the tenant never chose one → gating is off.
     *
     * @param  list<string>|null  $enabled
     * @param  array<string, mixed>  $features
     */
    private static function visible(MenuItem $item, ?array $enabled, array $features): bool
    {
        if ($item->feature && empty($features[$item->feature])) {
            return false;
        }

        return ! ($item->module && $enabled !== null && ! in_array($item->module, $enabled, true));
    }

    /**
     * The tenant's plan feature map; empty when no subscription is on record.
     *
     * @return array<string, mixed>
     */
    private static function planFeatures(int $tenantId): array
    {
        $plan = Subscription::with('plan')->where('tenant_id', $tenantId)->latest('id')->first()?->plan;

        return $plan?->feature_limits ?? [];
    }

    /**
     * route => demo module keys, in MODULES order so chips read the same way
     * they do in the generator.
     *
     * @return array<string, list<string>>
     */
    private static function modulesByRoute(): array
    {
        $byRoute = [];
        foreach (array_keys(DemoDataGenerator::MODULES) as $key) {
            if (isset(self::ROUTES[$key])) {
                $byRoute[self::ROUTES[$key]][] = $key;
            }
        }

        return $byRoute;
    }

    /**
     * Every visible leaf (route-bearing row) in $node's subtree, in menu order.
     * A gated node takes its whole subtree with it, like the nav does.
     *
     * @param  Collection<int, Collection<int, MenuItem>>  $children
     * @param  callable(MenuItem):bool  $visible
     * @return Collection<int, MenuItem>
     */
    private static function leaves(Collection $children, MenuItem $node, callable $visible): Collection
    {
        $out = collect();
        foreach ($children->get((int) $node->id, collect()) as $child) {
            if (! $visible($child)) {
                continue;
            }
            if ($child->route !== null) {
                $out->push($child);
            }
            $out = $out->concat(self::leaves($children, $child, $visible));
        }

        return $out;
    }

    /**
     * Turn menu leaves into picker entries, skipping modules already placed
     * (a route can appear twice in the menu, e.g. the Broker Ledger).
     *
     * @param  Collection<int, MenuItem>  $leaves
     * @param  array<string, list<string>>  $byRoute
     * @param  list<string>  $placed
     * @return list<array{key:string,label:string,icon:?string}>
     */
    private static function collectModules(Collection $leaves, array $byRoute, array &$placed): array
    {
        $out = [];
        foreach ($leaves as $leaf) {
            foreach ($byRoute[$leaf->route] ?? [] as $key) {
                if (in_array($key, $placed, true)) {
                    continue;
                }
                $placed[] = $key;
                $out[] = ['key' => $key, 'label' => DemoDataGenerator::MODULES[$key], 'icon' => $leaf->icon];
            }
        }

        return $out;
    }

    /**
     * @param  list<string>  $keys
     * @return array{label:string,icon:?string,groups:list<array{label:string,icon:?string,modules:list<array{key:string,label:string,icon:?string}>}>}
     */
    private static function flatSection(string $label, string $icon, array $keys): array
    {
        return [
            'label' => $label,
            'icon' => $icon,
            'groups' => [[
                'label' => '',
                'icon' => null,
                'modules' => array_map(
                    fn (string $key) => ['key' => $key, 'label' => DemoDataGenerator::MODULES[$key], 'icon' => null],
                    $keys,
                ),
            ]],
        ];
    }
}
