<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\MenuItem;
use App\Models\ShopMenuItem;
use App\Support\ShopProfiles;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Default shop-wise menu templates (.claude/shops-modules.md).
 *
 * MenuSeeder defines *every* screen the product ships — around 240 rows across
 * every vertical we have ever built. That catalogue is the right thing to hold
 * centrally and the wrong thing to hand a kirana shop on day one: a grocer does
 * not want Embroidery Vendors, Lab Orders and Container Tracking in their
 * sidebar, and until now the only cure was an operator pruning the tree by hand,
 * one workspace at a time, in Tenants → Menu Builder.
 *
 * This seeder writes the missing middle: for each trade, the menu that trade
 * actually opens on. App\Support\ShopMenus clones it at provisioning time in
 * place of the full catalogue, so a Garment signup lands on a garment menu.
 *
 * **A template names screens, it does not define them.** Every entry below is a
 * route that must already exist in the global catalogue; the label, icon,
 * permission, plan feature and module gate are read from the catalogue row and
 * copied, so a template can never grow an entry the SPA router refuses or a
 * permission nobody holds. A route the catalogue does not have is skipped and
 * reported rather than seeded — that is how a screen renamed out from under a
 * template announces itself instead of silently vanishing.
 *
 * **Structure follows the spec.** COMMON is the "દરેક Retail Shop માટે Common
 * Modules" list — the floor every retail trade shares. Each trade below then adds
 * only its own section, exactly as the spec lists it, which is why a
 * twenty-trade file is a few hundred lines rather than a few thousand.
 *
 * **Idempotent, and deliberately non-destructive.** run() seeds only trades that
 * have no template yet, so re-running `db:seed` after an operator has curated the
 * Grocery menu leaves their work alone. Re-applying a shipped default is an
 * explicit act — reseed() below, wired to "Reset to default" on
 * /platform/shop-menus.
 *
 * Three trades in the spec have no template here because they have no trade key
 * yet: Watch Shop, Paint Shop and Plumbing Shop. A trade must exist in
 * App\Support\ShopProfiles (i.e. be declared by an industry plugin) before it can
 * be curated; until then those shops sign up as "Something else" and get the full
 * catalogue. Adding the trade to its plugin is all that is needed to make a
 * template here take effect.
 */
class ShopMenuSeeder extends Seeder
{
    public function run(): void
    {
        $catalogue = $this->catalogue();

        if ($catalogue === []) {
            $this->command?->warn('Global menu catalogue is empty — run MenuSeeder first. No shop templates seeded.');

            return;
        }

        $known = ShopProfiles::keys();
        $seeded = 0;
        $skipped = [];

        foreach (self::templates() as $trade => $sections) {
            if (! in_array($trade, $known, true)) {
                // A trade whose plugin is not registered in this install. Not an
                // error: the template simply has nothing to attach to yet.
                $skipped[] = $trade;

                continue;
            }

            if (ShopMenuItem::where('shop_profile', $trade)->exists()) {
                continue; // curated already — never stomp an operator's tree
            }

            $this->write($trade, $sections, $catalogue);
            $seeded++;
        }

        $this->command?->info("Shop menu templates: {$seeded} seeded.");

        if ($skipped !== []) {
            $this->command?->line('  Skipped (no such trade in this install): '.implode(', ', $skipped));
        }

        if ($this->missingRoutes !== []) {
            $this->command?->warn('  Routes not in the catalogue, left out: '.implode(', ', array_unique($this->missingRoutes)));
        }
    }

    /**
     * Re-apply one trade's shipped default, discarding whatever is there.
     *
     * The "Reset to default" path. Separate from run() because run() must be safe
     * to re-run on every deploy and this must not be.
     *
     * @return int rows written, or -1 when this trade ships no default
     */
    public function reseed(string $trade): int
    {
        $sections = self::templates()[$trade] ?? null;

        if ($sections === null) {
            return -1;
        }

        $catalogue = $this->catalogue();

        return DB::transaction(function () use ($trade, $sections, $catalogue): int {
            ShopMenuItem::where('shop_profile', $trade)->delete();

            return $this->write($trade, $sections, $catalogue);
        });
    }

    /** Does this trade ship a curated default at all? */
    public static function ships(string $trade): bool
    {
        return array_key_exists($trade, self::templates());
    }

    /** @return list<string> every trade this seeder ships a default for */
    public static function shippedTrades(): array
    {
        return array_keys(self::templates());
    }

    /** Routes named by a template that the catalogue does not have. @var list<string> */
    private array $missingRoutes = [];

    /**
     * The global catalogue, keyed by route — the source of every label, icon and
     * gate a template row carries.
     *
     * A route can appear more than once in the catalogue (Job Cards sits under
     * both Automobile and Electronics, pointing at /service-jobs both times).
     * First wins; they are the same screen with the same gate, and a template
     * names the screen, not the position it happened to be listed in.
     *
     * @return array<string, MenuItem>
     */
    private function catalogue(): array
    {
        $out = [];

        foreach (MenuItem::whereNull('tenant_id')->whereNotNull('route')->orderBy('sort_order')->get() as $row) {
            $out[$row->route] ??= $row;
        }

        return $out;
    }

    /**
     * Write one trade's tree.
     *
     * @param  list<array<string, mixed>>  $sections
     * @param  array<string, MenuItem>  $catalogue
     * @return int rows written
     */
    private function write(string $trade, array $sections, array $catalogue): int
    {
        $written = 0;
        $order = 0;

        foreach ($sections as $section) {
            // A bare route at the top level — Dashboard, and nothing else so far.
            if (isset($section['route'])) {
                if ($this->leaf($trade, null, $section['route'], $order, $catalogue, $section['label'] ?? null)) {
                    $order++;
                    $written++;
                }

                continue;
            }

            // A group: seeded only if at least one of its screens resolves, so a
            // trade never grows an empty heading because a module was removed.
            $resolved = array_values(array_filter(
                $section['routes'] ?? [],
                function (string $route) use ($catalogue): bool {
                    if (isset($catalogue[$route])) {
                        return true;
                    }
                    $this->missingRoutes[] = $route;

                    return false;
                },
            ));

            if ($resolved === []) {
                continue;
            }

            $group = ShopMenuItem::create([
                'shop_profile' => $trade,
                'parent_id' => null,
                'label' => $section['group'],
                'icon' => $section['icon'] ?? 'pi pi-folder',
                'route' => null,
                'sort_order' => $order++,
                'is_active' => true,
            ]);
            $written++;

            $childOrder = 0;
            foreach ($resolved as $route) {
                $this->leaf($trade, $group->id, $route, $childOrder++, $catalogue);
                $written++;
            }
        }

        return $written;
    }

    /**
     * One screen, with the catalogue's own label and gates.
     *
     * @param  array<string, MenuItem>  $catalogue
     */
    private function leaf(string $trade, ?int $parentId, string $route, int $order, array $catalogue, ?string $label = null): bool
    {
        $source = $catalogue[$route] ?? null;

        if ($source === null) {
            $this->missingRoutes[] = $route;

            return false;
        }

        ShopMenuItem::create([
            'shop_profile' => $trade,
            'parent_id' => $parentId,
            'label' => $label ?? $source->label,
            'icon' => $source->icon,
            'route' => $route,
            // The gates travel with the screen. A template that dropped them
            // would hand every cashier the Trial Balance.
            'permission' => $source->permission,
            'feature' => $source->feature,
            'module' => $source->module,
            'sort_order' => $order,
            'is_active' => true,
        ]);

        return true;
    }

    // ------------------------------------------------------------------
    // The templates themselves
    // ------------------------------------------------------------------

    /**
     * The floor every retail trade shares — the spec's "Common Modules" list,
     * mapped onto the routes the catalogue actually has.
     *
     * Ordered the way a shop's day runs rather than the way the catalogue is
     * filed: Dashboard, then the counter (Sales), then what feeds it (Purchase,
     * Inventory), then the books, then the back office. A trade that wants a
     * different order drags it in the panel — this is a starting point.
     *
     * @return list<array<string, mixed>>
     */
    private static function common(): array
    {
        return [
            ['route' => '/'],

            ['group' => 'Sales', 'icon' => 'pi pi-shopping-cart', 'routes' => [
                '/pos',
                '/sales-quotations',
                '/sales-orders',
                '/sales-returns',
                '/b2b-orders',
                '/credit-control',
            ]],

            ['group' => 'Purchase', 'icon' => 'pi pi-shopping-bag', 'routes' => [
                '/purchase-indents',
                '/rfqs',
                '/purchase-orders',
                '/purchase-invoices',
                '/gate-entries',
                '/stock-returns',
                '/vendor-ratings',
                '/brokers',
                '/brokers/ledger',
            ]],

            ['group' => 'Inventory', 'icon' => 'pi pi-box', 'routes' => [
                '/stock',
                '/stock-ledger',
                '/stock-adjustments',
                '/stock-transfers',
                '/cycle-counts',
                '/batches',
                '/serial-numbers',
                '/inventory-alerts',
                '/reorder',
                '/dead-stock',
                '/warehouses',
            ]],

            ['group' => 'Products', 'icon' => 'pi pi-tag', 'routes' => [
                '/products',
                '/categories',
                '/brands',
                '/units',
                '/price-lists',
            ]],

            ['group' => 'Barcode & Labels', 'icon' => 'pi pi-qrcode', 'routes' => [
                '/barcode-scan',
                '/label-printing',
            ]],

            ['group' => 'Customers & Suppliers', 'icon' => 'pi pi-users', 'routes' => [
                '/customers',
                '/customer-categories',
                '/suppliers',
            ]],

            ['group' => 'CRM & Marketing', 'icon' => 'pi pi-heart', 'routes' => [
                '/leads',
                '/follow-ups',
                '/referrals',
                '/crm/occasions',
                // Feedback & CSAT (retail base §6). On the shared floor rather
                // than in any one trade's section: a kirana, a footwear shop and
                // a beauty counter all want to know what their customers thought.
                '/customer-feedback',
                '/whatsapp',
                '/sms',
                '/marketing-campaigns',
                '/email-logs',
            ]],

            ['group' => 'Offers & Loyalty', 'icon' => 'pi pi-ticket', 'routes' => [
                '/loyalty',
                '/coupons',
                '/gift-vouchers',
                '/memberships',
                '/membership-plans',
            ]],

            ['group' => 'Accounts', 'icon' => 'pi pi-calculator', 'routes' => [
                '/vouchers',
                '/accounts/day-book',
                '/accounts/customer-ledger',
                '/accounts/supplier-ledger',
                '/accounts/expense-ledger',
                '/accounts/cash-book',
                '/accounts/bank-book',
                '/accounts/journal',
                '/accounts/petty-cash',
                '/accounts/trial-balance',
                '/accounts/profit-loss',
                '/accounts/balance-sheet',
                '/bank-accounts',
                '/payment-transactions',
                '/payment-gateways',
                // The file the shop's chartered accountant imports (retail base
                // §10). Last in the group because it is the month-end act that
                // follows everything above it.
                '/accounting-exports',
            ]],

            /**
             * Expenses, with the heads §8 names. Shop rent and salary are expense
             * *categories* rather than rows, but the four dedicated registers are
             * screens: a claim, a vehicle, a fuel bill and an electricity bill are
             * each approved on their own terms.
             */
            ['group' => 'Expenses & Assets', 'icon' => 'pi pi-wallet', 'routes' => [
                '/expenses',
                '/expense-categories',
                '/employee-claims',
                '/vehicle-expenses',
                '/fuel-expenses',
                '/electricity-bills',
                '/assets',
                '/asset-maintenances',
            ]],

            ['group' => 'GST & Tax', 'icon' => 'pi pi-percentage', 'routes' => [
                '/gst-registrations',
                '/hsn-codes',
                '/e-invoices',
                '/e-way-bills',
                '/gst-tools',
                '/tds-tcs-rates',
                '/reports/gstr1',
                '/reports/gstr2',
                '/reports/gst-summary',
                '/reports/gst',
                '/reports/tds-register',
                '/reports/tcs-register',
            ]],

            /**
             * Staff & Payroll (retail base §8b). Gated on `hrms`, so a one-person
             * kirana never sees it — but a shop with three counter hands and a
             * delivery boy runs its whole month here, and until now the group was
             * absent from every retail template: the module could be switched on
             * and the screens still be unreachable, because a template *is* the
             * menu rather than an addition to it.
             */
            ['group' => 'Staff & Payroll', 'icon' => 'pi pi-id-card', 'routes' => [
                '/departments',
                '/employees',
                '/attendance',
                '/biometric-devices',
                '/biometric-punches',
                '/punch-kiosk',
                '/leave-types',
                '/leave-applications',
                '/overtime',
                '/salary-structures',
                '/payroll',
                '/salaries',
                '/employee-bonuses',
                '/salary-advances',
                '/employee-loans',
                '/sales-commissions',
            ]],

            ['group' => 'Reports', 'icon' => 'pi pi-chart-bar', 'routes' => [
                '/reports/sales',
                '/reports/purchases',
                '/reports/profitability',
                '/reports/stock-overview',
                '/reports/stock-movement',
                '/reports/fast-slow',
                '/reports/valuation',
                '/reports/stock-ageing',
                '/reports/receivables-ageing',
                '/reports/payables',
                '/reports/expenses',
                '/reports/customer-ledger',
                '/reports/supplier-ledger',
            ]],

            /**
             * Devices & channels (retail base §10). The hardware a counter runs on
             * and the marketplaces it sells through — both module-gated, so a shop
             * with neither sees no group at all.
             */
            ['group' => 'Devices & Channels', 'icon' => 'pi pi-desktop', 'routes' => [
                '/devices',
                '/device-events',
                '/printer-test',
                '/ecommerce-integrations',
                '/channel-orders',
                '/channel-listings',
                '/channel-sync-logs',
            ]],

            ['group' => 'AI', 'icon' => 'pi pi-sparkles', 'routes' => [
                '/ai-insights',
                '/ai-assistant',
            ]],

            ['group' => 'Administration', 'icon' => 'pi pi-cog', 'routes' => [
                '/users',
                '/roles',
                '/companies',
                '/branches',
                // The workspace's own plan and invoices. Its own row rather than a
                // corner of Settings: it is the one screen an owner opens that
                // nobody else in the shop should.
                '/billing',
                '/custom-fields',
                '/workflows',
                '/audit-logs',
                '/system-tools',
                '/settings',
            ]],
        ];
    }

    /**
     * The floor for trades with no counter — `.claude/shops/_base-factory.md`.
     *
     * The retail floor **minus the counter** and **plus production**, which is
     * the whole of that file's opening claim made real:
     *
     *  - **POS is gone.** Nobody walks in; the order arrives as a purchase order.
     *    A factory that opens its day looking at a till has the wrong menu.
     *  - **Offers & Loyalty is gone.** There is no consumer to reward.
     *  - **Barcode & Labels stops being a top-level group** and folds into
     *    Products. A factory still prints labels; it does not do it hourly.
     *  - **Purchase leads Sales.** For a works, the day starts with what is
     *    coming in, not what went out — raw material against open orders is the
     *    question, and the order book is the answer to it, not the other way round.
     *  - **Inventory is top-level**, not a sub-group of the counter's world.
     *  - **HR & Payroll is not optional.** Piece-rate and shift labour are a cost
     *    of goods here, not an admin chore, so it sits with the operating groups
     *    rather than down beside Settings.
     *
     * @return list<array<string, mixed>>
     */
    private static function factoryCommon(): array
    {
        return [
            ['route' => '/'],

            // Purchase first — see the note above.
            ['group' => 'Purchase', 'icon' => 'pi pi-shopping-bag', 'routes' => [
                '/purchase-indents',
                '/rfqs',
                '/purchase-orders',
                '/purchase-invoices',
                '/gate-entries',
                '/stock-returns',
                '/vendor-ratings',
            ]],

            // ← the trade's own production group is spliced in here (index 2)

            ['group' => 'Sales', 'icon' => 'pi pi-shopping-cart', 'routes' => [
                '/sales-quotations',
                '/sales-orders',
                '/sales-returns',
                '/b2b-orders',
            ]],

            ['group' => 'Inventory', 'icon' => 'pi pi-box', 'routes' => [
                '/stock',
                '/stock-ledger',
                '/stock-adjustments',
                '/stock-transfers',
                '/batches',
                '/serial-numbers',
                '/cycle-counts',
                '/inventory-alerts',
                '/reorder',
                '/dead-stock',
                '/warehouses',
            ]],

            // Barcode & labels live here now rather than in a group of their own.
            ['group' => 'Products', 'icon' => 'pi pi-tag', 'routes' => [
                '/products',
                '/categories',
                '/brands',
                '/units',
                '/price-lists',
                '/barcode-scan',
                '/label-printing',
            ]],

            /**
             * Parties, including the overseas ones. The base file's §6 puts the
             * Import & Export *masters* under Masters → Import & Export, and this
             * is that group: a works that exports has foreign buyers, ports and
             * customs agents in the same book as its domestic accounts, and
             * splitting them across two headings is a distinction only the
             * software cares about. All of them carry the `logistics` gate they
             * shipped with, so a works that never exports sees none of it.
             */
            ['group' => 'Customers & Suppliers', 'icon' => 'pi pi-users', 'routes' => [
                '/customers',
                '/customer-categories',
                '/suppliers',
                '/overseas-customers',
                '/overseas-suppliers',
                '/countries',
                '/ports',
                '/shipping-lines',
                '/airlines',
                '/freight-forwarders',
                '/cha-agents',
                '/transporters',
            ]],

            /**
             * CRM, trimmed. Leads and follow-ups are how a works chases an
             * enquiry, and feedback is how it hears about a rejected lot — but
             * the consumer-marketing half of the retail group (campaigns to a
             * mailing list) is not a factory's tool, so only the messaging a
             * buyer actually gets is kept.
             */
            ['group' => 'CRM', 'icon' => 'pi pi-heart', 'routes' => [
                '/leads',
                '/follow-ups',
                '/customer-feedback',
                '/whatsapp',
                '/sms',
            ]],

            /**
             * Promoted: labour is a cost of goods here, so this is an operating
             * group rather than an admin one — and it carries the whole of the
             * base file's §7, biometric punches and the three money-to-staff
             * registers included. A works that runs shifts settles advances and
             * loans against them every month; leaving those in a back office the
             * menu never opens is how they get settled on paper instead.
             */
            ['group' => 'People & Payroll', 'icon' => 'pi pi-id-card', 'routes' => [
                '/departments',
                '/employees',
                '/attendance',
                '/biometric-devices',
                '/biometric-punches',
                '/punch-kiosk',
                '/leave-types',
                '/leave-applications',
                '/overtime',
                '/salary-structures',
                '/payroll',
                '/salaries',
                '/employee-bonuses',
                '/salary-advances',
                '/employee-loans',
            ]],

            /**
             * Logistics, both halves of the base file's §6: the van fleet, and the
             * import/export shipment desk that sits under the same `logistics`
             * module. A works that imports raw material lives on the ETA board —
             * omitting it left the single most-watched screen of an importing
             * factory unreachable.
             */
            ['group' => 'Logistics', 'icon' => 'pi pi-truck', 'routes' => [
                '/vehicles',
                '/drivers',
                '/delivery-routes',
                '/dispatch-plans',
                '/proof-of-delivery',
                '/transport-charges',
                '/import-export',
                '/import-export/import-shipments',
                '/import-export/export-shipments',
                '/import-export/timeline',
                '/import-export/eta-etd',
                '/import-export/documents',
                '/import-export/customs',
                '/import-export/payments',
                '/import-export/containers',
                '/import-export/profit',
                '/import-export/expenses',
            ]],

            // "As the retail base" (§8) — the same finance floor, so petty cash,
            // the collection links and the party ledgers are all here.
            ['group' => 'Accounts', 'icon' => 'pi pi-calculator', 'routes' => [
                '/vouchers',
                '/accounts/day-book',
                '/accounts/customer-ledger',
                '/accounts/supplier-ledger',
                '/accounts/expense-ledger',
                '/accounts/cash-book',
                '/accounts/bank-book',
                '/accounts/journal',
                '/accounts/petty-cash',
                '/accounts/trial-balance',
                '/accounts/profit-loss',
                '/accounts/balance-sheet',
                '/bank-accounts',
                '/payment-transactions',
                '/payment-gateways',
                '/accounting-exports',
            ]],

            /**
             * Expenses, with the factory heads the base file names — plant
             * maintenance, power and fuel. A shop never opens these; a works
             * reconciles them monthly, and they are the difference between a
             * costing that is right and one that is only nearly right.
             *
             * Employee claims and vehicle expenses come from the retail base's
             * §8, which this section inherits wholesale.
             */
            ['group' => 'Expenses & Assets', 'icon' => 'pi pi-wallet', 'routes' => [
                '/expenses',
                '/expense-categories',
                '/employee-claims',
                '/vehicle-expenses',
                '/fuel-expenses',
                '/electricity-bills',
                '/factory-maintenances',
                '/assets',
                '/asset-maintenances',
            ]],

            ['group' => 'GST & Tax', 'icon' => 'pi pi-percentage', 'routes' => [
                '/gst-registrations',
                '/hsn-codes',
                '/e-invoices',
                '/e-way-bills',
                '/gst-tools',
                '/tds-tcs-rates',
                '/reports/gstr1',
                '/reports/gst-summary',
            ]],

            /**
             * Reports, led by the production cuts. `/reports/production-cost` is
             * the one a factory owner actually opens — what a finished good cost
             * to make — and putting the shared sales report above it would bury
             * the answer under the question.
             */
            /**
             * Reports, led by the production cuts. `/reports/production-cost` is
             * the one a factory owner actually opens — what a finished good cost
             * to make — and putting the shared sales report above it would bury
             * the answer under the question.
             *
             * The three chain reports (printing, tailor, embroidery) are the base
             * file's §9 and are listed for every trade rather than only the ones
             * that obviously run them: each carries its own module gate, so a
             * moulding shop simply never sees them, and a garment unit that adds
             * embroidery next season does not need its menu re-cut.
             */
            ['group' => 'Reports', 'icon' => 'pi pi-chart-bar', 'routes' => [
                '/reports/manufacturing',
                '/reports/production-cost',
                '/reports/printing',
                '/reports/tailor',
                '/reports/embroidery',
                '/reports/purchases',
                '/reports/stock-overview',
                '/reports/stock-movement',
                '/reports/valuation',
                '/reports/stock-ageing',
                '/reports/sales',
                '/reports/profitability',
                '/reports/expenses',
                '/reports/payables',
                '/reports/receivables-ageing',
            ]],

            ['group' => 'AI', 'icon' => 'pi pi-sparkles', 'routes' => [
                '/ai-insights',
                '/ai-assistant',
            ]],

            ['group' => 'Administration', 'icon' => 'pi pi-cog', 'routes' => [
                '/users',
                '/roles',
                '/companies',
                '/branches',
                // The workspace's own plan and invoices. Its own row rather than a
                // corner of Settings: it is the one screen an owner opens that
                // nobody else in the shop should.
                '/billing',
                '/custom-fields',
                '/workflows',
                '/audit-logs',
                '/system-tools',
                '/settings',
            ]],
        ];
    }

    /**
     * Every trade's template: the common floor plus that trade's own section.
     *
     * Two floors, not one: a counter trade gets common() and a works gets
     * factoryCommon() (`.claude/shops/_base-factory.md`). Which one a trade uses
     * is decided by whether it appears in factorySections() — the same place its
     * production group is declared — so a trade can never end up with a shop-floor
     * menu group sitting on top of a POS-led floor.
     *
     * The industry section is inserted *third* — right after Dashboard and the
     * group that leads that floor — rather than appended, because it is the part
     * of the menu that trade opens every day. A jeweller checks the metal rate
     * before anything else; burying it under fourteen shared groups would be
     * filing, not navigation.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private static function templates(): array
    {
        $out = [];

        foreach (self::industrySections() as $trade => $section) {
            $common = self::common();
            // After Dashboard + Sales.
            array_splice($common, 2, 0, [$section]);
            $out[$trade] = $common;
        }

        foreach (self::factorySections() as $trade => $declared) {
            $common = self::factoryCommon();
            /**
             * A factory trade may declare one group or several. Ten of the eleven
             * want one; the general `manufacturing` preset splits Production from
             * Shop Floor exactly as the base file's §2 and §3 do, and a mechanism
             * that only ever spliced a single section could not express that.
             *
             * Normalised here rather than forcing all eleven to wrap themselves in
             * an outer array: a declaration with a `group` key is one section, and
             * anything else is already a list of them.
             *
             * Spliced after Dashboard + Purchase — the group that leads a works' floor.
             */
            $sections = isset($declared['group']) ? [$declared] : $declared;
            array_splice($common, 2, 0, $sections);
            $out[$trade] = $common;
        }

        return $out;
    }

    /** Trades that run the factory floor rather than the counter one. */
    public static function isFactoryTrade(string $trade): bool
    {
        return array_key_exists($trade, self::factorySections());
    }

    /**
     * The one section that makes a trade's menu that trade's, straight from the
     * spec's per-shop list.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function industrySections(): array
    {
        return [
            // 1. 👕 Garment Shop
            'garment' => ['group' => 'Garment', 'icon' => 'pi pi-shopping-bag', 'routes' => [
                '/garment/styles',
                '/garment/size-sets',
                '/garment/matrix',
                '/garment/colours',
                '/garment/fabrics',
                '/garment/trials',
                '/tailor-alterations',
                '/tailors',
                '/tailor-works/pending',
                '/tailor-works/completed',
                '/tailor-payments',
            ]],

            // 2. 👟 Footwear Shop — the size × colour grid it shares with garment,
            // plus the warranty/exchange desk the spec asks for.
            'footwear' => ['group' => 'Footwear', 'icon' => 'pi pi-directions', 'routes' => [
                '/garment/size-sets',
                '/garment/matrix',
                '/garment/colours',
                '/warranties',
                '/warranties/claims',
            ]],

            // 3. 📱 Mobile Shop
            'mobile_shop' => ['group' => 'Mobile', 'icon' => 'pi pi-mobile', 'routes' => [
                '/mobile/handsets',
                '/serial-numbers',
                '/warranties',
                '/warranties/claims',
                '/mobile/insurance',
                '/mobile/emi',
                '/mobile/buybacks',
                '/service-jobs',
            ]],

            // 4. 💻 Computer / Laptop Shop
            'computer_shop' => ['group' => 'Computer & IT', 'icon' => 'pi pi-desktop', 'routes' => [
                '/serial-numbers',
                '/computer-it/assets',
                '/computer-it/warranties',
                '/computer-it/licence-keys',
                '/computer-it/service-calls',
                '/service-jobs',
                '/service-contracts',
            ]],

            // 5. 📺 Electronics Shop
            'electronics_shop' => ['group' => 'Electronics', 'icon' => 'pi pi-desktop', 'routes' => [
                '/electronics/units',
                '/electronics/requests',
                '/serial-numbers',
                '/warranties',
                '/warranties/claims',
                '/service-jobs',
                '/service-contracts',
                '/delivery-routes',
                '/proof-of-delivery',
            ]],

            // 6. 🛒 Grocery / Kirana
            'grocery' => ['group' => 'Kirana', 'icon' => 'pi pi-shopping-cart', 'routes' => [
                '/batches',
                '/grocery/combo-packs',
                '/grocery/supplier-credit',
                '/credit-control',
                '/pos-counters',
                '/pos-counters/sessions',
                '/devices',
            ]],

            // 7. 🏬 Supermarket
            'supermarket' => ['group' => 'Supermarket', 'icon' => 'pi pi-building', 'routes' => [
                '/pos-counters',
                '/pos-counters/sessions',
                '/supermarket/self-checkout',
                '/supermarket/shelves',
                '/supermarket/planogram',
                '/supermarket/replenishment',
                '/memberships',
                '/membership-plans',
                '/batches',
            ]],

            // 8. 🎁 Gift Shop
            'gift_shop' => ['group' => 'Gift', 'icon' => 'pi pi-gift', 'routes' => [
                '/gift/packs',
                '/gift/wraps',
                '/gift/wrap-options',
                '/gift/occasions',
                '/crm/occasions',
            ]],

            // 9. 🧸 Toy Shop — its own age/safety register, with wrapping delegated
            // to the gift module exactly as the Toys plugin does.
            'toy_shop' => ['group' => 'Toys', 'icon' => 'pi pi-star', 'routes' => [
                '/toys/age-grades',
                '/toys/safety-classes',
                '/toys/certificates',
                '/gift/packs',
                '/gift/wraps',
            ]],

            // 10. 📝 Stationery Shop
            'stationery_shop' => ['group' => 'Stationery', 'icon' => 'pi pi-pencil', 'routes' => [
                '/stationery/institutions',
                '/stationery/class-kits',
                '/stationery/school-orders',
                '/stationery/season-demand',
            ]],

            // 11. 📚 Book Store
            'book_store' => ['group' => 'Books', 'icon' => 'pi pi-book', 'routes' => [
                '/books/titles',
                '/books/authors',
                '/books/publishers',
                '/books/languages',
                '/channel-orders',
                '/ecommerce-integrations',
            ]],

            'academic_bookshop' => ['group' => 'Books', 'icon' => 'pi pi-book', 'routes' => [
                '/books/titles',
                '/books/authors',
                '/books/publishers',
                '/books/languages',
                '/stationery/institutions',
                '/stationery/school-orders',
            ]],

            // 12. 🏏 Sports Shop — the hire desk is the part that is not core retail.
            'sports_shop' => ['group' => 'Sports & Fitness', 'icon' => 'pi pi-flag', 'routes' => [
                '/sports/fleet',
                '/sports/rentals',
                '/sports/utilisation',
                '/warranties',
                '/service-jobs',
            ]],

            // 13. 💄 Cosmetic Store
            'cosmetic' => ['group' => 'Beauty & Personal Care', 'icon' => 'pi pi-heart-fill', 'routes' => [
                '/cosmetics/shades',
                '/cosmetics/shade-card',
                '/cosmetics/testers',
                '/cosmetics/shelf-life',
                '/batches',
                '/memberships',
                '/membership-plans',
            ]],

            // 14. 💍 Jewellery Shop — the rate card is the first screen of the day.
            'jewellery' => ['group' => 'Jewellery', 'icon' => 'pi pi-star-fill', 'routes' => [
                '/metal-rates',
                '/old-gold',
            ]],

            // 15. 👓 Optical Shop
            'optical' => ['group' => 'Optical', 'icon' => 'pi pi-eye', 'routes' => [
                '/optical-orders',
                '/eye-prescriptions',
                '/prescriptions',
                '/optical-appointments',
                '/optical-delivery',
                '/warranties',
            ]],

            // 17. 🔩 Hardware Shop — bundle stock and the transport desk that moves it.
            'hardware_wholesale' => ['group' => 'Hardware & Transport', 'icon' => 'pi pi-wrench', 'routes' => [
                '/credit-control',
                '/transporters',
                '/transport-charges',
                '/vehicles',
                '/delivery-routes',
                '/proof-of-delivery',
            ]],

            // 19. ⚡ Electrical Shop
            'electrical_shop' => ['group' => 'Electrical', 'icon' => 'pi pi-bolt', 'routes' => [
                '/serial-numbers',
                '/warranties',
                '/warranties/claims',
                '/service-jobs',
            ]],

            // 21. 🍽️ Kitchenware / Home Appliances
            'home_appliance' => ['group' => 'Appliances', 'icon' => 'pi pi-home', 'routes' => [
                '/serial-numbers',
                '/warranties',
                '/warranties/claims',
                '/service-jobs',
                '/service-contracts',
                '/delivery-routes',
                '/proof-of-delivery',
            ]],
        ];
    }

    /**
     * The eleven trades that run the factory floor, and the one group that makes
     * each of them itself — straight from their own files in `.claude/shops/`,
     * each of which declared `Shipped menu template: ⬜ not written yet`.
     *
     * Two rules shape every list below, and both come from `_base-factory.md`:
     *
     *  1. **Order is what that trade opens first**, not catalogue order. A
     *     printing press opens the colour job before the manufacturing order; a
     *     textile mill opens the roll. Putting `/manufacturing-orders` at the top
     *     of all eleven would be filing, not navigation.
     *  2. **Most trades get one group, not the base file's §2 + §3.** A planner
     *     and a supervisor are two people in a large works and the same person in
     *     most of these, and splitting six rows across two headings costs the
     *     small unit a click on every one of them. The split survives where it
     *     earns its keep — the general `manufacturing` preset, which is what a
     *     works big enough to employ both signs up as.
     *
     * Each trade therefore declares a *list* of groups. Rows whose module a tenant
     * has not bought are hidden at render time by the usual gate, so naming a
     * Professional screen here costs a Business workspace nothing.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private static function factorySections(): array
    {
        return [
            /**
             * 1. 🏭 Manufacturing / Factory (general) — the trade every other
             * preset specialises, and the only one that keeps the base file's
             * Production / Shop Floor split.
             *
             * Production is what a planner opens once a day: what are we making,
             * out of what, and what is out at a vendor. Shop Floor is what a
             * supervisor lives in for a shift: which machine, did it pass, what
             * went in the bin. Two jobs, two groups — and this is the preset a
             * works with both signs up as.
             */
            'manufacturing' => [
                ['group' => 'Production', 'icon' => 'pi pi-cog', 'routes' => [
                    '/boms',
                    '/manufacturing-orders',
                    '/job-works',
                    '/reports/production-cost',
                ]],
                ['group' => 'Shop Floor', 'icon' => 'pi pi-wrench', 'routes' => [
                    '/machines',
                    '/machines/utilisation',
                    '/quality-inspections',
                    '/production-scraps',
                ]],
            ],

            /**
             * 2. 🧵 Garment / Apparel Unit — cut, stitch, embroider, finish. The
             * chain is the menu: fabric in, tailors, embroidery vendors, out.
             *
             * Two groups, because this is the one trade that runs *three* of the
             * base file's §4 chains at once and a single heading of twenty-three
             * rows is not a menu. Production is the order and the cloth; the
             * outwork group is everybody the work goes out to.
             *
             * The tailoring chain is listed **complete** — rate cards and piece
             * rates included — because they are its masters, not extras: a unit
             * that can issue tailor work but cannot define what a rate is has a
             * chain with its first link missing. Same for the embroidery half:
             * dispatch without receipt and payment is a book that only opens.
             */
            'garment_manufacturing' => [
                ['group' => 'Garment Unit', 'icon' => 'pi pi-scissors', 'routes' => [
                    '/manufacturing-orders',
                    '/boms',
                    '/fabric-rolls',
                    '/fabric-cuttings',
                    '/job-works',
                    '/quality-inspections',
                    '/production-scraps',
                ]],
                ['group' => 'Tailoring & Embroidery', 'icon' => 'pi pi-user-edit', 'routes' => [
                    '/tailors',
                    '/contractors',
                    '/rate-cards',
                    '/piece-rates',
                    '/tailor-works/pending',
                    '/tailor-works/completed',
                    '/tailor-alterations',
                    '/tailor-bonuses',
                    '/tailor-performance',
                    '/tailor-payments',
                    '/tailors/ledger',
                    '/embroidery-vendors',
                    '/embroidery-works/pending',
                    '/embroidery-works/received',
                    '/embroidery-payments',
                    '/embroidery-vendors/ledger',
                ]],
            ],

            // 3. 🧶 Textile Mill — the roll leads. Everything a mill argues about
            // (shrinkage, inspection) hangs off a specific roll.
            'textile_mill' => ['group' => 'Textile Mill', 'icon' => 'pi pi-bookmark', 'routes' => [
                '/fabric-rolls',
                '/fabric-inspections',
                '/shrinkage-records',
                '/fabric-cuttings',
                '/manufacturing-orders',
                '/boms',
                '/machines',
                '/machines/utilisation',
                '/quality-inspections',
                '/production-scraps',
            ]],

            // 4. 🪡 Embroidery Unit — a vendor book first: this trade's work is
            // mostly out of the building, and the question is whose hands it is in.
            'embroidery_unit' => ['group' => 'Embroidery', 'icon' => 'pi pi-asterisk', 'routes' => [
                '/embroidery-vendors',
                '/embroidery-works/pending',
                '/embroidery-works/received',
                '/embroidery-payments',
                '/embroidery-vendors/ledger',
                '/job-works',
                '/manufacturing-orders',
                '/machines',
                '/quality-inspections',
                '/production-scraps',
                '/reports/embroidery',
            ]],

            // 5. 🖨️ Printing Press — the colour job is the day's work, so it
            // leads the manufacturing order it is booked against.
            'printing_press' => ['group' => 'Printing', 'icon' => 'pi pi-print', 'routes' => [
                '/colour-printings',
                '/manufacturing-orders',
                '/boms',
                '/job-works',
                '/machines',
                '/machines/utilisation',
                '/quality-inspections',
                '/production-scraps',
                '/reports/printing',
            ]],

            // 6. 📦 Packaging / Corrugation — printing plus a press floor.
            'packaging' => ['group' => 'Packaging', 'icon' => 'pi pi-inbox', 'routes' => [
                '/manufacturing-orders',
                '/boms',
                '/colour-printings',
                '/machines',
                '/machines/utilisation',
                '/quality-inspections',
                '/production-scraps',
            ]],

            // 7. 🧴 Plastic / Moulding — machine-led: a moulding shop's output is
            // a function of which machine ran and for how long.
            'plastic' => ['group' => 'Moulding', 'icon' => 'pi pi-slack', 'routes' => [
                '/manufacturing-orders',
                '/boms',
                '/machines',
                '/machines/utilisation',
                '/quality-inspections',
                '/production-scraps',
            ]],

            // 8. ⚙️ Steel / Fabrication — job-work and project heavy: fabrication
            // is usually sold against a site, not off a shelf.
            'steel' => ['group' => 'Fabrication', 'icon' => 'pi pi-wrench', 'routes' => [
                '/manufacturing-orders',
                '/boms',
                '/job-works',
                '/machines',
                '/quality-inspections',
                '/production-scraps',
                '/projects',
            ]],

            // 9. 🧪 Chemical / Paints — batch-led. A chemical works is asked
            // "which batch?" about everything, including by a regulator.
            'chemical' => ['group' => 'Chemical', 'icon' => 'pi pi-filter', 'routes' => [
                '/manufacturing-orders',
                '/boms',
                '/batches',
                '/quality-inspections',
                '/production-scraps',
                '/machines',
            ]],

            // 10. 🥫 Food Manufacturing — the recipe sits beside the BOM on
            // purpose: a food producer thinks in recipes and costs in BOMs, and
            // both are true at once.
            'food_manufacturing' => ['group' => 'Food Processing', 'icon' => 'pi pi-apple', 'routes' => [
                '/manufacturing-orders',
                '/boms',
                '/restaurant/recipes',
                '/batches',
                '/quality-inspections',
                '/production-scraps',
                '/machines',
            ]],

            // 11. 🪑 Furniture — made to order and delivered: a project, a job
            // card for the fitting, and a van at the end of it.
            'furniture' => ['group' => 'Furniture Unit', 'icon' => 'pi pi-inbox', 'routes' => [
                '/manufacturing-orders',
                '/boms',
                '/job-works',
                '/machines',
                '/quality-inspections',
                '/production-scraps',
                '/projects',
                '/service-jobs',
                '/delivery-routes',
                '/proof-of-delivery',
            ]],
        ];
    }
}
