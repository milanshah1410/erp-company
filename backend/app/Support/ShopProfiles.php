<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Shop-type presets for the onboarding wizard.
 *
 * A kirana shop does not need Manufacturing; a factory does not need Field
 * Sales. Rather than making every signup reason about seventeen module
 * checkboxes, the wizard asks one question — "what kind of business is this?" —
 * and ticks the modules that trade normally runs on. The selection stays fully
 * editable afterwards: a profile is a starting point, not a lock.
 *
 * The `modules` list is intentionally generous within a trade (a garment shop
 * that never alters a garment simply unticks Tailoring) because unticking is
 * cheaper for a new tenant than discovering a module they were never shown.
 *
 * Keys must exist in Modules::CATALOGUE; sanitize() drops anything stale, and
 * the plan ceiling is applied on top by the caller (RegistrationController and
 * EnabledModules both narrow to what the package sells).
 */
final class ShopProfiles
{
    /**
     * key => [label, icon, description, modules]
     *
     * @var array<string, array{label: string, icon: string, description: string, modules: list<string>}>
     */
    public const PROFILES = [
        'grocery' => [
            'label' => 'Grocery / Kirana',
            'icon' => 'pi pi-shopping-basket',
            'description' => 'Counter billing, barcode, stock, purchase and the daily cash book',
            'modules' => [
                'sales', 'pos', 'purchase', 'inventory', 'accounts', 'gst', 'devices',
            ],
        ],
        'garment' => [
            'label' => 'Garment / Apparel',
            'icon' => 'pi pi-shopping-bag',
            'description' => 'Size/colour variants, label printing, loyalty, alterations',
            'modules' => [
                'sales', 'pos', 'purchase', 'inventory', 'accounts', 'gst',
                'loyalty', 'tailoring', 'devices',
            ],
        ],
        'dairy' => [
            'label' => 'Dairy / FMCG',
            'icon' => 'pi pi-box',
            'description' => 'Batch & expiry tracking, stock alerts, route-wise daily collection',
            'modules' => [
                'sales', 'pos', 'purchase', 'inventory', 'accounts', 'gst',
                'field_sales', 'devices',
            ],
        ],
        'manufacturing' => [
            'label' => 'Manufacturing / Factory',
            'icon' => 'pi pi-cog',
            'description' => 'BOM, production orders, tailoring, embroidery, printing and QC',
            'modules' => [
                'sales', 'purchase', 'inventory', 'accounts', 'gst',
                'manufacturing', 'textile', 'tailoring', 'embroidery', 'hrms', 'assets',
            ],
        ],
        'wholesale' => [
            'label' => 'Wholesale / Distribution',
            'icon' => 'pi pi-truck',
            'description' => 'Bulk orders, brokers, customer credit, transport and outstanding',
            'modules' => [
                'sales', 'purchase', 'inventory', 'accounts', 'gst',
                'broker', 'field_sales', 'logistics',
            ],
        ],
        'restaurant' => [
            'label' => 'Restaurant / Cafe',
            'icon' => 'pi pi-shop',
            'description' => 'Tables, kitchen order tickets, running bills and takeaway',
            'modules' => [
                'restaurant', 'sales', 'pos', 'purchase', 'inventory', 'accounts', 'gst', 'loyalty', 'hrms',
            ],
        ],
        'optical' => [
            'label' => 'Optical Shop',
            'icon' => 'pi pi-eye',
            'description' => 'Eye tests, lens details, frame stock and spectacle orders',
            'modules' => [
                'optical', 'sales', 'pos', 'purchase', 'inventory', 'accounts', 'gst', 'loyalty', 'crm',
            ],
        ],
        'jewellery' => [
            'label' => 'Jewellery',
            'icon' => 'pi pi-star',
            'description' => 'Rate card, purity & making charges, old-gold exchange, hallmarking',
            'modules' => [
                'jewellery', 'sales', 'pos', 'purchase', 'inventory', 'accounts', 'gst', 'loyalty', 'crm',
            ],
        ],
        'medical' => [
            'label' => 'Medical Store / Pharmacy',
            'icon' => 'pi pi-heart-fill',
            'description' => 'Batch & expiry, drug schedules, prescriptions and the H1 register',
            'modules' => [
                'pharmacy', 'sales', 'pos', 'purchase', 'inventory', 'accounts', 'gst', 'devices', 'loyalty',
            ],
        ],
        'service' => [
            'label' => 'Service / Repair Centre',
            'icon' => 'pi pi-wrench',
            'description' => 'Job intake, technicians, spare parts, AMC contracts and billing',
            'modules' => [
                'service', 'projects', 'sales', 'purchase', 'inventory', 'accounts', 'gst', 'hrms', 'crm',
            ],
        ],
        'projects' => [
            'label' => 'Projects / Consulting',
            'icon' => 'pi pi-briefcase',
            'description' => 'Project budgets, tasks, timesheets and billable hours',
            'modules' => [
                'projects', 'sales', 'accounts', 'gst', 'hrms', 'crm', 'purchase',
            ],
        ],
        'trading' => [
            'label' => 'Import / Export',
            'icon' => 'pi pi-globe',
            'description' => 'Shipments, customs, containers and landed-cost tracking',
            'modules' => [
                'sales', 'purchase', 'inventory', 'accounts', 'gst', 'logistics', 'broker',
            ],
        ],
        'custom' => [
            'label' => 'Something else',
            'icon' => 'pi pi-sliders-h',
            'description' => 'Start from the essentials and pick the rest yourself',
            'modules' => [
                'sales', 'purchase', 'inventory', 'accounts',
            ],
        ],
    ];

    /**
     * Every profile key.
     *
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::PROFILES);
    }

    /**
     * The catalogue as a flat list, ready for the wizard's shop-type picker.
     *
     * @return list<array{key: string, label: string, icon: string, description: string, modules: list<string>}>
     */
    public static function options(): array
    {
        $out = [];
        foreach (self::PROFILES as $key => $meta) {
            $out[] = ['key' => $key] + $meta;
        }

        return $out;
    }

    /**
     * The module set a profile switches on, narrowed to keys the catalogue still
     * knows. Unknown profiles fall back to `custom` so a stale wizard build (or a
     * hand-written industry string from an older signup) can never produce an
     * empty selection.
     *
     * @return list<string>
     */
    public static function modulesFor(?string $key): array
    {
        $profile = self::PROFILES[$key ?? ''] ?? self::PROFILES['custom'];

        return Modules::sanitize($profile['modules']);
    }
}
