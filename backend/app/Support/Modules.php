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
 * The tagged half of the same "Core ERP" list lives in self::CORE below.
 */
final class Modules
{
    /**
     * The Core ERP set — every workspace gets these, whatever it sells and
     * whatever it pays (`.claude/Enterprise_SaaS_ERP_Industry_Module_Mapping.md`
     * §"Core ERP"). A trade preset adds its *industry* modules on top; it never
     * has to restate these, and nothing can take them away.
     *
     * The rest of that Core ERP list — Dashboard, Customers, Suppliers, Products,
     * Warehouse, Reports, Users, Roles & Permissions, Notifications — needs no key
     * here: those menu rows carry no module tag at all, so they were never
     * hideable in the first place. What is listed below is only the part of core
     * that *used* to be a switch:
     *
     *  - sales / purchase / inventory / accounts — the transactional spine. A
     *    workspace without them is not a smaller ERP, it is an empty one.
     *  - crm — every trade chases a customer; follow-ups, WhatsApp and SMS are the
     *    same job whether the thing being sold is grain or a shipment.
     *  - ai — the assistant answers over whatever the workspace already has, so it
     *    is as useful to a kirana counter as to a factory.
     *
     * Enforced at three points that must agree: Plan::allowedModules() (so no tier
     * can price core out), EnabledModules::forTenant() (so no stored selection or
     * downgrade can drop it) and TenantAccessController (so no operator can revoke
     * it). Presets merge it via ShopProfiles::all().
     *
     * Every key must exist in CATALOGUE.
     *
     * @var list<string>
     */
    public const CORE = ['sales', 'crm', 'purchase', 'inventory', 'accounts', 'ai'];

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
        // Separate from 'textile' and 'tailoring' on purpose: those two are the
        // mill and the workroom — fabric rolls, shrinkage, piece-rate work
        // orders — and a shop that only *sells* garments runs neither. What this
        // one holds is the counter's side of apparel: the style a buyer asks for
        // by design number, the fabric it is cut from, the size × colour grid
        // that turns one style into forty sellable SKUs, and the trial room.
        'garment' => [
            'label' => 'Garment & Apparel',
            'icon' => 'pi pi-shopping-bag',
            'description' => 'Style master, fabric register, size & colour matrix and the trial room',
        ],
        /**
         * Separate from `pos`, which is the till itself and is shared by every
         * counter trade. What is peculiar to a self-service store — the shelf a
         * customer picks off, the planogram that says what belongs on it, and
         * the unattended lane — is this one. A kirana with one till and no
         * aisles runs `pos` alone and never sees a facing count.
         *
         * The multi-counter register is deliberately *not* here: see the
         * PosCounter model for why cash-up belongs to `pos`.
         */
        'supermarket' => [
            'label' => 'Supermarket',
            'icon' => 'pi pi-shopping-cart',
            'description' => 'Shelf & planogram management, aisle replenishment and unattended self-checkout lanes',
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
            'description' => 'Appointments, eye powers, lens & frame specifications, spectacle orders and the collection desk',
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
        // Separate from 'service' for the same reason as 'automobile': the
        // job-card lifecycle a repair bench runs on is the Service module and is
        // shared with every repair trade. What is peculiar to computers — the
        // install base by serial, cover that decides whether an engineer
        // travels, licence keys and the on-site call book — is this one.
        'computer_it' => [
            'label' => 'Computer & IT',
            'icon' => 'pi pi-desktop',
            'description' => 'Installed machines by serial, warranty & AMC cover, software licence keys and the on-site call book',
        ],
        // Separate from 'service' on the same reasoning as 'automobile': the
        // job-card lifecycle is the Service module and is shared with every
        // repair trade. What is peculiar to goods with a number stamped on them
        // — the serial/IMEI register, warranty by scope as well as by date, and
        // the installation desk — is this one, so an electrical counter with no
        // workshop can run it alone and a garage never sees an IMEI field.
        'electronics' => [
            'label' => 'Electronics & Appliances',
            'icon' => 'pi pi-desktop',
            'description' => 'Serial & IMEI register, warranty by parts/labour scope, installation and service requests',
        ],
        /**
         * Separate from `electronics`, whose unit register is otherwise the same
         * shape. Three of the four things a phone counter lives on do not exist in
         * that trade at all: nobody insures a television against being dropped,
         * nobody part-exchanges a ceiling fan, and an appliance dealer's EMI is a
         * card swipe rather than a file the shop is asked about for a year
         * afterwards. One combined module would have put a screen-insurance book
         * and a used-handset buy-price grid in front of every electrical counter
         * that only wanted serial-backed warranty.
         */
        'mobile' => [
            'label' => 'Mobile & Handsets',
            'icon' => 'pi pi-mobile',
            'description' => 'IMEI register, screen & theft insurance, EMI files and part-exchange valuations',
        ],
        /**
         * Separate from `pos`, which is the counter every retail trade bills at.
         * What is peculiar to a grocery counter is not how the bill is made but
         * what is behind it: a barcode that is three things off the shelf at one
         * price, and a ledger of what the shop owes its distributors rather than
         * what its customers owe the shop. An appliance dealer bills at a counter
         * too and wants neither.
         */
        'grocery' => [
            'label' => 'Grocery & Kirana',
            'icon' => 'pi pi-shopping-cart',
            'description' => 'Combo packs billed as one scan, and distributor credit — limits, terms and what has to be paid today',
        ],
        /**
         * Separate from `sales`, which already sells to anyone with a ledger
         * account. What is peculiar to a stationery shop is that for ten weeks a
         * year its customer is an *institution* that does not buy anything: a
         * school publishes a class list, appoints a supplier, and sends four
         * hundred parents to fetch it one at a time. Nothing in the sales module
         * can hold "Class 5 at Sunrise, session 2026-27, these twenty-two items",
         * and every trade that does not supply schools would rather not see it.
         *
         * Deliberately narrow. Bulk quantity pricing is `price_lists` and the
         * school's order is an ordinary sales order — this module is the two
         * registers underneath them, not a second way to sell.
         */
        'stationery' => [
            'label' => 'Stationery & School Supply',
            'icon' => 'pi pi-pencil',
            'description' => 'School & institution accounts, class booklists, bulk quantity pricing and term-wise supply orders',
        ],
        /**
         * Separate from `pos` and from `grocery`, both of which bill at a counter.
         * What is peculiar to a gift shop is that nobody buys anything for
         * themselves: it has to be wrapped (a job with a queue, a promised time and
         * material off the shelf), it is often a hamper (a physical article
         * assembled out of other stock), and it is bought for a date belonging to
         * somebody who is not the customer.
         *
         * Note the deliberate difference from `grocery`'s combo: that one is a
         * multipack billed as a single scan at a bundle price. A hamper is
         * *assembled* — twenty built on Tuesday is twenty new articles on the shelf
         * and twenty fewer of everything inside them.
         *
         * Gift vouchers are not here. They are core's Loyalty & Offers feature,
         * because a supermarket and a garment showroom sell them too.
         */
        'gift' => [
            'label' => 'Gift & Novelty',
            'icon' => 'pi pi-gift',
            'description' => 'Gift wrap desk with its queue and material, assembled hamper packs, and occasion reminders with lead time',
        ],
        /**
         * Separate from `gift`, which the toy-shop preset also ticks and which
         * carries this trade's wrapping and its packs. What is peculiar to toys
         * is not how they are sold but what they are: goods for someone who is
         * not in the shop, and regulated because of it. An age band, the hazard
         * class behind it and the BIS licence that decides whether an article may
         * legally be sold have no meaning at a gift counter selling mugs, and a
         * gift shop asked to grade its range by age would rightly ask why.
         *
         * Deliberately narrow. It is three registers and a counter check — not a
         * second catalogue, not a second till.
         */
        'toys' => [
            'label' => 'Toys & Games',
            'icon' => 'pi pi-star',
            'description' => 'Age bands, safety classes with their hazards, and BIS certificate tracking that stops the sale of an uncertified toy',
        ],
        /**
         * Separate from every other retail module because of one structural
         * fact: a book shop does not name its own stock. The article arrives
         * with a worldwide identity (the ISBN), a price printed on the cover by
         * somebody else, and a set of people on the front that customers ask for
         * by name — none of which an SKU, a brand or a description can carry.
         *
         * The ISBN also *is* the barcode, which is why this module makes the
         * core till scannable without touching it, and why the number is
         * check-summed on the way in: one wrong digit is a different book that
         * also exists, and the shop finds out three weeks later.
         *
         * Deliberately narrow. Genres are core `categories`, barcode labels are
         * `devices`, and online orders are `ecommerce` — this is four registers
         * and the ISBN arithmetic behind them, not a second catalogue.
         */
        'books' => [
            'label' => 'Books & Periodicals',
            'icon' => 'pi pi-book',
            'description' => 'ISBN-checked titles, authors and translators, publishers with their trade discount and sale-or-return terms, and language sections',
        ],
        /**
         * Separate from `pos`, which is the counter every retail trade bills at,
         * and from `inventory`, which already dates a batch. Two things are
         * peculiar to a beauty counter and neither has a home anywhere else.
         *
         * A **shade** is not a colour: it is an identity a customer knows by
         * number and will not substitute, carrying the family, finish, undertone
         * and depth that decide whether it suits them, and a range is forty of
         * them that never sell evenly. A garment shop's colour list cannot answer
         * "which four of the forty are we out of".
         *
         * And make-up has a **second clock**. Core knows the printed expiry; it
         * does not know that a mascara dies six months after the seal is broken,
         * that the units getting opened are the testers on the counter, or that
         * selling a foundation past its date is an offence rather than a markdown
         * decision. A hardware shop needs none of that and would rightly ask why
         * its till was arguing about dates.
         *
         * Deliberately narrow. Two registers, a card and a counter check — not a
         * second catalogue and not a second till. Offers and membership are
         * `loyalty`, barcodes are `inventory`/`devices`.
         */
        'cosmetics' => [
            'label' => 'Beauty & Personal Care',
            'icon' => 'pi pi-heart-fill',
            'description' => 'Shade cards by family, finish and undertone, period-after-opening and tester stock, and a till that will not sell expired make-up',
        ],
        /**
         * The hire desk, and nothing else — which is the whole of what a sports
         * shop needs that no other trade in this catalogue does.
         *
         * Everything else the trade asks for is already sold: brands are core,
         * sizes and colours are `garment`'s matrix, warranty cards are `warranty`,
         * barcodes are `inventory`/`devices`, and a restring or a wheel true is a
         * `service` job card. What none of them can express is an article that
         * leaves the shop and is expected back — earning by the day, wearing out a
         * little each trip, and held against a deposit that is the customer's money
         * rather than the shop's.
         *
         * Deliberately narrow. One fleet register and one contract — not a second
         * catalogue, not a second till, and not a booking diary for courts and
         * turf, which is hiring *time* and a different problem.
         */
        'sports' => [
            'label' => 'Sports & Fitness',
            'icon' => 'pi pi-flag',
            'description' => 'Equipment hire: a fleet with rates and deposits, bookings that hold kit, partial returns, late fees and an overdue board',
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
        /**
         * Separate from `automobile`, which already carries a warranty register.
         * That one hangs off a vehicle and runs on time *and* distance, because
         * motor cover is written that way. This one hangs off the item that was
         * sold, on the bill it was sold on, and is claimed by a customer walking
         * back in with the thing — which is what a footwear, watch, electronics
         * or appliance shop actually does. One combined module would have put a
         * kilometre limit on a pair of sandals.
         */
        'warranty' => [
            'label' => 'Warranty & Claims',
            'icon' => 'pi pi-verified',
            'description' => 'Warranty cards issued against a sale, and the claims made back against them',
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
     * The catalogue as a flat list, ready for the wizard's module grid. `core`
     * marks the Core ERP set, which every client renders as on and locked rather
     * than as a choice.
     *
     * @return list<array{key: string, label: string, icon: string, description: string, core: bool}>
     */
    public static function options(): array
    {
        $out = [];
        foreach (self::CATALOGUE as $key => $meta) {
            $out[] = ['key' => $key] + $meta + ['core' => self::isCore($key)];
        }

        return $out;
    }

    /** Is this one of the modules every workspace gets? */
    public static function isCore(string $key): bool
    {
        return in_array($key, self::CORE, true);
    }

    /**
     * The given selection plus the Core ERP set, sanitized and returned in
     * catalogue order. Idempotent — the single place core is added to a module
     * list, so "what is core" is defined once.
     *
     * @param  array<int, mixed>  $keys
     * @return list<string>
     */
    public static function withCore(array $keys): array
    {
        $selected = array_merge(self::sanitize($keys), self::CORE);

        // Intersecting against the catalogue rather than array_unique'ing the
        // merge keeps the wizard's rendering order stable regardless of the order
        // a preset happened to declare its modules in.
        return array_values(array_intersect(self::keys(), $selected));
    }

    /**
     * The selection with core stripped — a trade's own modules, for callers that
     * need to show what a preset actually adds.
     *
     * @param  array<int, mixed>  $keys
     * @return list<string>
     */
    public static function withoutCore(array $keys): array
    {
        return array_values(array_diff(self::sanitize($keys), self::CORE));
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
