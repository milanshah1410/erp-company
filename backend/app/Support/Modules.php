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
