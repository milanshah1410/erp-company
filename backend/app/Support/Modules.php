<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The canonical module catalogue (onboarding wizard §3).
 *
 * A "module" is a switchable slice of the product. Every key here is used in
 * three places and must stay in sync:
 *
 *  1. MenuSeeder tags menu rows with `'module' => <key>`; MenuController hides a
 *     tagged row when the tenant's `enabled_modules` setting excludes it.
 *  2. Plan::allowedModules() — a plan's `feature_limits.modules` lists which of
 *     these a subscriber may switch on ('*' = all, i.e. Enterprise).
 *  3. The registration wizard renders this catalogue (GET /api/modules) and
 *     locks anything the chosen plan does not allow.
 *
 * Core areas (Dashboard, Masters, Reports shell, Administration) carry no module
 * tag on purpose — they are never hidden, so a tenant can always reach settings.
 */
final class Modules
{
    /**
     * key => [label, icon, description]
     *
     * Order is the order the wizard renders them in: revenue-side first, then
     * operations, then finance/people/back-office.
     *
     * @var array<string, array{label: string, icon: string, description: string}>
     */
    public const CATALOGUE = [
        'sales' => [
            'label' => 'Sales',
            'icon' => 'pi pi-shopping-cart',
            'description' => 'Quotations, orders, invoices, returns, B2B orders',
        ],
        'pos' => [
            'label' => 'POS',
            'icon' => 'pi pi-calculator',
            'description' => 'Counter billing with cash/card/UPI tender',
        ],
        'crm' => [
            'label' => 'CRM',
            'icon' => 'pi pi-heart',
            'description' => 'Leads, follow-ups, campaigns, WhatsApp & SMS',
        ],
        'field_sales' => [
            'label' => 'Field Sales',
            'icon' => 'pi pi-map',
            'description' => 'Salesmen, beat plans, visits, live tracking, collections',
        ],
        /**
         * Distribution & Wholesale. Separate from `field_sales` on purpose: beats
         * are how a distributor *reaches* its parties, this is how it decides
         * whether to supply them — and a cash & carry counter with no salesman at
         * all still lives or dies on party credit.
         */
        'distribution' => [
            'label' => 'Distribution & Wholesale',
            'icon' => 'pi pi-truck',
            'description' => 'Party credit control, supply holds and outstanding ageing by days past due',
        ],
        'ecommerce' => [
            'label' => 'E-commerce',
            'icon' => 'pi pi-globe',
            'description' => 'Marketplace connectors, channel orders and listings',
        ],
        'broker' => [
            'label' => 'Broker',
            'icon' => 'pi pi-user-plus',
            'description' => 'Broker masters, commissions and broker ledger',
        ],
        'purchase' => [
            'label' => 'Purchase',
            'icon' => 'pi pi-shopping-bag',
            'description' => 'Indents, RFQs, purchase orders, vendor invoices',
        ],
        'inventory' => [
            'label' => 'Inventory',
            'icon' => 'pi pi-warehouse',
            'description' => 'Stock, transfers, adjustments, batches and serials',
        ],
        'manufacturing' => [
            'label' => 'Manufacturing',
            'icon' => 'pi pi-cog',
            'description' => 'BOMs, manufacturing orders, job work, colour printing',
        ],
        'textile' => [
            'label' => 'Textile',
            'icon' => 'pi pi-th-large',
            'description' => 'Fabric rolls, inspection, shrinkage, cutting',
        ],
        'tailoring' => [
            'label' => 'Tailoring',
            'icon' => 'pi pi-user-edit',
            'description' => 'Tailors, contractors, rate cards, piece-rate work',
        ],
        'embroidery' => [
            'label' => 'Embroidery',
            'icon' => 'pi pi-star',
            'description' => 'Embroidery vendors, dispatch/receipt and payments',
        ],
        'logistics' => [
            'label' => 'Logistics',
            'icon' => 'pi pi-truck',
            'description' => 'Vehicles, drivers, dispatch planning, proof of delivery, import & export masters',
        ],
        'accounts' => [
            'label' => 'Accounts',
            'icon' => 'pi pi-wallet',
            'description' => 'Vouchers, cash/bank books, ledgers, expenses, P&L',
        ],
        'gst' => [
            'label' => 'GST & Compliance',
            'icon' => 'pi pi-verified',
            'description' => 'GSTINs, HSN master, e-invoice, e-way bill, TDS/TCS',
        ],
        'hrms' => [
            'label' => 'HR & Payroll',
            'icon' => 'pi pi-id-card',
            'description' => 'Employees, attendance, leave, payroll, loans',
        ],
        'service' => [
            'label' => 'Service Management',
            'icon' => 'pi pi-wrench',
            'description' => 'Repair jobs, technicians, spare parts and AMC contracts',
        ],
        'restaurant' => [
            'label' => 'Restaurant',
            'icon' => 'pi pi-shop',
            'description' => 'Table management, kitchen order tickets and running bills',
        ],
        'optical' => [
            'label' => 'Optical',
            'icon' => 'pi pi-eye',
            'description' => 'Eye powers, lens specifications and spectacle orders',
        ],
        'jewellery' => [
            'label' => 'Jewellery',
            'icon' => 'pi pi-star',
            'description' => 'Daily metal rates, purity & making charges, old-gold exchange',
        ],
        'pharmacy' => [
            'label' => 'Pharmacy',
            'icon' => 'pi pi-heart-fill',
            'description' => 'Drug schedules, prescriptions and the Schedule H1/X register',
        ],
        'diagnostics' => [
            'label' => 'Diagnostic Lab',
            'icon' => 'pi pi-filter',
            'description' => 'Test master, sample collection, results against reference ranges and verified reports',
        ],
        // Separate from `diagnostics` on purpose: a standalone pathology lab has
        // no wards to issue to, and a nursing home may run a store without a lab.
        // One combined "healthcare" module would force each to carry the other's
        // menu.
        'hospital' => [
            'label' => 'Hospital Store',
            'icon' => 'pi pi-building',
            'description' => 'Ward indents, department-wise issue of drugs and consumables, consumption reporting',
        ],
        'agriculture' => [
            'label' => 'Agriculture',
            'icon' => 'pi pi-sun',
            'description' => 'Seed, fertiliser & pesticide attributes and the statutory licence register',
        ],
        // Separate from 'agriculture' on purpose: an agro-input counter and a
        // dairy farm are both "agriculture" to a brochure, but a fertiliser
        // dealer has no animals and a poultry farm sells no licences. Splitting
        // them keeps each trade's nav to what it actually works with.
        'livestock' => [
            'label' => 'Livestock & Poultry',
            'icon' => 'pi pi-heart',
            'description' => 'Cattle & flock registers, daily milk/egg output, feed and mortality',
        ],
        // Separate from 'service' on purpose: the job-card lifecycle a garage
        // runs on is the Service module and is shared with every other repair
        // trade. What is peculiar to vehicles — the VIN register, the
        // appointment book, warranty by date *and* distance — is this one, so a
        // parts counter with no workshop can run it alone and an appliance
        // repair shop never sees an odometer.
        'automobile' => [
            'label' => 'Automobile',
            'icon' => 'pi pi-car',
            'description' => 'Vehicle register by VIN, service bookings, job cards and warranty tracking',
        ],
        'projects' => [
            'label' => 'Project Management',
            'icon' => 'pi pi-briefcase',
            'description' => 'Projects, tasks, timesheets and budget-vs-actual costing',
        ],
        'loyalty' => [
            'label' => 'Loyalty & Offers',
            'icon' => 'pi pi-ticket',
            'description' => 'Points programme, coupons and customer rewards',
        ],
        'assets' => [
            'label' => 'Asset Management',
            'icon' => 'pi pi-box',
            'description' => 'Fixed-asset register and maintenance schedules',
        ],
        'workflow' => [
            'label' => 'Workflow Automation',
            'icon' => 'pi pi-share-alt',
            'description' => 'IF/THEN rules for approvals, notifications, field updates',
        ],
        'devices' => [
            'label' => 'Hardware / Devices',
            'icon' => 'pi pi-desktop',
            'description' => 'Scanners, thermal & label printers, weighing scales, RFID',
        ],
        'ai' => [
            'label' => 'AI Assistant',
            'icon' => 'pi pi-sparkles',
            'description' => 'Forecasts, demand insights and the bilingual ERP assistant',
        ],
    ];

    /**
     * Every module key.
     *
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::CATALOGUE);
    }

    /**
     * The catalogue as a flat list, ready for the wizard's module grid.
     *
     * @return list<array{key: string, label: string, icon: string, description: string}>
     */
    public static function options(): array
    {
        $out = [];
        foreach (self::CATALOGUE as $key => $meta) {
            $out[] = ['key' => $key] + $meta;
        }

        return $out;
    }

    /**
     * Keep only keys that exist in the catalogue (drops stale keys stored by an
     * older wizard build).
     *
     * @param  array<int, mixed>  $keys
     * @return list<string>
     */
    public static function sanitize(array $keys): array
    {
        return array_values(array_intersect(
            array_map(static fn ($k) => (string) $k, $keys),
            self::keys(),
        ));
    }
}
