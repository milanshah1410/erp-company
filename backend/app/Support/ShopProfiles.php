<?php

declare(strict_types=1);

namespace App\Support;

use App\Modules\Industry\Support\IndustryRegistry;

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
 * Every preset carries `crm` and `ai`, which are common core rather than any one
 * trade's concern:
 *
 *  - `ai` — the assistant answers over whatever the workspace already has ("show
 *    this month's sales", low-stock and reorder hints), so it is as useful to a
 *    kirana counter as to a factory.
 *  - `crm` — every trade chases a customer: follow-ups, WhatsApp and SMS are the
 *    same job whether the thing being sold is grain or a shipment.
 *
 * Both stay switchable modules — a tenant may untick either and an operator may
 * revoke them — they just start on. Note `crm` is not in the Starter package, so
 * a Starter workspace still won't see it until it upgrades; forTenant() narrows
 * the stored selection to the plan ceiling on every read.
 *
 * Keys must exist in Modules::CATALOGUE; sanitize() drops anything stale, and
 * the plan ceiling is applied on top by the caller (RegistrationController and
 * EnabledModules both narrow to what the package sells).
 *
 * Two sources feed this list. PROFILES below are the hand-written presets that
 * predate the plugin layer; the rest come from registered industry plugins via
 * IndustryRegistry::tradePresets(). all() merges them, and every accessor reads
 * through it — so a vertical registering eight trades makes all eight appear in
 * the wizard without a line being added here.
 *
 * A plugin trade wins over a PROFILES entry of the same key: the plugin is the
 * more specific declaration and owns that trade's module set. 'restaurant' is
 * exactly this case — the F&B plugin now owns it.
 */
final class ShopProfiles
{
    /**
     * Hand-written presets, for trades no industry plugin covers yet. Prefer
     * declaring a new trade in a plugin over adding it here.
     *
     * key => [label, icon, description, modules]
     *
     * @var array<string, array{label: string, icon: string, description: string, modules: list<string>}>
     */
    public const PROFILES = [
        'grocery' => [
            'label' => 'Grocery / Kirana',
            'icon' => 'pi pi-shop',
            'description' => 'Counter billing, barcode, stock, purchase and the daily cash book',
            'modules' => [
                'sales', 'pos', 'purchase', 'inventory', 'accounts', 'gst', 'devices', 'crm', 'ai',
            ],
        ],
        'garment' => [
            'label' => 'Garment / Apparel',
            'icon' => 'pi pi-shopping-bag',
            'description' => 'Size/colour variants, label printing, loyalty, alterations',
            'modules' => [
                'sales', 'pos', 'purchase', 'inventory', 'accounts', 'gst',
                'loyalty', 'tailoring', 'devices', 'crm', 'ai',
            ],
        ],
        // 'dairy' and 'restaurant' used to be defined here. Both are now trades of
        // the Food & Beverage plugin, which owns their module sets — keeping copies
        // here would leave two definitions of one trade, drifting apart.
        'manufacturing' => [
            'label' => 'Manufacturing / Factory',
            'icon' => 'pi pi-cog',
            'description' => 'BOM, production orders, tailoring, embroidery, printing and QC',
            'modules' => [
                'sales', 'purchase', 'inventory', 'accounts', 'gst',
                'manufacturing', 'textile', 'tailoring', 'embroidery', 'hrms', 'assets', 'crm', 'ai',
            ],
        ],
        'wholesale' => [
            'label' => 'Wholesale / Distribution',
            'icon' => 'pi pi-truck',
            'description' => 'Bulk orders, brokers, customer credit, transport and outstanding',
            'modules' => [
                'sales', 'purchase', 'inventory', 'accounts', 'gst',
                'broker', 'field_sales', 'logistics', 'crm', 'ai',
            ],
        ],
        'optical' => [
            'label' => 'Optical Shop',
            'icon' => 'pi pi-eye',
            'description' => 'Eye tests, lens details, frame stock and spectacle orders',
            'modules' => [
                'optical', 'sales', 'pos', 'purchase', 'inventory', 'accounts', 'gst', 'loyalty', 'crm', 'ai',
            ],
        ],
        'jewellery' => [
            'label' => 'Jewellery',
            'icon' => 'pi pi-star',
            'description' => 'Rate card, purity & making charges, old-gold exchange, hallmarking',
            'modules' => [
                'jewellery', 'sales', 'pos', 'purchase', 'inventory', 'accounts', 'gst', 'loyalty', 'crm', 'ai',
            ],
        ],
        'medical' => [
            'label' => 'Medical Store / Pharmacy',
            'icon' => 'pi pi-heart-fill',
            'description' => 'Batch & expiry, drug schedules, prescriptions and the H1 register',
            'modules' => [
                'pharmacy', 'sales', 'pos', 'purchase', 'inventory', 'accounts', 'gst', 'devices', 'loyalty', 'crm', 'ai',
            ],
        ],
        'service' => [
            'label' => 'Service / Repair Centre',
            'icon' => 'pi pi-wrench',
            'description' => 'Job intake, technicians, spare parts, AMC contracts and billing',
            'modules' => [
                'service', 'projects', 'sales', 'purchase', 'inventory', 'accounts', 'gst', 'hrms', 'crm', 'ai',
            ],
        ],
        'projects' => [
            'label' => 'Projects / Consulting',
            'icon' => 'pi pi-briefcase',
            'description' => 'Project budgets, tasks, timesheets and billable hours',
            'modules' => [
                'projects', 'sales', 'accounts', 'gst', 'hrms', 'crm', 'purchase', 'ai',
            ],
        ],
        'construction' => [
            'label' => 'Construction / Infrastructure',
            'icon' => 'pi pi-building-columns',
            'description' => 'Site projects, material issue, labour, plant & machinery and running bills',
            'modules' => [
                'projects', 'purchase', 'inventory', 'sales', 'accounts', 'gst',
                'assets', 'hrms', 'logistics', 'crm', 'ai',
            ],
        ],
        'agriculture' => [
            'label' => 'Agriculture / Agri-inputs',
            'icon' => 'pi pi-sun',
            'description' => 'Seed, fertiliser & pesticide stock with batch/expiry, farmer credit and mandi despatch',
            'modules' => [
                'sales', 'pos', 'purchase', 'inventory', 'accounts', 'gst',
                'field_sales', 'logistics', 'broker', 'crm', 'ai',
            ],
        ],
        'trading' => [
            'label' => 'Import / Export',
            'icon' => 'pi pi-globe',
            'description' => 'Shipments, customs, containers and landed-cost tracking',
            'modules' => [
                'sales', 'purchase', 'inventory', 'accounts', 'gst', 'logistics', 'broker', 'crm', 'ai',
            ],
        ],
        'custom' => [
            'label' => 'Something else',
            'icon' => 'pi pi-sliders-h',
            'description' => 'Start from the essentials and pick the rest yourself',
            'modules' => [
                'sales', 'purchase', 'inventory', 'accounts', 'crm', 'ai',
            ],
        ],
    ];

    /**
     * Every preset — the hand-written ones plus every trade declared by a
     * registered industry plugin, which wins on a key collision.
     *
     * @return array<string, array{label: string, icon: string, description: string, modules: list<string>}>
     */
    public static function all(): array
    {
        return array_merge(self::PROFILES, IndustryRegistry::tradePresets());
    }

    /**
     * Every profile key.
     *
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    /**
     * The catalogue as a flat list, ready for the wizard's shop-type picker.
     *
     * Each row carries the vertical it belongs to (`industry`), so the picker can
     * group eight F&B trades under one heading instead of showing a flat wall of
     * twenty.
     *
     * A trade's vertical has two possible sources, checked in that order: the
     * plugin that declared it (authoritative — the plugin *is* the vertical), then
     * the industry template that curates it (Volume 11 §"Industry Templates"),
     * which is how the hand-written presets get grouped. A trade no template
     * claims still reports null and the picker shows it ungrouped.
     *
     * @return list<array{key: string, label: string, icon: string, description: string, modules: list<string>, industry: string|null}>
     */
    public static function options(): array
    {
        $trades = IndustryRegistry::trades();
        $curated = IndustryTemplates::tradeIndex();

        $out = [];
        foreach (self::all() as $key => $meta) {
            $industry = $trades[$key]['industry'] ?? $curated[$key] ?? null;
            $out[] = ['key' => $key] + $meta + ['industry' => $industry];
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
        $all = self::all();
        $profile = $all[$key ?? ''] ?? $all['custom'];

        return Modules::sanitize($profile['modules']);
    }
}
