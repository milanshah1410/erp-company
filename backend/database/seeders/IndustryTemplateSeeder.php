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
            // Describes the whole vertical, not just its first trade: this row
            // now sells eight of them, and a dairy or a bakery buyer reading
            // "tables and kitchen tickets" would think they were in the wrong
            // place. (An install seeded before this keeps its own wording — the
            // seeder never overwrites an operator-visible field. Edit it on
            // /platform/industries, where the change is audit-logged.)
            'description' => 'Tables, kitchen tickets and recipes; plus combos, happy hours, cake orders, milk rounds and counter tokens',
            // Trades come from the Food & Beverage plugin — see PLUGIN_BACKED.
            'trades' => [],
            'modules' => ['sales', 'pos', 'restaurant', 'purchase', 'inventory', 'accounts', 'gst', 'loyalty', 'crm', 'ai'],
        ],
        // Keyed the same as the Manufacturing plugin, which now owns this
        // vertical's eleven trades — see the Restaurant note above for why the
        // key has to match. The generic "Manufacturing / Factory" preset that
        // used to be curated here moved into the plugin as its general-factory
        // trade, so nothing was lost by going plugin-backed. The modules below
        // are only the fallback for a signup that names the industry without
        // picking a trade, and they are the common shop floor rather than any
        // one trade's chain.
        'manufacturing' => [
            'label' => 'Manufacturing',
            'icon' => 'pi pi-cog',
            'description' => 'BOMs, production runs, machines, quality control, scrap and costing — garment, textile, printing, plastic, steel, packaging, chemical and food units',
            // Trades come from the Manufacturing plugin — see PLUGIN_BACKED.
            'trades' => [],
            'modules' => ['sales', 'purchase', 'inventory', 'manufacturing', 'accounts', 'gst', 'hrms', 'assets', 'crm', 'ai'],
        ],
        // Keyed to match the Healthcare plugin, which now owns this vertical's
        // trades — see PLUGIN_BACKED and the note on food_beverage above.
        'healthcare' => [
            'label' => 'Healthcare',
            'icon' => 'pi pi-heart-fill',
            'description' => 'Medical stores, diagnostic labs, clinics, hospitals and pharma distribution',
            // Trades come from the Healthcare plugin. Note an existing install
            // keeps whatever is already in this column (the seeder never
            // overwrites), which is harmless: a plugin-declared trade wins over
            // template curation in ShopProfiles::options() either way.
            'trades' => [],
            'modules' => ['pharmacy', 'sales', 'pos', 'purchase', 'inventory', 'accounts', 'gst', 'devices', 'crm', 'ai'],
        ],
        // 'wholesale' used to be curated here. It now belongs to the Distribution
        // & Wholesale plugin below, so this row keeps only the freight-and-customs
        // side of the old pairing — which is what its label describes anyway.
        // (An existing install keeps whatever is already in its trades column; the
        // seeder never overwrites, and grouping resolves through the plugin
        // regardless — see ShopProfiles::options().)
        'logistics' => [
            'label' => 'Logistics & Freight',
            'icon' => 'pi pi-truck',
            'description' => 'Shipments, customs, fleet, dispatch planning and delivery proof',
            'trades' => ['trading'],
            'modules' => ['sales', 'purchase', 'inventory', 'logistics', 'accounts', 'gst', 'broker', 'field_sales', 'crm', 'ai'],
        ],
        // Keyed after the Distribution & Wholesale plugin that owns its trades,
        // for the same reason as food_beverage above. The fallback modules lean to
        // the general bulk trader: a signup that names "Distribution" without
        // picking a trade is a wholesaler far more often than it is a C&F agent,
        // and `field_sales` is what makes beats and collections reachable at all.
        'distribution' => [
            'label' => 'Distribution & Wholesale',
            'icon' => 'pi pi-truck',
            'description' => 'Multi-godown stock, salesman beats, party credit, outstanding and transport',
            'trades' => [],
            'modules' => [
                'distribution', 'sales', 'purchase', 'inventory', 'accounts', 'gst',
                'field_sales', 'logistics', 'broker', 'crm', 'ai',
            ],
        ],
        'construction' => [
            'label' => 'Construction',
            'icon' => 'pi pi-building-columns',
            'description' => 'Site projects, material issue, labour and plant & machinery',
            'trades' => ['construction'],
            'modules' => ['projects', 'purchase', 'inventory', 'sales', 'accounts', 'gst', 'assets', 'hrms', 'logistics', 'crm', 'ai'],
        ],
        // Keyed the same as the Agriculture plugin, which now owns this
        // vertical's trades — see the Restaurant note above for why the key has
        // to match. Trades come from the plugin (PLUGIN_BACKED below); the
        // modules here are only the fallback for a signup that names the industry
        // without picking a trade, and it leans to the dealer because that is the
        // commonest shape by far.
        'agriculture' => [
            'label' => 'Agriculture',
            'icon' => 'pi pi-sun',
            'description' => 'Agri-input licences and stock, plus dairy and poultry farm production',
            'trades' => [],
            'modules' => ['sales', 'pos', 'purchase', 'inventory', 'accounts', 'gst', 'agriculture', 'field_sales', 'logistics', 'crm', 'ai'],
        ],

        // Keyed after the Automobile plugin that owns its trades, for the same
        // reason as food_beverage above. The fallback modules lean to the
        // service centre rather than the parts counter: a signup that names
        // "Automobile" without picking a trade is overwhelmingly a garage, and
        // `service` is what makes the job card reachable at all.
        'automobile' => [
            'label' => 'Automobile',
            'icon' => 'pi pi-car',
            'description' => 'Service centres, workshops, dealerships, spare parts and tyre shops',
            'trades' => [],
            'modules' => ['automobile', 'service', 'sales', 'pos', 'purchase', 'inventory', 'accounts', 'gst', 'crm', 'ai'],
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
        'manufacturing' => 'manufacturing',
        'healthcare' => 'healthcare',
        'agriculture' => 'agriculture',
        'distribution' => 'distribution',
        'automobile' => 'automobile',
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
            /**
             * Empty counts as unset for modules, unlike everywhere else here.
             * A template with no modules at all is not a curation an operator
             * made — it falls back to the 'custom' essentials for every signup
             * that picks it — so refilling it is a repair, not an undo. Trades
             * are left on `??=`: an empty trade list is meaningful, and is
             * exactly what a plugin-backed row is supposed to hold.
             */
            if (empty($row->modules)) {
                $row->modules = $template['modules'];
            }
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

            /**
             * firstOrNew and fill-only-what-is-unset, not updateOrCreate.
             *
             * A plugin key can collide with a curated template above — it is
             * meant to, that is how a vertical takes over a row it used to share
             * (see the Manufacturing note). updateOrCreate would then *adopt* the
             * curated row by overwriting it: label, description and above all
             * `modules` blanked to the empty defaults below, silently deleting
             * the fallback module set every signup that names the industry
             * without picking a trade depends on. Which is exactly what happened
             * to Manufacturing between it being registered as a plugin and being
             * listed in PLUGIN_BACKED.
             *
             * Adopting the row is right; erasing it on the way past is not.
             */
            $row = IndustryTemplate::firstOrNew(['key' => $plugin->key()]);

            $row->label = $row->label ?: $plugin->label();
            $row->icon = $row->icon ?: $plugin->icon();
            $row->description = $row->description ?: $plugin->description();
            $row->modules ??= [];
            $row->trades ??= [];
            $row->plugin_key = $plugin->key();
            $row->is_system = true;
            $row->sort_order = $row->exists ? $row->sort_order : $sort;
            if (! $row->exists) {
                $row->is_active = true;
            }
            $row->save();
        }
    }
}
