<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\IndustryTemplate;
use App\Modules\Industry\Support\IndustryRegistry;
use Illuminate\Database\Seeder;

/**
 * The industry catalogue the SaaS owner sells (Volume 11 §"Industry Templates").
 *
 * The seven templates named in the requirements come first, in that order. Two
 * more follow because the trade presets that already exist have to live
 * somewhere: "Services" (repair centres, consulting) and "Other" (the
 * start-from-essentials path). Without them those trades would vanish from a
 * picker that groups by industry — a regression dressed as a new feature.
 *
 * Restaurant is special: the Food & Beverage plugin already owns that vertical
 * and its eight trades, so its row carries `plugin_key` and no trade list of its
 * own. Any future plugin gets the same treatment automatically — the loop at the
 * end adds a system row for every registered vertical that has no template yet,
 * so registering a plugin still needs nothing but its PLUGINS line.
 *
 * Re-running is safe and non-destructive: rows are matched on `key`, and an
 * operator's edits to label/description/trades are preserved (only genuinely
 * new rows are written in full). Change a seeded default deliberately and the
 * seeder will not fight you for it.
 */
class IndustryTemplateSeeder extends Seeder
{
    /**
     * key => [label, icon, description, trades, modules]
     *
     * `modules` is the fallback selection for a signup that names the industry
     * but never picks a trade; a picked trade always wins (see
     * IndustryTemplates::modulesFor).
     *
     * @var array<string, array{label: string, icon: string, description: string, trades: list<string>, modules: list<string>}>
     */
    private const TEMPLATES = [
        'retail' => [
            'label' => 'Retail',
            'icon' => 'pi pi-shop',
            'description' => 'Counter billing, barcode, stock and the daily cash book',
            'trades' => ['grocery', 'garment', 'optical', 'jewellery'],
            'modules' => ['sales', 'pos', 'purchase', 'inventory', 'accounts', 'gst', 'loyalty', 'devices', 'crm', 'ai'],
        ],
        // Keyed after the plugin that owns it, not after the requirement's
        // "Restaurant" wording: a trade declared by a plugin reports the plugin's
        // key as its industry (IndustryRegistry wins over curation, by design), so
        // a template keyed anything else would leave its own trades ungrouped.
        // The label is what anyone actually sees.
        'food_beverage' => [
            'label' => 'Restaurant & Food',
            'icon' => 'pi pi-shopping-bag',
            'description' => 'Tables, kitchen tickets, recipes and ingredient depletion',
            // Trades come from the Food & Beverage plugin — see PLUGIN_BACKED.
            'trades' => [],
            'modules' => ['sales', 'pos', 'restaurant', 'purchase', 'inventory', 'accounts', 'gst', 'loyalty', 'crm', 'ai'],
        ],
        'manufacturing' => [
            'label' => 'Manufacturing',
            'icon' => 'pi pi-cog',
            'description' => 'BOMs, production orders, job work and quality control',
            'trades' => ['manufacturing'],
            'modules' => ['sales', 'purchase', 'inventory', 'manufacturing', 'accounts', 'gst', 'hrms', 'assets', 'crm', 'ai'],
        ],
        'healthcare' => [
            'label' => 'Healthcare',
            'icon' => 'pi pi-heart-fill',
            'description' => 'Batch & expiry, drug schedules, prescriptions and registers',
            'trades' => ['medical'],
            'modules' => ['pharmacy', 'sales', 'pos', 'purchase', 'inventory', 'accounts', 'gst', 'devices', 'crm', 'ai'],
        ],
        'logistics' => [
            'label' => 'Logistics & Distribution',
            'icon' => 'pi pi-truck',
            'description' => 'Shipments, customs, fleet, dispatch planning and delivery proof',
            'trades' => ['trading', 'wholesale'],
            'modules' => ['sales', 'purchase', 'inventory', 'logistics', 'accounts', 'gst', 'broker', 'field_sales', 'crm', 'ai'],
        ],
        'construction' => [
            'label' => 'Construction',
            'icon' => 'pi pi-building-columns',
            'description' => 'Site projects, material issue, labour and plant & machinery',
            'trades' => ['construction'],
            'modules' => ['projects', 'purchase', 'inventory', 'sales', 'accounts', 'gst', 'assets', 'hrms', 'logistics', 'crm', 'ai'],
        ],
        'agriculture' => [
            'label' => 'Agriculture',
            'icon' => 'pi pi-sun',
            'description' => 'Agri-input stock with batch & expiry, farmer credit and mandi despatch',
            'trades' => ['agriculture'],
            'modules' => ['sales', 'pos', 'purchase', 'inventory', 'accounts', 'gst', 'field_sales', 'logistics', 'crm', 'ai'],
        ],

        // Beyond the seven: the homes for trade presets that already ship.
        'services' => [
            'label' => 'Services & Projects',
            'icon' => 'pi pi-wrench',
            'description' => 'Job intake, technicians, AMC contracts, timesheets and billable hours',
            'trades' => ['service', 'projects'],
            'modules' => ['service', 'projects', 'sales', 'purchase', 'inventory', 'accounts', 'gst', 'hrms', 'crm', 'ai'],
        ],
        'other' => [
            'label' => 'Other',
            'icon' => 'pi pi-sliders-h',
            'description' => 'Start from the essentials and pick the rest yourself',
            'trades' => ['custom'],
            'modules' => ['sales', 'purchase', 'inventory', 'accounts', 'crm', 'ai'],
        ],
    ];

    /** Template key => the industry plugin that owns its trades. */
    private const PLUGIN_BACKED = [
        'food_beverage' => 'food_beverage',
    ];

    public function run(): void
    {
        $sort = 0;

        foreach (self::TEMPLATES as $key => $template) {
            $sort += 10;

            $row = IndustryTemplate::firstOrNew(['key' => $key]);

            // An operator may have renamed or re-curated a seeded template; only
            // fill what is genuinely unset so a re-seed is not an undo button.
            $row->label = $row->label ?: $template['label'];
            $row->icon = $row->icon ?: $template['icon'];
            $row->description = $row->description ?: $template['description'];
            $row->modules ??= $template['modules'];
            $row->trades ??= $template['trades'];
            $row->plugin_key ??= self::PLUGIN_BACKED[$key] ?? null;
            $row->is_system = true;
            $row->sort_order = $row->exists ? $row->sort_order : $sort;
            if (! $row->exists) {
                $row->is_active = true;
            }
            $row->save();
        }

        // Every registered plugin vertical gets a template, so a new plugin shows
        // up in the picker and the panel without a seeder edit.
        foreach (IndustryRegistry::plugins() as $plugin) {
            $claimed = IndustryTemplate::where('plugin_key', $plugin->key())->exists();
            if ($claimed) {
                continue;
            }

            $sort += 10;

            IndustryTemplate::updateOrCreate(
                ['key' => $plugin->key()],
                [
                    'label' => $plugin->label(),
                    'icon' => $plugin->icon(),
                    'description' => $plugin->description(),
                    'modules' => [],
                    'trades' => [],
                    'plugin_key' => $plugin->key(),
                    'is_system' => true,
                    'is_active' => true,
                    'sort_order' => $sort,
                ],
            );
        }
    }
}
