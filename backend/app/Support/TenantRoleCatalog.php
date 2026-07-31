<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Level-2 (Tenant / Company) RBAC catalog — project-stack.md §"Enterprise RBAC".
 *
 * Single source of truth for the tenant `web` guard's permission vocabulary and
 * the departmental role hierarchy that ships with every tenant. Consumed by
 * Database\Seeders\RolePermissionSeeder (to create permissions + roles) and by
 * App\Http\Controllers\Api\RoleController (to group roles by department in the
 * admin UI).
 *
 * Roles are assigned per tenant (team-scoped) but defined once as global
 * templates at team_id = NULL. A handful of roles pre-date this catalog
 * (super-admin, admin, sales-manager, warehouse-manager, purchase-manager,
 * accountant, staff, auditor); the seeder keeps their hand-tuned permission
 * sets and this catalog only slots them into the org chart for the UI.
 */
class TenantRoleCatalog
{
    /** Modules that get a full view/create/edit/delete permission set. */
    public const MODULES = [
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
        // No 'backups': backup & restore is an operator concern and lives on the
        // platform Ops panel, not in a tenant workspace.
        'companies', 'branches', 'financial-years', 'gst-registrations',
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
        // Import & Export Logistics masters. Overseas suppliers/customers reuse
        // the 'suppliers' / 'customers' permissions — same records, filtered view.
        'countries', 'ports', 'shipping-lines', 'airlines',
        'freight-forwarders', 'cha-agents', 'transporters',
        // Import & Export Logistics shipments. Customs is split out from
        // 'shipments' because filing entries and paying duty is the CHA desk's
        // job, not the documentation desk's.
        'shipments', 'shipment-containers', 'shipment-documents',
        'shipment-customs', 'shipment-expenses', 'shipment-payments',
        'payments', 'payment-gateways',
        'bank-accounts', 'vouchers', 'tds-tcs-rates',
        'workflows',
        // Device Manager: configuring hardware is an admin/IT act, so it gets a
        // full permission set of its own rather than riding on 'settings'.
        'devices',
        // Service Management. Contracts are split from jobs because selling an
        // AMC is a sales act, while working a repair is a workshop one.
        'service-jobs', 'service-contracts',
        // Project Management. Timesheets are split out because logging your own
        // hours is something everyone on a project does, while editing the
        // project's budget is not.
        'projects', 'project-tasks', 'project-timesheets',
        // Pharmacy. The register itself is view-only (below): its entries are
        // written by dispensing, never by hand.
        'prescriptions',
        // Jewellery. Quoting the day's rate is separated from taking scrap in:
        // the rate governs every bill in the shop, buying old gold is one deal.
        'metal-rates', 'old-gold',
        // Optical. Testing eyes and dispensing spectacles are different jobs,
        // often different people, so they carry separate permissions.
        'eye-prescriptions', 'optical-orders',
        // Restaurant. The floor, the tickets and the kitchen display are three
        // different screens held by three different people.
        'restaurant-tables', 'restaurant-orders', 'kitchen-orders',
    ];

    /** Modules that are view-only (no create/edit/delete). */
    public const VIEW_ONLY_MODULES = [
        'audit-logs', 'notifications', 'accounts', 'channel-sync-logs', 'ai',
        // The device event log is telemetry: readable by anyone who can watch a
        // counter, cleared under 'devices.delete'.
        'device-events',
        // The Schedule H1/X register is a statutory record produced during a
        // drug-inspector visit. Nobody edits it — entries are written by the
        // act of dispensing, so the permission is read and nothing else.
        'drug-register',
    ];

    /** Modules that also support an "approve" permission. */
    public const APPROVABLE = [
        'purchase-indents', 'purchases', 'purchase-invoices',
        'sales', 'sales-returns', 'b2b-orders', 'channel-orders', 'stock-adjustments', 'stock-transfers', 'stock-returns', 'cycle-counts',
        'job-works', 'expenses',
        'employee-claims', 'vehicle-expenses', 'fuel-expenses',
        'electricity-bills', 'factory-maintenances', 'asset-maintenances',
        'leave-applications', 'overtime', 'employee-bonuses', 'salary-advances', 'employee-loans',
        // Only approved hours can be invoiced, so approval is a distinct act
        // from logging them.
        'project-timesheets',
    ];

    /**
     * Reusable module bundles, referenced by role specs. A spec entry is either
     * a group key below or a bare module name, suffixed with an access level.
     *
     * @return array<string, array<int, string>>
     */
    public static function groups(): array
    {
        return [
            'CATALOG' => ['products', 'categories', 'brands', 'units'],
            'CRM_CORE' => ['customers', 'customer-categories', 'leads', 'follow-ups', 'loyalty', 'coupons', 'referrals'],
            'PRICE' => ['price-lists'],
            'COMMS' => ['whatsapp', 'sms', 'email', 'marketing-campaigns'],
            'FIELD_SALES' => ['salesmen', 'salesman-targets', 'beats', 'salesman-visits', 'salesman-locations', 'salesman-collections', 'salesman-expenses'],
            'SALES' => ['sales', 'sales-returns', 'b2b-orders'],
            'PURCHASE' => ['suppliers', 'purchase-indents', 'rfqs', 'vendor-ratings', 'purchases', 'purchase-invoices'],
            'INVENTORY' => ['warehouses', 'stock', 'stock-ledger', 'stock-adjustments', 'stock-transfers', 'stock-returns', 'cycle-counts', 'gate-entries', 'advanced-inventory', 'barcodes'],
            'MFG' => ['boms', 'manufacturing-orders', 'colour-printing', 'job-works', 'textile'],
            'TAILOR' => ['tailors', 'contractors', 'rate-cards', 'piece-rates', 'tailor-works', 'tailor-alterations', 'tailor-bonuses', 'tailor-payments'],
            'EMBROIDERY' => ['embroidery-vendors', 'embroidery-works', 'embroidery-payments'],
            'EXPENSES' => ['expenses', 'expense-categories', 'employee-claims', 'vehicle-expenses', 'fuel-expenses', 'electricity-bills', 'factory-maintenances', 'asset-maintenances'],
            'TREASURY' => ['payments', 'payment-gateways', 'bank-accounts', 'vouchers', 'tds-tcs-rates'],
            'ASSETS' => ['vehicles', 'assets'],
            'GST' => ['gst-registrations', 'hsn-codes', 'e-invoices', 'e-way-bills'],
            'HR' => ['departments', 'employees', 'attendance', 'salaries', 'leave-types', 'leave-applications', 'overtime', 'employee-bonuses', 'salary-advances', 'employee-loans', 'biometric-devices', 'biometric-punches', 'salary-structures', 'payroll'],
            'TRANSPORT' => ['drivers', 'delivery-routes', 'dispatch-plans', 'dispatch-pods', 'transport-charges'],
            // Import & Export Logistics: the trade reference data …
            'EXIM' => ['countries', 'ports', 'shipping-lines', 'airlines', 'freight-forwarders', 'cha-agents', 'transporters'],
            // … and the shipments built on it. Split so a desk can hold the
            // shipments without being able to edit the masters behind them.
            'EXIM_SHIPMENTS' => ['shipments', 'shipment-containers', 'shipment-documents', 'shipment-customs', 'shipment-expenses', 'shipment-payments'],
            'BROKER' => ['brokers'],
            // Device Manager. Bundled with the event log because reading the log
            // is how you diagnose the device you are allowed to configure.
            'DEVICES' => ['devices', 'device-events'],
            'SERVICE' => ['service-jobs', 'service-contracts'],
            'PROJECTS' => ['projects', 'project-tasks', 'project-timesheets'],
            'PHARMACY' => ['prescriptions', 'drug-register'],
            'JEWELLERY' => ['metal-rates', 'old-gold'],
            'OPTICAL' => ['eye-prescriptions', 'optical-orders'],
            'RESTAURANT' => ['restaurant-tables', 'restaurant-orders', 'kitchen-orders'],
        ];
    }

    /**
     * Access levels → concrete actions.
     *   v = view · c = contribute (view/create/edit) · m = manage (+delete) ·
     *   a = approve (manage + approve, where the module supports it)
     *
     * @return array<int, string>
     */
    public static function levelActions(string $level): array
    {
        return match ($level) {
            'v' => ['view'],
            'c' => ['view', 'create', 'edit'],
            'm' => ['view', 'create', 'edit', 'delete'],
            'a' => ['view', 'create', 'edit', 'delete', 'approve'],
            default => ['view'],
        };
    }

    /**
     * The department org chart. Ordered map of department label → role slugs,
     * including the pre-existing roles so the whole hierarchy renders together.
     * The "System" bucket holds the built-in platform roles.
     *
     * @return array<string, array<int, string>>
     */
    public static function departments(): array
    {
        return [
            'Management' => ['company-owner', 'managing-director', 'ceo', 'director', 'general-manager', 'branch-manager', 'operations-manager'],
            'CRM' => ['crm-manager', 'sales-manager', 'sales-executive', 'telecaller', 'marketing-executive'],
            'Customer' => ['customer-support', 'relationship-manager'],
            'Purchase' => ['purchase-manager', 'purchase-executive', 'vendor-coordinator'],
            'Inventory' => ['inventory-manager', 'warehouse-manager', 'store-keeper', 'stock-auditor'],
            'Manufacturing' => ['production-manager', 'production-supervisor', 'factory-manager', 'machine-operator', 'tailor', 'cutting-operator', 'embroidery-operator', 'printing-operator', 'packing-staff', 'qc-inspector'],
            'Broker' => ['broker', 'broker-manager'],
            'Finance' => ['finance-manager', 'senior-accountant', 'accountant', 'cashier', 'auditor'],
            'HR' => ['hr-manager', 'hr-executive', 'payroll-executive'],
            'Transport' => ['transport-manager', 'dispatch-executive', 'transport-coordinator', 'driver', 'delivery-boy'],
            'Import & Export' => ['logistics-manager', 'import-executive', 'export-executive', 'documentation-executive', 'cha-executive'],
            'Administration' => ['office-admin', 'receptionist'],
            'System' => ['super-admin', 'admin', 'staff'],
        ];
    }

    /** Look up a role's department for the admin UI. Null for custom roles. */
    public static function departmentOf(string $roleSlug): ?string
    {
        foreach (self::departments() as $department => $roles) {
            if (in_array($roleSlug, $roles, true)) {
                return $department;
            }
        }

        return null;
    }

    /**
     * The NEW roles this catalog introduces (the pre-existing ones are seeded
     * elsewhere with their hand-tuned sets). slug → [label, spec]. A spec of
     * ['*'] means "all permissions". Permissions are tailored by seniority:
     * managers manage + approve their area, executives contribute, operators
     * mostly view and record their own work.
     *
     * @return array<string, array{label: string, spec: array<int, string>}>
     */
    public static function newRoles(): array
    {
        return [
            // ---- Management ----
            'company-owner' => ['label' => 'Company Owner', 'spec' => ['*']],
            'managing-director' => ['label' => 'Managing Director', 'spec' => ['*']],
            'ceo' => ['label' => 'CEO', 'spec' => ['*']],
            'director' => ['label' => 'Director', 'spec' => [
                'CATALOG:m', 'CRM_CORE:m', 'PRICE:m', 'COMMS:m', 'FIELD_SALES:m', 'SALES:a',
                'PURCHASE:a', 'INVENTORY:a', 'MFG:a', 'TAILOR:a', 'EMBROIDERY:m', 'EXPENSES:a',
                'TREASURY:m', 'ASSETS:m', 'GST:m', 'HR:a', 'TRANSPORT:m', 'EXIM:m', 'EXIM_SHIPMENTS:m', 'BROKER:m',
                'accounts:v', 'reports:v', 'workflows:m', 'audit-logs:v',
                'users:v', 'roles:v', 'companies:v', 'branches:v', 'settings:v', 'notifications:v', 'ai:v',
            ]],
            'general-manager' => ['label' => 'General Manager', 'spec' => [
                'CATALOG:m', 'CRM_CORE:m', 'PRICE:m', 'COMMS:m', 'FIELD_SALES:m', 'SALES:a',
                'PURCHASE:a', 'INVENTORY:a', 'MFG:a', 'TAILOR:a', 'EMBROIDERY:m', 'EXPENSES:a',
                'TREASURY:v', 'ASSETS:m', 'GST:v', 'HR:m', 'TRANSPORT:m', 'EXIM:m', 'EXIM_SHIPMENTS:m', 'BROKER:m',
                'accounts:v', 'reports:v', 'workflows:v', 'notifications:v', 'ai:v',
            ]],
            'branch-manager' => ['label' => 'Branch Manager', 'spec' => [
                'CATALOG:v', 'CRM_CORE:m', 'PRICE:v', 'COMMS:c', 'FIELD_SALES:m', 'SALES:a',
                'PURCHASE:c', 'INVENTORY:a', 'MFG:v', 'TRANSPORT:m', 'EXPENSES:c', 'BROKER:v', 'DEVICES:m',
                'reports:v', 'notifications:v', 'ai:v',
            ]],
            'operations-manager' => ['label' => 'Operations Manager', 'spec' => [
                'INVENTORY:a', 'MFG:a', 'TAILOR:a', 'EMBROIDERY:m', 'PURCHASE:c', 'TRANSPORT:m', 'EXIM:m', 'EXIM_SHIPMENTS:m',
                'CATALOG:v', 'EXPENSES:c', 'DEVICES:m', 'SERVICE:m', 'PROJECTS:m',
                'reports:v', 'workflows:v', 'notifications:v', 'ai:v',
            ]],

            // ---- CRM ----
            'crm-manager' => ['label' => 'CRM Manager', 'spec' => [
                'CRM_CORE:m', 'PRICE:m', 'COMMS:m', 'FIELD_SALES:m', 'SALES:a', 'CATALOG:v',
                'reports:v', 'notifications:v', 'ai:v',
            ]],
            'sales-executive' => ['label' => 'Sales Executive', 'spec' => [
                'customers:c', 'customer-categories:v', 'leads:c', 'follow-ups:c', 'price-lists:v',
                'SALES:c', 'CATALOG:v', 'stock:v', 'FIELD_SALES:v', 'reports:v', 'notifications:v',
            ]],
            'telecaller' => ['label' => 'Telecaller', 'spec' => [
                'leads:c', 'follow-ups:c', 'customers:v', 'whatsapp:c', 'sms:c', 'email:c', 'notifications:v',
            ]],
            'marketing-executive' => ['label' => 'Marketing Executive', 'spec' => [
                'marketing-campaigns:m', 'COMMS:m', 'coupons:m', 'referrals:m', 'loyalty:m',
                'leads:v', 'customers:v', 'reports:v', 'notifications:v',
            ]],

            // ---- Customer ----
            'customer-support' => ['label' => 'Customer Support', 'spec' => [
                'customers:v', 'follow-ups:c', 'b2b-orders:v', 'sales:v', 'sales-returns:c',
                'loyalty:v', 'whatsapp:c', 'sms:c', 'email:c', 'notifications:v',
            ]],
            'relationship-manager' => ['label' => 'Relationship Manager', 'spec' => [
                'customers:m', 'customer-categories:v', 'follow-ups:m', 'loyalty:m', 'referrals:m',
                'coupons:v', 'leads:c', 'sales:v', 'b2b-orders:c', 'price-lists:v', 'reports:v', 'notifications:v',
            ]],

            // ---- Purchase ----
            'purchase-executive' => ['label' => 'Purchase Executive', 'spec' => [
                'suppliers:c', 'purchase-indents:c', 'rfqs:c', 'vendor-ratings:c', 'purchases:c', 'purchase-invoices:c',
                'products:v', 'categories:v', 'stock:v', 'stock-ledger:v', 'email:c', 'reports:v', 'notifications:v',
            ]],
            'vendor-coordinator' => ['label' => 'Vendor Coordinator', 'spec' => [
                'suppliers:c', 'vendor-ratings:c', 'rfqs:c', 'purchase-indents:v', 'purchases:v', 'purchase-invoices:v',
                'products:v', 'email:c', 'notifications:v',
            ]],

            // ---- Inventory ----
            'inventory-manager' => ['label' => 'Inventory Manager', 'spec' => [
                'INVENTORY:a', 'CATALOG:m', 'boms:v', 'manufacturing-orders:v', 'suppliers:v',
                'DEVICES:m', 'reports:v', 'notifications:v', 'ai:v',
            ]],
            'store-keeper' => ['label' => 'Store Keeper', 'spec' => [
                'stock:c', 'stock-ledger:v', 'stock-adjustments:c', 'stock-transfers:c', 'gate-entries:m',
                'warehouses:v', 'products:v', 'barcodes:v', 'advanced-inventory:v', 'devices:v', 'device-events:v', 'notifications:v',
            ]],
            'stock-auditor' => ['label' => 'Stock Auditor', 'spec' => [
                'stock:v', 'stock-ledger:v', 'cycle-counts:c', 'stock-adjustments:v', 'advanced-inventory:v',
                'warehouses:v', 'products:v', 'reports:v', 'audit-logs:v', 'notifications:v',
            ]],

            // ---- Manufacturing ----
            'production-manager' => ['label' => 'Production Manager', 'spec' => [
                'MFG:a', 'boms:m', 'manufacturing-orders:a', 'job-works:a', 'TAILOR:m', 'EMBROIDERY:m', 'textile:m',
                'stock:c', 'stock-ledger:v', 'products:v', 'reports:v', 'notifications:v', 'ai:v',
            ]],
            'production-supervisor' => ['label' => 'Production Supervisor', 'spec' => [
                'manufacturing-orders:c', 'boms:v', 'job-works:c', 'textile:c', 'tailor-works:c', 'embroidery-works:c',
                'stock:v', 'products:v', 'notifications:v',
            ]],
            'factory-manager' => ['label' => 'Factory Manager', 'spec' => [
                'MFG:a', 'TAILOR:a', 'EMBROIDERY:a', 'textile:m', 'factory-maintenances:a', 'asset-maintenances:c',
                'stock:c', 'employees:v', 'attendance:c', 'reports:v', 'notifications:v',
            ]],
            'machine-operator' => ['label' => 'Machine Operator', 'spec' => [
                'manufacturing-orders:v', 'job-works:c', 'textile:v', 'stock:v', 'products:v', 'notifications:v',
            ]],
            'tailor' => ['label' => 'Tailor', 'spec' => [
                'tailor-works:c', 'tailor-alterations:c', 'piece-rates:v', 'rate-cards:v', 'products:v', 'notifications:v',
            ]],
            'cutting-operator' => ['label' => 'Cutting Operator', 'spec' => [
                'textile:c', 'manufacturing-orders:v', 'boms:v', 'stock:v', 'products:v', 'notifications:v',
            ]],
            'embroidery-operator' => ['label' => 'Embroidery Operator', 'spec' => [
                'embroidery-works:c', 'embroidery-vendors:v', 'embroidery-payments:v', 'products:v', 'notifications:v',
            ]],
            'printing-operator' => ['label' => 'Printing Operator', 'spec' => [
                'colour-printing:c', 'job-works:c', 'textile:v', 'products:v', 'notifications:v',
            ]],
            'packing-staff' => ['label' => 'Packing Staff', 'spec' => [
                'manufacturing-orders:v', 'gate-entries:c', 'dispatch-plans:v', 'stock:v', 'products:v', 'notifications:v',
            ]],
            'qc-inspector' => ['label' => 'QC Inspector', 'spec' => [
                'textile:c', 'manufacturing-orders:v', 'job-works:v', 'stock:v', 'products:v', 'reports:v', 'notifications:v',
            ]],

            // ---- Service ----
            'service-manager' => ['label' => 'Service Manager', 'spec' => [
                'SERVICE:m', 'customers:c', 'employees:v', 'stock:v', 'CATALOG:v',
                'sales:v', 'payments:v', 'reports:v', 'notifications:v', 'ai:v',
            ]],
            // A technician works the jobs on the bench: they record diagnosis,
            // parts and resolution, but do not open, close or price the book.
            'service-technician' => ['label' => 'Service Technician', 'spec' => [
                'service-jobs:c', 'service-contracts:v', 'stock:v', 'products:v',
                'customers:v', 'notifications:v',
            ]],

            // ---- Restaurant ----
            'restaurant-manager' => ['label' => 'Restaurant Manager', 'spec' => [
                'RESTAURANT:m', 'sales:a', 'CATALOG:m', 'stock:v', 'EXPENSES:c',
                'customers:c', 'employees:v', 'payments:c', 'reports:v', 'notifications:v', 'ai:v',
            ]],
            // A waiter opens tables and fires food but cannot settle a bill or
            // change the menu.
            'waiter' => ['label' => 'Waiter / Steward', 'spec' => [
                'restaurant-tables:v', 'restaurant-orders:c', 'kitchen-orders:v',
                'products:v', 'customers:c', 'notifications:v',
            ]],
            // The kitchen only needs its own board.
            'kitchen-staff' => ['label' => 'Kitchen Staff', 'spec' => [
                'kitchen-orders:c', 'restaurant-orders:v', 'products:v', 'notifications:v',
            ]],

            // ---- Optical ----
            // The optometrist tests eyes and records powers; the dispensing
            // optician turns a test into a pair of spectacles.
            'optometrist' => ['label' => 'Optometrist', 'spec' => [
                'eye-prescriptions:m', 'optical-orders:v', 'customers:c',
                'products:v', 'notifications:v',
            ]],
            'optician' => ['label' => 'Dispensing Optician', 'spec' => [
                'OPTICAL:m', 'sales:c', 'CATALOG:c', 'stock:v',
                'customers:c', 'payments:v', 'reports:v', 'notifications:v',
            ]],

            // ---- Jewellery ----
            // Quoting the morning rate governs every bill in the shop, so it
            // sits with the owner/manager rather than with the counter.
            'jewellery-manager' => ['label' => 'Jewellery Manager', 'spec' => [
                'JEWELLERY:m', 'sales:a', 'CATALOG:m', 'stock:v', 'advanced-inventory:v',
                'customers:c', 'payments:c', 'reports:v', 'notifications:v', 'ai:v',
            ]],
            'jewellery-salesman' => ['label' => 'Jewellery Salesman', 'spec' => [
                'metal-rates:v', 'old-gold:c', 'sales:c', 'products:v', 'stock:v',
                'customers:c', 'notifications:v',
            ]],

            // ---- Pharmacy ----
            // A registered pharmacist dispenses and answers for the register;
            // a counter assistant can write a slip and sell against it but not
            // produce the statutory record.
            'pharmacist' => ['label' => 'Pharmacist', 'spec' => [
                'PHARMACY:m', 'sales:c', 'stock:v', 'advanced-inventory:v', 'CATALOG:c',
                'customers:c', 'reports:v', 'notifications:v',
            ]],
            'pharmacy-assistant' => ['label' => 'Pharmacy Assistant', 'spec' => [
                'prescriptions:c', 'sales:c', 'stock:v', 'products:v',
                'customers:c', 'notifications:v',
            ]],

            // ---- Projects ----
            'project-manager' => ['label' => 'Project Manager', 'spec' => [
                'PROJECTS:m', 'project-timesheets:a', 'customers:v', 'employees:v',
                'expenses:c', 'sales:v', 'payments:v', 'reports:v', 'notifications:v', 'ai:v',
            ]],
            // A member works the board and logs their own hours; they cannot
            // approve those hours or touch the budget they are spending.
            'project-member' => ['label' => 'Project Member', 'spec' => [
                'projects:v', 'project-tasks:c', 'project-timesheets:c',
                'customers:v', 'notifications:v',
            ]],

            // ---- Broker ----
            'broker' => ['label' => 'Broker', 'spec' => [
                'brokers:v', 'sales:v', 'b2b-orders:v', 'reports:v', 'notifications:v',
            ]],
            'broker-manager' => ['label' => 'Broker Manager', 'spec' => [
                'brokers:m', 'sales:v', 'b2b-orders:v', 'payments:c', 'reports:v', 'notifications:v',
            ]],

            // ---- Finance ----
            'finance-manager' => ['label' => 'Finance Manager', 'spec' => [
                'EXPENSES:a', 'TREASURY:m', 'GST:m', 'accounts:v', 'reports:v',
                'purchase-invoices:a', 'sales:v', 'purchases:v', 'financial-years:m',
                'payroll:v', 'salaries:v', 'suppliers:v', 'customers:v', 'notifications:v', 'ai:v',
            ]],
            'senior-accountant' => ['label' => 'Senior Accountant', 'spec' => [
                'EXPENSES:a', 'TREASURY:m', 'GST:m', 'accounts:v', 'reports:v',
                'purchase-invoices:a', 'purchases:v', 'sales:v', 'suppliers:v', 'customers:v', 'notifications:v',
            ]],
            'cashier' => ['label' => 'Cashier', 'spec' => [
                'payments:c', 'vouchers:c', 'bank-accounts:v', 'expenses:c', 'accounts:v',
                'sales:v', 'customers:v', 'devices:v', 'device-events:v', 'notifications:v',
            ]],

            // ---- HR ----
            'hr-manager' => ['label' => 'HR Manager', 'spec' => [
                'HR:a', 'DEVICES:m', 'reports:v', 'notifications:v', 'ai:v',
            ]],
            'hr-executive' => ['label' => 'HR Executive', 'spec' => [
                'employees:c', 'attendance:c', 'leave-applications:c', 'overtime:c', 'leave-types:v',
                'salaries:v', 'biometric-punches:v', 'departments:v', 'notifications:v',
            ]],
            'payroll-executive' => ['label' => 'Payroll Executive', 'spec' => [
                'payroll:c', 'salaries:c', 'salary-structures:c', 'salary-advances:c', 'employee-loans:c',
                'employee-bonuses:c', 'attendance:v', 'employees:v', 'bank-accounts:v', 'notifications:v',
            ]],

            // ---- Transport ----
            'transport-manager' => ['label' => 'Transport Manager', 'spec' => [
                'TRANSPORT:m', 'EXIM:m', 'EXIM_SHIPMENTS:m', 'vehicles:m', 'vehicle-expenses:a', 'fuel-expenses:a',
                'reports:v', 'notifications:v',
            ]],
            'dispatch-executive' => ['label' => 'Dispatch Executive', 'spec' => [
                'dispatch-plans:m', 'dispatch-pods:m', 'delivery-routes:m', 'transport-charges:c',
                'drivers:v', 'vehicles:v', 'gate-entries:c', 'EXIM:v', 'shipments:v', 'shipment-containers:c',
                'notifications:v',
            ]],
            // Books the inland leg — trucks between port/CFS/ICD and the
            // warehouse. Sits in Transport on the org chart but serves the
            // Import & Export desk, hence the shipment/container access.
            'transport-coordinator' => ['label' => 'Transport Coordinator', 'spec' => [
                'dispatch-plans:c', 'dispatch-pods:c', 'delivery-routes:c', 'transport-charges:c',
                'drivers:v', 'vehicles:v', 'gate-entries:c', 'warehouses:v',
                'EXIM:v', 'shipments:v', 'shipment-containers:c', 'shipment-expenses:c',
                'reports:v', 'notifications:v',
            ]],
            'driver' => ['label' => 'Driver', 'spec' => [
                'dispatch-plans:v', 'dispatch-pods:c', 'delivery-routes:v', 'vehicles:v', 'fuel-expenses:c', 'notifications:v',
            ]],
            'delivery-boy' => ['label' => 'Delivery Boy', 'spec' => [
                'dispatch-plans:v', 'dispatch-pods:c', 'delivery-routes:v', 'notifications:v',
            ]],

            // ---- Import & Export ----
            // The cross-border desk. The manager owns the whole module; the
            // executives each own one lane or one document type, which is how a
            // freight desk actually divides up: buying/selling side, paperwork,
            // and the customs filing that only a licensed CHA handles.
            //
            // Two more roles work this module from their own departments rather
            // than from here: warehouse-manager (Inventory — receives inbound
            // cargo, seeded with its hand-tuned set in RolePermissionSeeder) and
            // transport-coordinator (Transport — the inland leg).
            'logistics-manager' => ['label' => 'Logistics Manager', 'spec' => [
                'EXIM:m', 'EXIM_SHIPMENTS:m', 'TRANSPORT:m', 'suppliers:v', 'customers:v',
                'purchases:v', 'sales:v', 'stock:v', 'warehouses:v',
                'reports:v', 'workflows:v', 'notifications:v', 'ai:v',
            ]],
            'import-executive' => ['label' => 'Import Executive', 'spec' => [
                'EXIM:v', 'shipments:c', 'shipment-containers:c', 'shipment-documents:c',
                'shipment-expenses:c', 'shipment-customs:v', 'shipment-payments:v',
                'suppliers:v', 'purchases:v', 'purchase-invoices:v', 'stock:v', 'warehouses:v',
                'reports:v', 'notifications:v',
            ]],
            'export-executive' => ['label' => 'Export Executive', 'spec' => [
                'EXIM:v', 'shipments:c', 'shipment-containers:c', 'shipment-documents:c',
                'shipment-expenses:c', 'shipment-customs:v', 'shipment-payments:v',
                'customers:v', 'sales:v', 'stock:v', 'warehouses:v',
                'reports:v', 'notifications:v',
            ]],
            'documentation-executive' => ['label' => 'Documentation Executive', 'spec' => [
                'shipments:v', 'shipment-documents:m', 'shipment-containers:v', 'shipment-customs:v',
                'EXIM:v', 'suppliers:v', 'customers:v', 'email:c', 'notifications:v',
            ]],
            'cha-executive' => ['label' => 'CHA Executive', 'spec' => [
                'shipments:v', 'shipment-customs:m', 'shipment-documents:c', 'shipment-containers:c',
                'shipment-expenses:c', 'EXIM:v', 'notifications:v',
            ]],

            // ---- Administration ----
            'office-admin' => ['label' => 'Office Admin', 'spec' => [
                'users:v', 'employees:c', 'attendance:c', 'departments:v', 'ASSETS:c', 'expenses:c',
                'companies:v', 'branches:v', 'settings:v', 'DEVICES:m', 'notifications:v',
            ]],
            'receptionist' => ['label' => 'Receptionist', 'spec' => [
                'customers:v', 'leads:c', 'follow-ups:c', 'employees:v', 'attendance:v',
                'whatsapp:c', 'sms:c', 'notifications:v',
            ]],
        ];
    }

    /**
     * Expand a role spec into concrete "module.action" permission names,
     * skipping actions a module doesn't support. Returns null for a ['*'] spec
     * (meaning "grant everything"), which the caller handles specially.
     *
     * @param  array<int, string>  $spec
     * @return array<int, string>|null
     */
    public static function expand(array $spec): ?array
    {
        if ($spec === ['*']) {
            return null;
        }

        $groups = self::groups();
        $out = [];

        foreach ($spec as $entry) {
            [$token, $level] = array_pad(explode(':', $entry, 2), 2, 'v');
            $modules = $groups[$token] ?? [$token];

            foreach ($modules as $module) {
                foreach (self::levelActions($level) as $action) {
                    if (self::permissionExists($module, $action)) {
                        $out[] = "{$module}.{$action}";
                    }
                }
            }
        }

        return array_values(array_unique($out));
    }

    /** Whether a given module/action pair is a real permission. */
    private static function permissionExists(string $module, string $action): bool
    {
        if (in_array($module, self::VIEW_ONLY_MODULES, true)) {
            return $action === 'view';
        }

        if (! in_array($module, self::MODULES, true)) {
            return false;
        }

        if ($action === 'approve') {
            return in_array($module, self::APPROVABLE, true);
        }

        return in_array($action, ['view', 'create', 'edit', 'delete'], true);
    }
}
