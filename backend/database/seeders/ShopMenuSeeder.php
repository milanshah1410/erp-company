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
            ]],

            ['group' => 'Purchase', 'icon' => 'pi pi-shopping-bag', 'routes' => [
                '/purchase-orders',
                '/purchase-invoices',
                '/purchase-indents',
                '/stock-returns',
            ]],

            ['group' => 'Inventory', 'icon' => 'pi pi-box', 'routes' => [
                '/stock',
                '/stock-adjustments',
                '/stock-transfers',
                '/stock-ledger',
                '/inventory-alerts',
                '/reorder',
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
                '/whatsapp',
                '/sms',
                '/marketing-campaigns',
            ]],

            ['group' => 'Offers & Loyalty', 'icon' => 'pi pi-ticket', 'routes' => [
                '/loyalty',
                '/coupons',
                '/gift-vouchers',
            ]],

            ['group' => 'Accounts', 'icon' => 'pi pi-calculator', 'routes' => [
                '/vouchers',
                '/accounts/day-book',
                '/accounts/customer-ledger',
                '/accounts/supplier-ledger',
                '/accounts/cash-book',
                '/accounts/bank-book',
                '/accounts/trial-balance',
                '/accounts/profit-loss',
                '/accounts/balance-sheet',
                '/bank-accounts',
            ]],

            ['group' => 'Expenses', 'icon' => 'pi pi-wallet', 'routes' => [
                '/expenses',
                '/expense-categories',
            ]],

            ['group' => 'GST & Tax', 'icon' => 'pi pi-percentage', 'routes' => [
                '/gst-registrations',
                '/hsn-codes',
                '/e-invoices',
                '/e-way-bills',
                '/gst-tools',
                '/reports/gstr1',
                '/reports/gst-summary',
            ]],

            ['group' => 'Reports', 'icon' => 'pi pi-chart-bar', 'routes' => [
                '/reports/sales',
                '/reports/purchases',
                '/reports/stock-overview',
                '/reports/fast-slow',
                '/reports/receivables-ageing',
                '/reports/payables',
                '/reports/profitability',
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
     * The industry section is inserted *third* — right after Dashboard and Sales
     * — rather than appended, because it is the part of the menu that trade opens
     * every day. A jeweller checks the metal rate before anything else; burying
     * it under fourteen shared groups would be filing, not navigation.
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

        return $out;
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
}
