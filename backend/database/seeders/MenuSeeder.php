<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\MenuItem;
use Illuminate\Database\Seeder;

/**
 * Seeds the global navigation catalogue (project-stack.md §3) with tenant_id
 * NULL, so every tenant shares it. Idempotent: matches on (parent, label) and
 * updates in place, so re-running keeps sort order / icons / gates current and
 * preserves IDs (favorites survive). Any global row NOT re-seeded this run —
 * e.g. a group moved to a new parent or renamed during a restructure — is swept
 * afterwards so the tree never grows stale duplicates. Favorites of a swept row
 * cascade away; recents are route strings and are unaffected.
 *
 * The tree is grouped into a handful of workspace-level sections (Masters,
 * Sales, Finance …), each holding functional sub-groups that in turn hold the
 * clickable leaves — up to four levels deep (e.g. Masters → Products → Barcode
 * → Barcode Scan).
 *
 * `feature` keys gate a node behind a subscription plan feature (see PlanSeeder
 * feature_limits): Barcode → barcode, Reports → reports, E-Commerce →
 * integrations. `module` keys gate a node behind a tenant's enabled module set
 * (onboarding wizard §3). Gating a group hides its whole subtree, so a module/
 * feature only needs to sit on the shallowest node that owns it.
 */
class MenuSeeder extends Seeder
{
    public function run(): void
    {
        $touched = [];
        foreach ($this->tree() as $order => $entry) {
            $this->seedEntry($entry, null, $order, $touched);
        }

        // Drop global rows that no longer appear in the tree (moved, renamed or
        // removed by a restructure). nullOnDelete keeps the self-FK safe; kept
        // rows only ever reference other kept rows, so nothing dangles.
        MenuItem::whereNull('tenant_id')
            ->whereNotIn('id', $touched)
            ->delete();
    }

    /**
     * @param  array<int, int>  $touched  Collects every seeded row id so run()
     *                                     can sweep the rows it did not touch.
     */
    private function seedEntry(array $entry, ?int $parentId, int $order, array &$touched): void
    {
        $item = MenuItem::updateOrCreate(
            ['tenant_id' => null, 'parent_id' => $parentId, 'label' => $entry['label']],
            [
                'icon' => $entry['icon'] ?? null,
                'route' => $entry['to'] ?? null,
                'permission' => $entry['permission'] ?? null,
                'feature' => $entry['feature'] ?? null,
                'module' => $entry['module'] ?? null,
                'sort_order' => $order,
                'is_active' => true,
            ],
        );
        $touched[] = $item->id;

        foreach ($entry['children'] ?? [] as $childOrder => $child) {
            $this->seedEntry($child, $item->id, $childOrder, $touched);
        }
    }

    /**
     * The canonical menu. Kept in one place so "add a module → add a menu row"
     * stays a data change. Leaves carry the same route/permission they always
     * had; only the grouping around them changed.
     */
    private function tree(): array
    {
        return [
            ['label' => 'Dashboard', 'icon' => 'pi pi-home', 'to' => '/'],

            ['label' => 'Masters', 'icon' => 'pi pi-database', 'children' => [
                ['label' => 'Products', 'icon' => 'pi pi-box', 'children' => [
                    ['label' => 'Categories', 'icon' => 'pi pi-sitemap', 'to' => '/categories', 'permission' => 'categories.view'],
                    ['label' => 'Brands', 'icon' => 'pi pi-tag', 'to' => '/brands', 'permission' => 'brands.view'],
                    ['label' => 'Units', 'icon' => 'pi pi-percentage', 'to' => '/units', 'permission' => 'units.view'],
                    ['label' => 'Products', 'icon' => 'pi pi-box', 'to' => '/products', 'permission' => 'products.view'],
                    ['label' => 'Price Lists', 'icon' => 'pi pi-indian-rupee', 'to' => '/price-lists', 'permission' => 'price-lists.view'],
                    ['label' => 'Barcode', 'icon' => 'pi pi-qrcode', 'feature' => 'barcode', 'children' => [
                        ['label' => 'Barcode Scan', 'icon' => 'pi pi-camera', 'to' => '/barcode-scan', 'permission' => 'barcodes.view'],
                        ['label' => 'Label Printing', 'icon' => 'pi pi-print', 'to' => '/label-printing', 'permission' => 'barcodes.view'],
                    ]],
                ]],
                ['label' => 'Business Partners', 'icon' => 'pi pi-users', 'children' => [
                    ['label' => 'Customers', 'icon' => 'pi pi-users', 'to' => '/customers', 'permission' => 'customers.view'],
                    ['label' => 'Customer Categories', 'icon' => 'pi pi-tags', 'to' => '/customer-categories', 'permission' => 'customer-categories.view'],
                    ['label' => 'Suppliers', 'icon' => 'pi pi-truck', 'to' => '/suppliers', 'permission' => 'suppliers.view'],
                    ['label' => 'Brokers', 'icon' => 'pi pi-user-plus', 'to' => '/brokers', 'permission' => 'brokers.view', 'module' => 'broker'],
                    ['label' => 'Broker Ledger', 'icon' => 'pi pi-book', 'to' => '/brokers/ledger', 'permission' => 'brokers.view', 'module' => 'broker'],
                ]],
                ['label' => 'Warehouses', 'icon' => 'pi pi-building', 'to' => '/warehouses', 'permission' => 'warehouses.view'],
            ]],

            ['label' => 'Sales', 'icon' => 'pi pi-dollar', 'children' => [
                ['label' => 'Sales', 'icon' => 'pi pi-shopping-cart', 'module' => 'sales', 'children' => [
                    ['label' => 'Quotations', 'icon' => 'pi pi-file', 'to' => '/sales-quotations', 'permission' => 'sales.view'],
                    ['label' => 'Sales Orders', 'icon' => 'pi pi-shopping-cart', 'to' => '/sales-orders', 'permission' => 'sales.view'],
                    ['label' => 'POS', 'icon' => 'pi pi-calculator', 'to' => '/pos', 'permission' => 'sales.create', 'module' => 'pos'],
                    ['label' => 'Sales Returns', 'icon' => 'pi pi-replay', 'to' => '/sales-returns', 'permission' => 'sales-returns.view'],
                    ['label' => 'B2B Orders', 'icon' => 'pi pi-check-square', 'to' => '/b2b-orders', 'permission' => 'b2b-orders.view'],
                ]],
                ['label' => 'CRM', 'icon' => 'pi pi-heart', 'module' => 'crm', 'children' => [
                    ['label' => 'Leads', 'icon' => 'pi pi-filter', 'to' => '/leads', 'permission' => 'leads.view'],
                    ['label' => 'Follow-ups', 'icon' => 'pi pi-bell', 'to' => '/follow-ups', 'permission' => 'follow-ups.view'],
                    ['label' => 'Referrals', 'icon' => 'pi pi-share-alt', 'to' => '/referrals', 'permission' => 'referrals.view'],
                    ['label' => 'Occasions', 'icon' => 'pi pi-calendar-plus', 'to' => '/crm/occasions', 'permission' => 'customers.view'],
                    ['label' => 'Coupons', 'icon' => 'pi pi-ticket', 'to' => '/coupons', 'permission' => 'coupons.view'],
                    ['label' => 'WhatsApp', 'icon' => 'pi pi-whatsapp', 'to' => '/whatsapp', 'permission' => 'whatsapp.view'],
                    ['label' => 'SMS', 'icon' => 'pi pi-comment', 'to' => '/sms', 'permission' => 'sms.view'],
                    ['label' => 'Marketing Campaigns', 'icon' => 'pi pi-megaphone', 'to' => '/marketing-campaigns', 'permission' => 'marketing-campaigns.view'],
                    ['label' => 'Email Log', 'icon' => 'pi pi-envelope', 'to' => '/email-logs', 'permission' => 'email.view'],
                ]],
                ['label' => 'Field Sales', 'icon' => 'pi pi-map', 'module' => 'sales', 'children' => [
                    ['label' => 'Salesmen', 'icon' => 'pi pi-user', 'to' => '/salesmen', 'permission' => 'salesmen.view'],
                    ['label' => 'Beat Plans & Routes', 'icon' => 'pi pi-directions', 'to' => '/beats', 'permission' => 'beats.view'],
                    ['label' => 'Daily Visit Plans', 'icon' => 'pi pi-calendar-clock', 'to' => '/salesman-visits', 'permission' => 'salesman-visits.view'],
                    ['label' => 'Live Tracking', 'icon' => 'pi pi-map-marker', 'to' => '/salesman-tracking', 'permission' => 'salesman-locations.view'],
                    ['label' => 'Collections', 'icon' => 'pi pi-wallet', 'to' => '/salesman-collections', 'permission' => 'salesman-collections.view'],
                    ['label' => 'Daily Expenses', 'icon' => 'pi pi-receipt', 'to' => '/salesman-expenses', 'permission' => 'salesman-expenses.view'],
                    ['label' => 'Targets & Incentives', 'icon' => 'pi pi-flag', 'to' => '/salesman-targets', 'permission' => 'salesman-targets.view'],
                    ['label' => 'Reports', 'icon' => 'pi pi-chart-bar', 'to' => '/salesman-reports', 'permission' => 'salesmen.view'],
                ]],
                ['label' => 'E-Commerce', 'icon' => 'pi pi-globe', 'feature' => 'integrations', 'children' => [
                    ['label' => 'Integrations', 'icon' => 'pi pi-link', 'to' => '/ecommerce-integrations', 'permission' => 'ecommerce-integrations.view'],
                    ['label' => 'Marketplace Orders', 'icon' => 'pi pi-inbox', 'to' => '/channel-orders', 'permission' => 'channel-orders.view'],
                    ['label' => 'Channel Listings', 'icon' => 'pi pi-tags', 'to' => '/channel-listings', 'permission' => 'channel-listings.view'],
                    ['label' => 'Sync History', 'icon' => 'pi pi-history', 'to' => '/channel-sync-logs', 'permission' => 'channel-sync-logs.view'],
                ]],
            ]],

            ['label' => 'Purchase', 'icon' => 'pi pi-shopping-bag', 'module' => 'purchase', 'children' => [
                ['label' => 'Purchase Indents', 'icon' => 'pi pi-clipboard', 'to' => '/purchase-indents', 'permission' => 'purchase-indents.view'],
                ['label' => 'RFQ / Quotations', 'icon' => 'pi pi-send', 'to' => '/rfqs', 'permission' => 'rfqs.view'],
                ['label' => 'Purchase Orders', 'icon' => 'pi pi-file-edit', 'to' => '/purchase-orders', 'permission' => 'purchases.view'],
                ['label' => 'Vendor Invoices', 'icon' => 'pi pi-receipt', 'to' => '/purchase-invoices', 'permission' => 'purchase-invoices.view'],
                ['label' => 'Vendor Ratings', 'icon' => 'pi pi-star', 'to' => '/vendor-ratings', 'permission' => 'vendor-ratings.view'],
            ]],

            ['label' => 'Inventory', 'icon' => 'pi pi-warehouse', 'module' => 'inventory', 'children' => [
                ['label' => 'Stock', 'icon' => 'pi pi-database', 'children' => [
                    ['label' => 'Current Stock', 'icon' => 'pi pi-database', 'to' => '/stock', 'permission' => 'stock.view'],
                    ['label' => 'Stock Ledger', 'icon' => 'pi pi-history', 'to' => '/stock-ledger', 'permission' => 'stock-ledger.view'],
                    ['label' => 'Stock Adjustments', 'icon' => 'pi pi-sliders-h', 'to' => '/stock-adjustments', 'permission' => 'stock-adjustments.view'],
                    ['label' => 'Stock Transfers', 'icon' => 'pi pi-arrows-h', 'to' => '/stock-transfers', 'permission' => 'stock-transfers.view'],
                    ['label' => 'Stock Returns', 'icon' => 'pi pi-undo', 'to' => '/stock-returns', 'permission' => 'stock-returns.view'],
                    ['label' => 'Gate Entries', 'icon' => 'pi pi-sign-in', 'to' => '/gate-entries', 'permission' => 'gate-entries.view'],
                    ['label' => 'Cycle Counts', 'icon' => 'pi pi-check-square', 'to' => '/cycle-counts', 'permission' => 'cycle-counts.view'],
                    ['label' => 'Inventory Alerts', 'icon' => 'pi pi-bell', 'to' => '/inventory-alerts', 'permission' => 'advanced-inventory.view'],
                    ['label' => 'Dead Stock', 'icon' => 'pi pi-chart-line', 'to' => '/dead-stock', 'permission' => 'advanced-inventory.view'],
                    ['label' => 'Reorder', 'icon' => 'pi pi-refresh', 'to' => '/reorder', 'permission' => 'advanced-inventory.view'],
                ]],
                ['label' => 'Tracking', 'icon' => 'pi pi-qrcode', 'children' => [
                    ['label' => 'Batches / Lots', 'icon' => 'pi pi-box', 'to' => '/batches', 'permission' => 'advanced-inventory.view'],
                    ['label' => 'Serial Numbers', 'icon' => 'pi pi-qrcode', 'to' => '/serial-numbers', 'permission' => 'advanced-inventory.view'],
                ]],
            ]],

            ['label' => 'Manufacturing', 'icon' => 'pi pi-cog', 'module' => 'manufacturing', 'children' => [
                ['label' => 'Production', 'icon' => 'pi pi-cog', 'children' => [
                    ['label' => 'Bills of Materials', 'icon' => 'pi pi-sitemap', 'to' => '/boms', 'permission' => 'boms.view'],
                    ['label' => 'Manufacturing Orders', 'icon' => 'pi pi-wrench', 'to' => '/manufacturing-orders', 'permission' => 'manufacturing-orders.view'],
                    ['label' => 'Colour Printing', 'icon' => 'pi pi-palette', 'to' => '/colour-printings', 'permission' => 'colour-printing.view'],
                    ['label' => 'Job Work', 'icon' => 'pi pi-briefcase', 'to' => '/job-works', 'permission' => 'job-works.view'],
                ]],
                ['label' => 'Textile', 'icon' => 'pi pi-th-large', 'children' => [
                    ['label' => 'Fabric Rolls', 'icon' => 'pi pi-clone', 'to' => '/fabric-rolls', 'permission' => 'textile.view'],
                    ['label' => 'Fabric Inspection', 'icon' => 'pi pi-search', 'to' => '/fabric-inspections', 'permission' => 'textile.view'],
                    ['label' => 'Shrinkage Records', 'icon' => 'pi pi-arrows-h', 'to' => '/shrinkage-records', 'permission' => 'textile.view'],
                    ['label' => 'Fabric Cutting', 'icon' => 'pi pi-scissors', 'to' => '/fabric-cuttings', 'permission' => 'textile.view'],
                ]],
                ['label' => 'Tailoring', 'icon' => 'pi pi-th-large', 'children' => [
                    ['label' => 'Tailor Master', 'icon' => 'pi pi-user', 'to' => '/tailors', 'permission' => 'tailors.view'],
                    ['label' => 'Contractors', 'icon' => 'pi pi-users', 'to' => '/contractors', 'permission' => 'contractors.view'],
                    ['label' => 'Rate Card', 'icon' => 'pi pi-list', 'to' => '/rate-cards', 'permission' => 'rate-cards.view'],
                    ['label' => 'Piece Rate', 'icon' => 'pi pi-sliders-h', 'to' => '/piece-rates', 'permission' => 'piece-rates.view'],
                    ['label' => 'Pending Work', 'icon' => 'pi pi-clock', 'to' => '/tailor-works/pending', 'permission' => 'tailor-works.view'],
                    ['label' => 'Completed Work', 'icon' => 'pi pi-check-circle', 'to' => '/tailor-works/completed', 'permission' => 'tailor-works.view'],
                    ['label' => 'Alterations', 'icon' => 'pi pi-pencil', 'to' => '/tailor-alterations', 'permission' => 'tailor-alterations.view'],
                    ['label' => 'Bonus', 'icon' => 'pi pi-star', 'to' => '/tailor-bonuses', 'permission' => 'tailor-bonuses.view'],
                    ['label' => 'Performance', 'icon' => 'pi pi-chart-line', 'to' => '/tailor-performance', 'permission' => 'reports.view'],
                    ['label' => 'Payment', 'icon' => 'pi pi-wallet', 'to' => '/tailor-payments', 'permission' => 'tailor-payments.view'],
                    ['label' => 'Tailor Ledger', 'icon' => 'pi pi-book', 'to' => '/tailors/ledger', 'permission' => 'tailors.view'],
                ]],
                ['label' => 'Embroidery', 'icon' => 'pi pi-star', 'children' => [
                    ['label' => 'Vendor Master', 'icon' => 'pi pi-user', 'to' => '/embroidery-vendors', 'permission' => 'embroidery-vendors.view'],
                    ['label' => 'Pending Dispatch', 'icon' => 'pi pi-clock', 'to' => '/embroidery-works/pending', 'permission' => 'embroidery-works.view'],
                    ['label' => 'Received', 'icon' => 'pi pi-check-circle', 'to' => '/embroidery-works/received', 'permission' => 'embroidery-works.view'],
                    ['label' => 'Payment', 'icon' => 'pi pi-wallet', 'to' => '/embroidery-payments', 'permission' => 'embroidery-payments.view'],
                    ['label' => 'Vendor Ledger', 'icon' => 'pi pi-book', 'to' => '/embroidery-vendors/ledger', 'permission' => 'embroidery-vendors.view'],
                ]],
            ]],

            ['label' => 'Finance', 'icon' => 'pi pi-wallet', 'module' => 'accounts', 'children' => [
                ['label' => 'Accounts', 'icon' => 'pi pi-wallet', 'children' => [
                    ['label' => 'Bank & Cash Accounts', 'icon' => 'pi pi-building', 'to' => '/bank-accounts', 'permission' => 'bank-accounts.view'],
                    ['label' => 'Vouchers', 'icon' => 'pi pi-file-edit', 'to' => '/vouchers', 'permission' => 'vouchers.view'],
                    ['label' => 'Cash Book', 'icon' => 'pi pi-money-bill', 'to' => '/accounts/cash-book', 'permission' => 'accounts.view'],
                    ['label' => 'Bank Book', 'icon' => 'pi pi-credit-card', 'to' => '/accounts/bank-book', 'permission' => 'accounts.view'],
                    ['label' => 'Journal', 'icon' => 'pi pi-book', 'to' => '/accounts/journal', 'permission' => 'accounts.view'],
                    ['label' => 'Day Book', 'icon' => 'pi pi-calendar', 'to' => '/accounts/day-book', 'permission' => 'accounts.view'],
                    ['label' => 'Petty Cash', 'icon' => 'pi pi-wallet', 'to' => '/accounts/petty-cash', 'permission' => 'accounts.view'],
                ]],
                ['label' => 'Ledgers', 'icon' => 'pi pi-book', 'children' => [
                    ['label' => 'Customer Ledger', 'icon' => 'pi pi-user', 'to' => '/accounts/customer-ledger', 'permission' => 'customers.view'],
                    ['label' => 'Supplier Ledger', 'icon' => 'pi pi-truck', 'to' => '/accounts/supplier-ledger', 'permission' => 'suppliers.view'],
                    ['label' => 'Broker Ledger', 'icon' => 'pi pi-user-plus', 'to' => '/brokers/ledger', 'permission' => 'brokers.view'],
                    ['label' => 'Expense Ledger', 'icon' => 'pi pi-receipt', 'to' => '/accounts/expense-ledger', 'permission' => 'expenses.view'],
                ]],
                ['label' => 'Expenses', 'icon' => 'pi pi-receipt', 'children' => [
                    ['label' => 'Expenses', 'icon' => 'pi pi-receipt', 'to' => '/expenses', 'permission' => 'expenses.view'],
                    ['label' => 'Expense Categories', 'icon' => 'pi pi-tags', 'to' => '/expense-categories', 'permission' => 'expense-categories.view'],
                    ['label' => 'Employee Claims', 'icon' => 'pi pi-user', 'to' => '/employee-claims', 'permission' => 'employee-claims.view'],
                    ['label' => 'Vehicle Expenses', 'icon' => 'pi pi-car', 'to' => '/vehicle-expenses', 'permission' => 'vehicle-expenses.view'],
                    ['label' => 'Fuel Expenses', 'icon' => 'pi pi-bolt', 'to' => '/fuel-expenses', 'permission' => 'fuel-expenses.view'],
                    ['label' => 'Electricity Bills', 'icon' => 'pi pi-flash', 'to' => '/electricity-bills', 'permission' => 'electricity-bills.view'],
                    ['label' => 'Factory Maintenance', 'icon' => 'pi pi-wrench', 'to' => '/factory-maintenances', 'permission' => 'factory-maintenances.view'],
                    ['label' => 'Asset Maintenance', 'icon' => 'pi pi-cog', 'to' => '/asset-maintenances', 'permission' => 'asset-maintenances.view'],
                    ['label' => 'Assets', 'icon' => 'pi pi-box', 'to' => '/assets', 'permission' => 'assets.view'],
                ]],
                ['label' => 'Financial Reports', 'icon' => 'pi pi-chart-line', 'children' => [
                    ['label' => 'Trial Balance', 'icon' => 'pi pi-list', 'to' => '/accounts/trial-balance', 'permission' => 'accounts.view'],
                    ['label' => 'Profit & Loss', 'icon' => 'pi pi-chart-line', 'to' => '/accounts/profit-loss', 'permission' => 'accounts.view'],
                    ['label' => 'Balance Sheet', 'icon' => 'pi pi-book', 'to' => '/accounts/balance-sheet', 'permission' => 'accounts.view'],
                ]],
                ['label' => 'Payments', 'icon' => 'pi pi-credit-card', 'children' => [
                    ['label' => 'Payment Links', 'icon' => 'pi pi-link', 'to' => '/payment-transactions', 'permission' => 'payments.view'],
                    ['label' => 'Payment Gateways', 'icon' => 'pi pi-credit-card', 'to' => '/payment-gateways', 'permission' => 'payment-gateways.view'],
                ]],
                ['label' => 'GST & Compliance', 'icon' => 'pi pi-verified', 'children' => [
                    ['label' => 'GST Registrations', 'icon' => 'pi pi-id-card', 'to' => '/gst-registrations', 'permission' => 'gst-registrations.view'],
                    ['label' => 'HSN/SAC Master', 'icon' => 'pi pi-list', 'to' => '/hsn-codes', 'permission' => 'hsn-codes.view'],
                    ['label' => 'GST Tools', 'icon' => 'pi pi-search', 'to' => '/gst-tools', 'permission' => 'hsn-codes.view'],
                    ['label' => 'TDS/TCS Rates', 'icon' => 'pi pi-percentage', 'to' => '/tds-tcs-rates', 'permission' => 'tds-tcs-rates.view'],
                    ['label' => 'E-Invoices', 'icon' => 'pi pi-qrcode', 'to' => '/e-invoices', 'permission' => 'e-invoices.view'],
                    ['label' => 'E-Way Bills', 'icon' => 'pi pi-truck', 'to' => '/e-way-bills', 'permission' => 'e-way-bills.view'],
                ]],
            ]],

            ['label' => 'HR & Payroll', 'icon' => 'pi pi-id-card', 'module' => 'hrms', 'children' => [
                ['label' => 'Human Resources', 'icon' => 'pi pi-id-card', 'children' => [
                    ['label' => 'Departments', 'icon' => 'pi pi-sitemap', 'to' => '/departments', 'permission' => 'departments.view'],
                    ['label' => 'Employees', 'icon' => 'pi pi-users', 'to' => '/employees', 'permission' => 'employees.view'],
                    ['label' => 'Attendance', 'icon' => 'pi pi-calendar', 'to' => '/attendance', 'permission' => 'attendance.view'],
                    ['label' => 'Biometric Devices', 'icon' => 'pi pi-desktop', 'to' => '/biometric-devices', 'permission' => 'biometric-devices.view'],
                    ['label' => 'Biometric Punches', 'icon' => 'pi pi-fingerprint', 'to' => '/biometric-punches', 'permission' => 'biometric-punches.view'],
                    ['label' => 'Leave Types', 'icon' => 'pi pi-tags', 'to' => '/leave-types', 'permission' => 'leave-types.view'],
                    ['label' => 'Leave Applications', 'icon' => 'pi pi-calendar-times', 'to' => '/leave-applications', 'permission' => 'leave-applications.view'],
                    ['label' => 'Overtime', 'icon' => 'pi pi-clock', 'to' => '/overtime', 'permission' => 'overtime.view'],
                ]],
                ['label' => 'Payroll', 'icon' => 'pi pi-wallet', 'children' => [
                    ['label' => 'Salary Structures', 'icon' => 'pi pi-sliders-h', 'to' => '/salary-structures', 'permission' => 'salary-structures.view'],
                    ['label' => 'Payroll Processing', 'icon' => 'pi pi-cog', 'to' => '/payroll', 'permission' => 'payroll.view'],
                    ['label' => 'Salary Register', 'icon' => 'pi pi-wallet', 'to' => '/salaries', 'permission' => 'salaries.view'],
                    ['label' => 'Bonuses', 'icon' => 'pi pi-star', 'to' => '/employee-bonuses', 'permission' => 'employee-bonuses.view'],
                    ['label' => 'Salary Advances', 'icon' => 'pi pi-arrow-up-right', 'to' => '/salary-advances', 'permission' => 'salary-advances.view'],
                    ['label' => 'Loans', 'icon' => 'pi pi-credit-card', 'to' => '/employee-loans', 'permission' => 'employee-loans.view'],
                ]],
            ]],

            ['label' => 'Logistics', 'icon' => 'pi pi-truck', 'children' => [
                ['label' => 'Vehicles', 'icon' => 'pi pi-car', 'to' => '/vehicles', 'permission' => 'vehicles.view'],
                ['label' => 'Drivers', 'icon' => 'pi pi-id-card', 'to' => '/drivers', 'permission' => 'drivers.view'],
                ['label' => 'Delivery Routes', 'icon' => 'pi pi-directions', 'to' => '/delivery-routes', 'permission' => 'delivery-routes.view'],
                ['label' => 'Dispatch Planning', 'icon' => 'pi pi-send', 'to' => '/dispatch-plans', 'permission' => 'dispatch-plans.view'],
                ['label' => 'Proof of Delivery', 'icon' => 'pi pi-check-circle', 'to' => '/proof-of-delivery', 'permission' => 'dispatch-pods.view'],
                ['label' => 'Transport Charges', 'icon' => 'pi pi-indian-rupee', 'to' => '/transport-charges', 'permission' => 'transport-charges.view'],
            ]],

            ['label' => 'Reports', 'icon' => 'pi pi-chart-bar', 'feature' => 'reports', 'children' => [
                ['label' => 'Sales Reports', 'icon' => 'pi pi-shopping-cart', 'children' => [
                    ['label' => 'Sales', 'icon' => 'pi pi-shopping-cart', 'to' => '/reports/sales', 'permission' => 'reports.view'],
                    ['label' => 'Broker Commissions', 'icon' => 'pi pi-percentage', 'to' => '/reports/broker-commissions', 'permission' => 'reports.view'],
                    ['label' => 'Broker-wise Sales', 'icon' => 'pi pi-users', 'to' => '/reports/broker-wise-sales', 'permission' => 'reports.view'],
                    ['label' => 'Profitability', 'icon' => 'pi pi-chart-line', 'to' => '/reports/profitability', 'permission' => 'reports.view'],
                ]],
                ['label' => 'Purchase Reports', 'icon' => 'pi pi-shopping-bag', 'children' => [
                    ['label' => 'Purchases', 'icon' => 'pi pi-file-edit', 'to' => '/reports/purchases', 'permission' => 'reports.view'],
                    ['label' => 'Vendor Performance', 'icon' => 'pi pi-star', 'to' => '/reports/vendor-performance', 'permission' => 'reports.view'],
                    ['label' => 'Payables / Pending', 'icon' => 'pi pi-wallet', 'to' => '/reports/payables', 'permission' => 'reports.view'],
                ]],
                ['label' => 'Inventory Reports', 'icon' => 'pi pi-warehouse', 'children' => [
                    ['label' => 'Stock Overview', 'icon' => 'pi pi-database', 'to' => '/reports/stock-overview', 'permission' => 'reports.view'],
                    ['label' => 'Stock Movement', 'icon' => 'pi pi-history', 'to' => '/reports/stock-movement', 'permission' => 'reports.view'],
                    ['label' => 'Fast / Slow Moving', 'icon' => 'pi pi-gauge', 'to' => '/reports/fast-slow', 'permission' => 'reports.view'],
                    ['label' => 'Inventory Valuation', 'icon' => 'pi pi-wallet', 'to' => '/reports/valuation', 'permission' => 'reports.view'],
                    ['label' => 'Stock Ageing', 'icon' => 'pi pi-clock', 'to' => '/reports/stock-ageing', 'permission' => 'reports.view'],
                    ['label' => 'Slow Moving Stock', 'icon' => 'pi pi-arrow-down', 'to' => '/reports/slow-moving', 'permission' => 'reports.view'],
                    ['label' => 'Fast Moving Stock', 'icon' => 'pi pi-arrow-up', 'to' => '/reports/fast-moving', 'permission' => 'reports.view'],
                ]],
                ['label' => 'Finance Reports', 'icon' => 'pi pi-wallet', 'children' => [
                    ['label' => 'Expenses', 'icon' => 'pi pi-money-bill', 'to' => '/reports/expenses', 'permission' => 'reports.view'],
                    ['label' => 'Customer Ledger', 'icon' => 'pi pi-book', 'to' => '/reports/customer-ledger', 'permission' => 'reports.view'],
                    ['label' => 'Supplier Ledger', 'icon' => 'pi pi-book', 'to' => '/reports/supplier-ledger', 'permission' => 'reports.view'],
                ]],
                ['label' => 'Manufacturing Reports', 'icon' => 'pi pi-cog', 'children' => [
                    ['label' => 'Manufacturing', 'icon' => 'pi pi-cog', 'to' => '/reports/manufacturing', 'permission' => 'reports.view'],
                    ['label' => 'Production Cost', 'icon' => 'pi pi-calculator', 'to' => '/reports/production-cost', 'permission' => 'reports.view'],
                    ['label' => 'Tailor', 'icon' => 'pi pi-user', 'to' => '/reports/tailor', 'permission' => 'reports.view'],
                    ['label' => 'Embroidery', 'icon' => 'pi pi-palette', 'to' => '/reports/embroidery', 'permission' => 'reports.view'],
                    ['label' => 'Printing', 'icon' => 'pi pi-print', 'to' => '/reports/printing', 'permission' => 'reports.view'],
                ]],
                ['label' => 'HR Reports', 'icon' => 'pi pi-id-card', 'children' => [
                    // No HR report screens exist yet; the group stays hidden (a group
                    // with no visible children is dropped) until routes are added.
                ]],
                ['label' => 'GST Reports', 'icon' => 'pi pi-percentage', 'children' => [
                    ['label' => 'GST / Tax', 'icon' => 'pi pi-percentage', 'to' => '/reports/gst', 'permission' => 'reports.view'],
                    ['label' => 'GST Summary (GSTR-3B)', 'icon' => 'pi pi-percentage', 'to' => '/reports/gst-summary', 'permission' => 'reports.view'],
                    ['label' => 'GSTR-1 (Outward)', 'icon' => 'pi pi-file-export', 'to' => '/reports/gstr1', 'permission' => 'reports.view'],
                    ['label' => 'GSTR-2 (Inward)', 'icon' => 'pi pi-file-import', 'to' => '/reports/gstr2', 'permission' => 'reports.view'],
                    ['label' => 'TDS Register', 'icon' => 'pi pi-minus-circle', 'to' => '/reports/tds-register', 'permission' => 'reports.view'],
                    ['label' => 'TCS Register', 'icon' => 'pi pi-plus-circle', 'to' => '/reports/tcs-register', 'permission' => 'reports.view'],
                ]],
                ['label' => 'Custom Reports', 'icon' => 'pi pi-sliders-h', 'children' => [
                    // Placeholder for user-defined reports; hidden until leaves exist.
                ]],
            ]],

            ['label' => 'Administration', 'icon' => 'pi pi-shield', 'children' => [
                ['label' => 'Users', 'icon' => 'pi pi-users', 'children' => [
                    ['label' => 'Users', 'icon' => 'pi pi-user', 'to' => '/users', 'permission' => 'users.view'],
                    ['label' => 'Roles & Permissions', 'icon' => 'pi pi-shield', 'to' => '/roles', 'permission' => 'roles.view'],
                ]],
                ['label' => 'Organization', 'icon' => 'pi pi-sitemap', 'children' => [
                    ['label' => 'Companies', 'icon' => 'pi pi-sitemap', 'to' => '/companies', 'permission' => 'companies.view'],
                    ['label' => 'Branches', 'icon' => 'pi pi-map-marker', 'to' => '/branches', 'permission' => 'branches.view'],
                    ['label' => 'Billing & Plan', 'icon' => 'pi pi-credit-card', 'to' => '/billing'],
                    ['label' => 'Backup & Restore', 'icon' => 'pi pi-cloud-upload', 'to' => '/backups', 'permission' => 'backups.view'],
                    ['label' => 'Workflows', 'icon' => 'pi pi-share-alt', 'to' => '/workflows', 'permission' => 'workflows.view'],
                    ['label' => 'Settings', 'icon' => 'pi pi-cog', 'to' => '/settings', 'permission' => 'settings.view'],
                    ['label' => 'System Tools', 'icon' => 'pi pi-wrench', 'to' => '/system-tools', 'permission' => 'settings.view'],
                ]],
                ['label' => 'AI', 'icon' => 'pi pi-sparkles', 'children' => [
                    ['label' => 'AI Assistant', 'icon' => 'pi pi-comments', 'to' => '/ai-assistant', 'permission' => 'ai.view'],
                    ['label' => 'AI Insights', 'icon' => 'pi pi-chart-line', 'to' => '/ai-insights', 'permission' => 'ai.view'],
                ]],
                ['label' => 'Audit Logs', 'icon' => 'pi pi-history', 'to' => '/audit-logs', 'permission' => 'audit-logs.view'],
            ]],
        ];
    }
}
