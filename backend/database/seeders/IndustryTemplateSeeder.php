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
     * @var array<string, array{label: string, icon: string, description: string, trades: list<string>, modules: list<string>, sort?: int}>
     */
    private const TEMPLATES = [
        // Keyed the same as the Retail plugin, which owns this vertical's
        // trades — see PLUGIN_BACKED and the note on food_beverage below for why
        // the key has to match. Grocery, garment, footwear, optical and
        // jewellery all moved out of the hand-written ShopProfiles list into
        // RetailPlugin::trades() together, because a plugin-backed template
        // reports the plugin's trades and ignores the curated column. Two of the
        // five have since gone further and become verticals of their own
        // (Grocery & Kirana, Optical & Eyewear) once they grew past what a
        // shared retail preset could describe.
        'retail' => [
            'label' => 'Retail',
            'icon' => 'pi pi-shop',
            'description' => 'Counter billing, barcode, stock and the daily cash book',
            // Trades come from the Retail plugin — see PLUGIN_BACKED.
            'trades' => [],
            // Neither `warranty` nor `garment` is in the fallback set on purpose:
            // a signup that names "Retail" without picking a trade is as likely
            // to be a kirana as a shoe shop or a showroom, and a grocer has
            // nothing to warrant and no style master to keep. The trade presets
            // switch each on for the trades that do.
            'modules' => ['sales', 'pos', 'purchase', 'inventory', 'accounts', 'gst', 'loyalty', 'devices', 'crm', 'ai'],
        ],
        /**
         * Grocery & Kirana, keyed after the plugin that owns its trade — the same
         * rule as every other plugin-backed row. It used to be one of Retail's
         * five trades and is its own vertical now, which is why the Retail row
         * above no longer mentions a kirana.
         *
         * `sort` is explicit rather than positional. The loop numbers templates
         * by position, so inserting a row in the middle gives it a number an
         * existing install has already handed to something else — a trap this
         * seeder has fallen into once before. An explicit number places it
         * between Retail and Restaurant on a fresh install and collides with
         * nothing on an old one.
         */
        'grocery' => [
            'label' => 'Grocery & Kirana',
            'icon' => 'pi pi-shopping-cart',
            'description' => 'Kirana counters and grocery stores: counter billing, batch & expiry, combo packs, loyalty and distributor credit',
            'sort' => 15,
            // Trades come from the Grocery plugin — see PLUGIN_BACKED.
            'trades' => [],
            'modules' => ['grocery', 'sales', 'pos', 'purchase', 'inventory', 'accounts', 'gst', 'loyalty', 'devices', 'crm', 'ai'],
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

        // Keyed after the Electronics plugin that owns its trades, for the same
        // reason as automobile above. The fallback modules lean to the showroom
        // rather than the electrical counter: a signup that names "Electronics"
        // without picking a trade is far more often a shop that delivers and
        // installs than one selling switches over a counter, and `service` plus
        // `logistics` are what make that shape work at all.
        'electronics' => [
            'label' => 'Electronics & Appliances',
            'icon' => 'pi pi-desktop',
            // Mobile and computer shops used to be named here. Both are now their
            // own templates, keyed after their own plugins, so naming them would
            // send a phone counter into the vertical that no longer has its trade.
            'description' => 'Electronics showrooms, appliance dealers, kitchenware and electrical goods stores',
            'trades' => [],
            'modules' => ['electronics', 'warranty', 'service', 'sales', 'pos', 'purchase', 'inventory', 'accounts', 'gst', 'logistics', 'devices', 'crm', 'ai'],
        ],

        // Keyed after the Computer & IT plugin that owns its trade, for the same
        // reason as food_beverage above. Kept apart from Electronics rather than
        // folded into it because the two sell on different things: an appliance
        // dealer's aftersales question is "will you come and install it?", and a
        // computer shop's is "is this machine on our AMC, and where is its
        // licence key?". One template would have to describe both and would end
        // up selling neither.
        'computer_it' => [
            'label' => 'Computer & IT',
            'icon' => 'pi pi-desktop',
            'description' => 'Computer and laptop shops: machines sold by serial, warranty and AMC cover, repairs, licence keys and on-site service calls',
            // Trades come from the Computer & IT plugin — see PLUGIN_BACKED.
            'trades' => [],
            'modules' => ['computer_it', 'service', 'sales', 'pos', 'purchase', 'inventory', 'accounts', 'gst', 'devices', 'crm', 'ai'],
        ],

        // Keyed after the Mobile plugin that owns its trade, for the same reason as
        // the two above. Kept apart from Electronics because the aftersales question
        // is a different one: an appliance dealer is asked "will you come and
        // install it?", and a phone counter is asked "is my screen covered, and how
        // many EMIs are left?". No `logistics` in the fallback — nothing here is
        // delivered on a van — and `loyalty` instead, because this trade lives on
        // the same customers coming back.
        'mobile' => [
            'label' => 'Mobile & Handsets',
            'icon' => 'pi pi-mobile',
            'description' => 'Mobile phone shops: handsets by IMEI, warranty and screen insurance, EMI files, part-exchange and repairs',
            // Trades come from the Mobile plugin — see PLUGIN_BACKED.
            'trades' => [],
            'modules' => ['mobile', 'warranty', 'service', 'sales', 'pos', 'purchase', 'inventory', 'accounts', 'gst', 'loyalty', 'devices', 'crm', 'ai'],
        ],

        /**
         * Keyed after the Stationery plugin that owns its trade, for the same
         * reason as the templates above. Kept out of `retail` — which is where a
         * shop selling pens over a counter would otherwise land — because the
         * buying decision is not a retail one: this trade is chosen by shops that
         * supply schools, and the thing they are shopping for is the booklist, not
         * the till. A stationery shop that only sells over a counter is well
         * served by `retail`, and should pick it.
         */
        'stationery' => [
            'label' => 'Stationery & School Supplies',
            'icon' => 'pi pi-pencil',
            'description' => 'Stationery, book and office-supply shops: school accounts, class booklists, bulk quantity pricing and term-wise supply orders',
            // Trades come from the Stationery plugin — see PLUGIN_BACKED.
            'trades' => [],
            // `logistics` because a school's order goes out on the van in phases,
            // and `devices` because a June counter cannot search for products by
            // name — it scans.
            'modules' => ['stationery', 'sales', 'pos', 'purchase', 'inventory', 'accounts', 'gst', 'logistics', 'devices', 'loyalty', 'crm', 'ai'],
            /**
             * Stated rather than positional, and chosen against the *installed*
             * numbering rather than this file's order — the two have drifted.
             * Existing rows keep whatever sort_order they were first seeded with,
             * and on an install that has run this seeder before, `other` is
             * already sitting at 110. A positional number here (135) would
             * therefore file Stationery *after* "Other", which has to stay last
             * in the picker. 105 lands before it on an existing install and still
             * leaves `other` last on a fresh one.
             */
            'sort' => 105,
        ],
        /**
         * Keyed after the Gift plugin that owns its trade. Kept out of `retail`
         * for the same kind of reason stationery is: what a gift shop is shopping
         * for is not a till — it is the wrap desk, the hamper book and the list of
         * dates its customers buy for, none of which a garment showroom wants.
         *
         * A curated row rather than leaving the seeder's plugin fallback to invent
         * one: that fallback saves a template with no modules, and a signup that
         * names an industry without picking a trade then drops to the bare
         * essentials — a gift shop landing on a workspace with no POS.
         */
        'gift' => [
            'label' => 'Gift & Novelty',
            'icon' => 'pi pi-gift',
            'description' => 'Gift shops and novelty counters: the wrap desk, hamper packs, occasion reminders, loyalty and gift vouchers',
            // Trades come from the Gift plugin — see PLUGIN_BACKED.
            'trades' => [],
            // `loyalty` carries both points and gift vouchers; `devices` is
            // barcode and label printing, without which pricing five hundred
            // small items before Diwali is done by hand.
            'modules' => ['gift', 'sales', 'pos', 'purchase', 'inventory', 'accounts', 'gst', 'loyalty', 'devices', 'crm', 'ai'],
            // Explicit, for the reason stated on the row above: 137 slots it after
            // Stationery (135) on a fresh seed and collides with nothing on an
            // existing one, where every other row is already holding a multiple of
            // ten — including `other`, which has to stay last.
            'sort' => 137,
        ],
        /**
         * Keyed after the Toys plugin that owns its trade. Kept apart from Gift
         * & Novelty, whose wrap desk and hamper book it borrows, because the two
         * are shopping for different things: a gift counter's question is "can
         * you wrap it by six?", and a toy shop's is "is this legal to sell and
         * who is it for?". One template would have to describe both and would
         * end up selling neither.
         *
         * `gift` is in the fallback module set as well as in the trade preset, so
         * a signup that names the industry without picking the trade still lands
         * on a workspace with a wrap desk — which is half of what the trade was
         * promised.
         */
        'toys' => [
            'label' => 'Toys & Games',
            'icon' => 'pi pi-star',
            'description' => 'Toy shops: age bands, safety classes and BIS certificates, gift wrapping, combo packs and counter billing',
            // Trades come from the Toys plugin — see PLUGIN_BACKED.
            'trades' => [],
            'modules' => ['toys', 'gift', 'sales', 'pos', 'purchase', 'inventory', 'accounts', 'gst', 'loyalty', 'devices', 'crm', 'ai'],
            // Explicit for the same reason as the row above: 138 slots it after
            // Gift (137) and collides with nothing on an existing install, where
            // every other row holds a multiple of ten — `other` included, which
            // has to stay last.
            'sort' => 138,
        ],
        /**
         * Keyed after the Optical plugin that owns its trade. It used to be one
         * of Retail's five and is its own vertical now — the same move Grocery
         * made — which is why the Retail row above no longer mentions eye tests.
         *
         * `warranty` is in the fallback module set as well as in the trade
         * preset: an optical workspace issues warranty cards when it hands a
         * pair over, and a signup that named the industry without picking the
         * trade would otherwise be granted cover it has no screen to read.
         *
         * `sort` explicit, like its two neighbours: 139 places it after Toys and
         * collides with nothing on an existing install, where every other row
         * holds a multiple of ten and `other` has to stay last.
         */
        'optical' => [
            'label' => 'Optical & Eyewear',
            'icon' => 'pi pi-eye',
            'description' => 'Opticians and eyewear stores: eye tests and appointments, lens and frame specifications, spectacle orders through the lab, collection and warranty',
            // Trades come from the Optical plugin — see PLUGIN_BACKED.
            'trades' => [],
            'modules' => ['optical', 'warranty', 'sales', 'pos', 'purchase', 'inventory', 'accounts', 'gst', 'loyalty', 'devices', 'crm', 'ai'],
            'sort' => 139,
        ],

        /**
         * Keyed after the Book Store plugin that owns its two trades. Kept apart
         * from Stationery, whose booklists the academic trade borrows, because
         * the two shops are asked different questions: a stationer's is "how many
         * sets for Class 5?", a bookseller's is "which edition, whose translation,
         * and can you get it?". One template would have to describe both and
         * would end up selling neither.
         *
         * `ecommerce` is in the fallback module set as well as in the trade
         * presets, because a signup that names the industry without picking a
         * trade should still land on a workspace that can take web orders — which
         * is one of the seven things this trade was promised. Whether it actually
         * gets it is the plan's decision, not this row's.
         *
         * `sort` explicit like its neighbours: 140 places it after Optical (139)
         * and collides with nothing on an existing install, where every other row
         * holds a multiple of ten and `other` has to stay last.
         */
        'books' => [
            'label' => 'Books & Periodicals',
            'icon' => 'pi pi-book',
            'description' => 'Book shops: ISBN catalogue with authors and translators, publishers and their trade terms, language sections, counter billing and online orders',
            // Trades come from the Book Store plugin — see PLUGIN_BACKED.
            'trades' => [],
            'modules' => ['books', 'sales', 'pos', 'purchase', 'inventory', 'accounts', 'gst', 'ecommerce', 'loyalty', 'devices', 'crm', 'ai'],
            'sort' => 140,
        ],
        /**
         * Keyed after the Sports plugin that owns its trade. Its own row rather
         * than a note under Retail, because a sports shop is two businesses in one
         * unit: it sells kit like a footwear shop, and it *hires* kit, which
         * nothing else in this catalogue does. A buyer choosing "Retail" would get
         * the first half and never find out the second existed.
         *
         * `service` and `warranty` are in the fallback module set as well as in
         * the trade preset, because two of the seven things this trade was
         * promised — Repair and Warranty — have no screen at all without them, and
         * a signup that names the industry without picking the trade should still
         * land on a workspace that can take a racquet in for restringing.
         *
         * `sort` explicit like its neighbours: 145 leaves room after Books (140)
         * for the verticals still landing around it, and collides with nothing on
         * an existing install where every other row holds a multiple of ten and
         * `other` has to stay last.
         */
        'sports' => [
            'label' => 'Sports & Fitness',
            'icon' => 'pi pi-flag',
            'description' => 'Sports shops: equipment hire with deposits and an overdue board, kit repair, size and colour runs, warranty and counter billing',
            // Trades come from the Sports plugin — see PLUGIN_BACKED.
            'trades' => [],
            'modules' => ['sports', 'sales', 'pos', 'purchase', 'inventory', 'accounts', 'gst', 'service', 'warranty', 'loyalty', 'devices', 'crm', 'ai'],
            'sort' => 145,
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
        'retail' => 'retail',
        'grocery' => 'grocery',
        'food_beverage' => 'food_beverage',
        'manufacturing' => 'manufacturing',
        'healthcare' => 'healthcare',
        'agriculture' => 'agriculture',
        'distribution' => 'distribution',
        'automobile' => 'automobile',
        'electronics' => 'electronics',
        'computer_it' => 'computer_it',
        'mobile' => 'mobile',
        'stationery' => 'stationery',
        'gift' => 'gift',
        'toys' => 'toys',
        'books' => 'books',
        'optical' => 'optical',
        'sports' => 'sports',
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
            // Positional by default; a template may state its own number to slot
            // between two existing rows without renumbering everything after it.
            $row->sort_order = $row->exists ? $row->sort_order : ($template['sort'] ?? $sort);
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
