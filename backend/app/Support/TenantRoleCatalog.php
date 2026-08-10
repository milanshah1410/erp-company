<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Level-2 (Tenant / Company) RBAC catalog — project-stack.md §"Enterprise RBAC".
 *
 * Single source of truth for the tenant `web` guard's permission vocabulary and
 * the departmental role hierarchy that ships with every tenant. Consumed by
 * Database\Seeders\RolePermissionSeeder (to create permissions + roles) and by
 * App\Http\Controllers\Api\RoleController (to group roles by department in the
 * admin UI).
 *
 * Roles are assigned per tenant (team-scoped) but defined once as global
 * templates at team_id = NULL. A handful of roles pre-date this catalog
 * (super-admin, admin, sales-manager, warehouse-manager, purchase-manager,
 * accountant, staff, auditor); the seeder keeps their hand-tuned permission
 * sets and this catalog only slots them into the org chart for the UI.
 */
class TenantRoleCatalog
{
    /** Modules that get a full view/create/edit/delete permission set. */
    public const MODULES = [
        'products', 'categories', 'brands', 'units', 'warehouses',
        'suppliers', 'customers', 'customer-categories', 'brokers', 'price-lists',
        'leads', 'follow-ups', 'loyalty', 'coupons', 'referrals', 'whatsapp', 'sms', 'email', 'marketing-campaigns',
        'salesmen', 'salesman-targets', 'beats', 'salesman-visits',
        'salesman-locations', 'salesman-collections', 'salesman-expenses',
        'stock', 'stock-ledger', 'stock-adjustments', 'stock-transfers', 'stock-returns', 'cycle-counts',
        'gate-entries', 'advanced-inventory', 'barcodes',
        'purchase-indents', 'rfqs', 'vendor-ratings',
        'purchases', 'purchase-invoices', 'sales', 'sales-returns', 'b2b-orders',
        'ecommerce-integrations', 'channel-orders', 'channel-listings',
        'reports', 'users', 'roles', 'settings',
        // No 'backups': backup & restore is an operator concern and lives on the
        // platform Ops panel, not in a tenant workspace.
        'companies', 'branches', 'financial-years', 'gst-registrations',
        'hsn-codes', 'e-invoices', 'e-way-bills',
        'departments', 'employees', 'attendance', 'salaries',
        'leave-types', 'leave-applications', 'overtime', 'employee-bonuses',
        'salary-advances', 'employee-loans', 'biometric-devices', 'biometric-punches',
        'salary-structures', 'payroll',
        'tailors', 'contractors', 'rate-cards', 'piece-rates', 'tailor-works', 'tailor-payments',
        'tailor-alterations', 'tailor-bonuses',
        'embroidery-vendors', 'embroidery-works', 'embroidery-payments',
        'boms', 'manufacturing-orders', 'colour-printing', 'job-works',
        'textile',
        // Manufacturing plugin shop floor. Three separate permissions because
        // they are three different authorities: registering plant is an admin
        // act, judging a lot is the inspector's, and writing stock off as scrap
        // is neither — it is a call on the company's money, which is why an
        // inspector can fail a lot without being able to scrap it.
        'machines', 'quality-inspections', 'production-scrap',
        'expenses', 'expense-categories',
        'vehicles', 'assets',
        'employee-claims', 'vehicle-expenses', 'fuel-expenses',
        'electricity-bills', 'factory-maintenances', 'asset-maintenances',
        'drivers', 'delivery-routes', 'dispatch-plans', 'dispatch-pods', 'transport-charges',
        // Import & Export Logistics masters. Overseas suppliers/customers reuse
        // the 'suppliers' / 'customers' permissions — same records, filtered view.
        'countries', 'ports', 'shipping-lines', 'airlines',
        'freight-forwarders', 'cha-agents', 'transporters',
        // Import & Export Logistics shipments. Customs is split out from
        // 'shipments' because filing entries and paying duty is the CHA desk's
        // job, not the documentation desk's.
        'shipments', 'shipment-containers', 'shipment-documents',
        'shipment-customs', 'shipment-expenses', 'shipment-payments',
        'payments', 'payment-gateways',
        'bank-accounts', 'vouchers', 'tds-tcs-rates',
        'workflows',
        // The custom-field builder. Separate from 'settings' because defining a
        // field changes what every other user is required to fill in — it is
        // closer to editing the schema than to flipping a preference, and a
        // workspace usually wants fewer people holding it than hold settings.
        'custom-fields',
        // Device Manager: configuring hardware is an admin/IT act, so it gets a
        // full permission set of its own rather than riding on 'settings'.
        'devices',
        // Service Management. Contracts are split from jobs because selling an
        // AMC is a sales act, while working a repair is a workshop one.
        'service-jobs', 'service-contracts',
        // Project Management. Timesheets are split out because logging your own
        // hours is something everyone on a project does, while editing the
        // project's budget is not.
        'projects', 'project-tasks', 'project-timesheets',
        // Pharmacy. The register itself is view-only (below): its entries are
        // written by dispensing, never by hand.
        'prescriptions',
        // Jewellery. Quoting the day's rate is separated from taking scrap in:
        // the rate governs every bill in the shop, buying old gold is one deal.
        'metal-rates', 'old-gold',
        // Optical. Testing eyes and dispensing spectacles are different jobs,
        // often different people, so they carry separate permissions. The diary
        // is a third: whoever answers the phone books the chair, and that is not
        // the same person as the one who may record what the test found.
        'eye-prescriptions', 'optical-orders', 'optical-appointments',
        // Restaurant. The floor, the tickets and the kitchen display are three
        // different screens held by three different people.
        'restaurant-tables', 'restaurant-orders', 'kitchen-orders',
        // Food & Beverage plugin. The menu and the recipe book are separated on
        // purpose: a floor manager marks the fish sold out every other evening,
        // while what a dish is made of — and therefore its food cost — is the
        // chef's and the owner's business, not the shift's.
        'restaurant-menu', 'restaurant-recipes',
        /**
         * The other Food & Beverage trades. Each is its own permission because each
         * is a different person's job in a different kind of shop: pricing a combo
         * or a happy hour is the owner's, selling a club card is the counter's, the
         * cake order book belongs to the bakery, and a dairy's collection counter
         * and its delivery round are two different shifts that must not be able to
         * edit each other's numbers.
         */
        'menu-combos', 'menu-happy-hours', 'memberships', 'cake-orders',
        'milk-collections', 'milk-subscriptions',
        /**
         * The daily round sheet, separate from the standing orders it is generated
         * from. This is the split that lets a delivery boy correct what was left at
         * a door without also being able to change what a household is charged —
         * one permission for both would have made the sheet unusable by the only
         * person who walks it.
         */
        'milk-round',
        // Agriculture plugin. The dealer's licence register, and the farm's herd,
        // flocks and daily book. Production is split from the registers because
        // the person who keys the morning yield is not the person who decides
        // which cow to cull.
        'agri-licences', 'livestock-animals', 'livestock-flocks', 'farm-production',
        // Healthcare plugin. The test master is separated from the orders run
        // against it for the same reason a price list is separated from a bill:
        // defining what "normal" is for a test governs every report the lab ever
        // issues, and that is not a bench decision.
        'lab-tests', 'lab-orders',
        // The ward indent. One permission for the whole document — the ward
        // raises it and the store approves and issues it, which is the approve
        // level, not a separate module.
        'hospital-indents',
        // Automobile plugin. Three registers, three different desks: the counter
        // takes bookings all day, the vehicle master is edited when a car is
        // first seen or its papers renewed, and the warranty register decides
        // who pays for a repair — which is emphatically not a booking clerk's
        // call. The job card itself is a service job and needs no permission of
        // its own.
        'automobile-vehicles', 'automobile-bookings', 'automobile-warranties',
        // Electronics plugin. Two registers, two different desks: the phone desk
        // logs requests all day, and the unit register is written when a machine
        // is booked in or sold. There is no warranty permission here on purpose —
        // cover is the core `warranty` module's card, held under 'warranties',
        // and the job card itself is a service job.
        'electronics-units', 'electronics-requests',
        /**
         * Computer & IT plugin. Four registers, and the licence one is the
         * reason they are not three: a key is worth money the moment it is read
         * off a screen, so the counter hand who logs a call and the person who
         * can see a Windows key are deliberately different grants. Assets and
         * calls split for the same reason as Automobile's — the desk logs calls
         * all day, the install base is written when a machine is delivered — and
         * the warranty register decides who pays, which is nobody's call to take
         * on the phone. The job card itself is a service job and needs no
         * permission of its own.
         */
        'it-assets', 'it-warranties', 'it-licences', 'it-service-calls',
        /**
         * Mobile plugin. Four registers, and they split by how much damage the desk
         * can do:
         *
         *  - `mobile-handsets` is the IMEI register, written whenever a phone is
         *    booked in or sold. Counter work.
         *  - `mobile-insurance` decides whether a cracked screen is replaced free.
         *    Writing a policy is a sale; voiding one takes cover away from a
         *    customer relying on it, which is why that sits on `.delete`.
         *  - `mobile-emi` is a debtor book. Collections ride on `payments.create`
         *    because they move money, but *waiving* an instalment — forgiving a
         *    receivable outright — needs `.delete` here.
         *  - `mobile-buyback` commits the shop to a debt the moment a handset is
         *    accepted, so quoting and accepting are one grant and reading the
         *    register is another.
         *
         * There is no warranty permission here on purpose: cover is the core
         * `warranty` module's card, held under 'warranties'. Nor a job-card one —
         * a repair is a service job.
         */
        'mobile-handsets', 'mobile-insurance', 'mobile-emi', 'mobile-buyback',
        /**
         * Stationery plugin. Two registers, and they split by who is allowed to
         * change what a parent pays.
         *
         *  - `stationery-institutions` is the account: the school's board, its
         *    session, its roll, and the two discount rates. Editing it re-prices
         *    every set sold against that school for the rest of the year, which
         *    is an owner's decision and not a counter's.
         *  - `stationery-kits` is the booklist. Typing one up is clerical work
         *    and is handed out freely — but `.edit` also carries publishing, which
         *    is what lets the counter start selling from a list, and `.delete`
         *    carries archiving, which stops it mid-season.
         *
         * There is deliberately no school-order permission. A school's order *is*
         * a sales order, so it is held under `sales`; a second grant would let a
         * shop show someone institutional orders on one screen and not the other.
         */
        'stationery-institutions', 'stationery-kits',
        /**
         * Distribution & Wholesale plugin. Its own permission rather than riding
         * on 'customers': raising a party's credit limit or lifting a supply hold
         * is a decision about money at risk, and the desk that edits a party's
         * phone number is not the desk that should be taking it.
         *
         * `.view` is the collections clerk's read of the book; `.edit` covers
         * holding, releasing and changing a party's terms. `.create` and
         * `.delete` are seeded but unused — a party's credit standing is not a
         * record anyone creates or destroys, it is a state they are in.
         */
        'credit-control',
        /**
         * Retail warranty. Split from the claims made against it because they
         * are two desks: issuing a card is part of finishing a sale and belongs
         * to whoever is on the counter, while deciding that a six-week-old pair
         * of shoes is a manufacturing fault — and giving away a new one — is
         * not a counter decision. Settling a claim sits on `.approve` for the
         * same reason.
         */
        'warranties', 'warranty-claims',
        /**
         * Retail plugin — the garment trade.
         *
         * Three permissions for four screens, and the grouping is the point:
         *
         *  - `garment-styles` also covers the size runs and the colour palette.
         *    Those masters exist only to build styles and grids, and a shop that
         *    lets someone define a style but not the sizes it comes in has drawn
         *    a line nobody in the trade recognises.
         *  - `garment-fabrics` is separate because it is bought, not merchandised:
         *    it carries a supplier and a rate, and the person who names next
         *    season's fits is not the person who negotiates cloth.
         *  - `garment-trials` is emphatically its own. A trial is goods leaving
         *    the shop unpaid for on somebody's judgement of the customer, which
         *    is a credit decision in everything but name — and `.delete` (which
         *    is what cancelling sits on) releases the reservation behind it.
         *
         * The size × colour matrix has no permission of its own: generating a
         * grid creates product variants, so it rides on `products.edit`.
         */
        'garment-styles', 'garment-fabrics', 'garment-trials',
        /**
         * Gift vouchers. Under Loyalty & Offers rather than any trade — a gift
         * shop and a supermarket sell the same instrument — but its own
         * permission rather than riding on `coupons`, because a coupon is a
         * discount the shop chose to give and a voucher is money it has already
         * taken. Issuing one creates a liability; `.delete` is what cancelling a
         * card a customer is holding sits on.
         */
        'gift-vouchers',
        /**
         * Grocery & Kirana. One permission, not two: combo packs deliberately
         * ride on `products.*` — a pack is a product with a statement of what it
         * breaks into, and a shop that granted its counter manager the product
         * master and then found the hamper screen read-only would rightly call
         * that a bug.
         *
         * Supplier credit is separate precisely because it is *not* that. It is
         * the shop's own book of debts — what the owner owes each distributor,
         * which bills are late and what has to be paid this morning — and the
         * person who prices the shelf has no business reading it. `.edit` is the
         * distributor's limit and credit period, which is a fact somebody records
         * rather than a decision they take.
         */
        'supplier-credit',
        /**
         * The supermarket trade, and one of the three is not where a reader
         * would guess:
         *
         *  - `pos-counters` is a *core POS* permission, not a supermarket one.
         *    It covers defining tills and working the drawer — opening a shift,
         *    counting it down, reading the X and Z figures — which any shop with
         *    two counters does. Its create/edit levels are what a cashier is
         *    given; delete is what stops them inventing new tills.
         *  - `shelf-planogram` is merchandising: which product belongs on which
         *    shelf and how many facings it gets. Deliberately *not* the same
         *    grant as filling the shelf, which is `stock.edit` — the person who
         *    walks the replenishment round is not the person who decides that
         *    the biscuits move to aisle four, and in a big store they are not
         *    even in the same department.
         *  - `self-checkout` is the unattended lane. The `.approve` level is the
         *    entire safety mechanism of the trade: the lane's own login may scan
         *    but must not approve, so an age-restricted item parks the basket
         *    until a supervisor has looked at the customer. A lane granted both
         *    has had the check switched off without any switch being touched.
         */
        'pos-counters', 'shelf-planogram', 'self-checkout',
        /**
         * Gift & Novelty plugin. Three registers, split by what the desk holding
         * each of them can do:
         *
         *  - `gift-wraps` is the wrap desk *and* the wrapping master. The person at
         *    the bench takes parcels in, wraps them and hands them over; the master
         *    behind it is four rows that change twice a year, and granting the desk
         *    without them would grant a screen whose picker is empty. `.delete` is
         *    cancelling a parcel — which writes off material already consumed.
         *  - `gift-packs` is hamper *design*: what goes in one and what it is
         *    called. Assembling a batch is deliberately not here — it empties
         *    shelves, so it rides on `stock.create` and unwinding one on
         *    `stock.edit`. Merchandising a hamper and emptying the store to build
         *    forty of them are routinely different people.
         *  - `gift-occasions` is a register of customers' private dates, and `.edit`
         *    carries the ability to text them about one. Which is why it is not
         *    folded into `customers`: a shop can let the counter maintain customer
         *    records without letting it message them about their family.
         *
         * Note this is not the Grocery module's combo pack. That one is a multipack
         * billed as a single scan and rightly rides on `products.*`; a hamper is an
         * assembled article with its own stock and its own build documents, which is
         * why it has a register of its own.
         */
        'gift-wraps', 'gift-packs', 'gift-occasions',
        /**
         * Toys & Games plugin. One permission, not three, and deliberately so:
         * the age bands, the safety classes and the certificate register are one
         * book to whoever keeps it. The person who decides an article has small
         * parts is the person who decides it is not for under-3s and the person
         * who files the BIS licence behind it — three grants that are always
         * given together are one grant with extra steps.
         *
         * `.view` is what a counter needs (it is also what the till's own check
         * reads through, so refusing it would only mean the sale happens without
         * the warning); `.edit` is grading and classifying the range; `.delete`
         * carries withdrawing a certificate, which stops a shop being able to
         * sell everything behind it.
         */
        'toy-safety',

        /**
         * Books & Periodicals plugin. One permission across four screens, for
         * the same reason Toys has one across three: the publishers, the authors,
         * the languages and the credits on a title are one book to whoever keeps
         * it. The person who decides which house a title belongs to is the person
         * who spells the translator's name and shelves it under Gujarati.
         *
         * `.view` is what the counter needs — it is what the ISBN lookup runs
         * through, and refusing it only means a customer is told "no" without
         * anybody having checked. `.edit` is cataloguing. `.delete` is retiring a
         * register row, which the controllers refuse anyway while titles still
         * hang off it.
         *
         * There is deliberately no separate grant for editing a *title*: a title
         * is a product, so its price, its stock and its existence are `products.*`
         * like everything else in the shop. Only the credits belong to us.
         */
        'book-catalogue',

        /**
         * Beauty & Personal Care plugin. Two permissions, not one, and unlike
         * the toy shop's single grant the split here is real: these are two jobs
         * done by two people.
         *
         * `cosmetic-shades` is the buyer's book — which shades the shop carries
         * and which of them are on a range's card. Generating a card creates
         * sellable SKUs, so that route carries products.create as well.
         *
         * `cosmetic-shelf-life` is the floor's — what is going off, which testers
         * are past their period after opening, and whether the till refuses short
         * stock. `.view` is what a counter needs (it is also what the till's own
         * check reads through), `.create` is opening a tester, and `.delete`
         * carries changing the policy: whether an expired line stops a bill is a
         * decision about the shop's licence to trade, not a preference.
         */
        'cosmetic-shades',
        'cosmetic-shelf-life',

        /**
         * Sports & Fitness plugin. Two permissions, and the split is the oldest
         * one in retail: what the shop owns, and what the counter does with it.
         *
         * `rental-assets` is the fleet — buying a bike, pricing it by the hour and
         * the week, setting the deposit against it, sending it to the bench and
         * writing it off. Every one of those is an ownership decision about the
         * shop's capital, and none of them is the counter's. It also carries the
         * utilisation report, because whether a bike has earned its keep is the
         * same book as what the bike is.
         *
         * `rentals` is the desk — booking, handing over, extending, taking back.
         * `.view` is what anybody working a counter needs; `.edit` is the whole of
         * the day's work, including handover, which commits kit to a customer for
         * a weekend; `.delete` carries cancelling a booking.
         *
         * Two things are deliberately *not* here. Settling a hire raises an
         * invoice and decides what happens to a deposit, so it rides on
         * `sales.create` — the same rule that puts assembling a hamper on
         * `stock.create`. And a repair on a returned bike is an ordinary
         * `service-jobs` grant, because it is an ordinary job card.
         */
        'rental-assets',
        'rentals',

        /**
         * Customer feedback & CSAT (retail base §6). Its own permission rather
         * than riding on `customers`: what a customer thinks of the shop is not
         * the same record as their phone number, and the split matters in both
         * directions. A counter hand should be able to write down a complaint
         * without also being able to edit the customer master — and `.edit`, which
         * carries *replying* on the shop's behalf, is not a counter decision at
         * all. `.delete` stops a row counting towards the score, which is why it
         * is held separately again.
         *
         * Distinct from the platform's support-ticket CSAT, which measures the
         * SaaS owner's own helpdesk and lives on the central guard.
         */
        'customer-feedback',

        /**
         * Shop-staff sales commission (retail base §8b). Separate from `payroll`
         * on purpose: an owner sets the rates and a floor manager agrees the
         * month's figures, but the person who runs payroll should not be able to
         * rewrite what the shop promised. And separate from `salesman-targets`,
         * which is the field rep's monthly incentive — a different scheme for a
         * different job.
         *
         * `.view` is reading the payout sheet. `.create` covers adding a rule and
         * working the month out. `.edit` corrects an open figure. `.approve` is
         * the one that matters: it signs the numbers off, settles them, and is the
         * only way to reverse a figure somebody has already been paid.
         */
        'sales-commissions',
    ];

    /** Modules that are view-only (no create/edit/delete). */
    public const VIEW_ONLY_MODULES = [
        'audit-logs', 'notifications', 'accounts', 'channel-sync-logs', 'ai',
        /**
         * Tally / Busy / Zoho Books export (retail base §10). Read-only because
         * the bridge is one-way — nothing is ever written back — so create/edit/
         * delete would be permissions over an act that does not exist.
         *
         * Its own grant rather than `reports.view`, though, because the file is a
         * wider read than any single report: every party, every bill and every
         * ledger name the workspace holds, in one download that leaves the
         * building.
         */
        'accounting-exports',
        // The device event log is telemetry: readable by anyone who can watch a
        // counter, cleared under 'devices.delete'.
        'device-events',
        // The Schedule H1/X register is a statutory record produced during a
        // drug-inspector visit. Nobody edits it — entries are written by the
        // act of dispensing, so the permission is read and nothing else.
        'drug-register',
    ];

    /** Modules that also support an "approve" permission. */
    public const APPROVABLE = [
        'purchase-indents', 'purchases', 'purchase-invoices',
        'sales', 'sales-returns', 'b2b-orders', 'channel-orders', 'stock-adjustments', 'stock-transfers', 'stock-returns', 'cycle-counts',
        'job-works', 'expenses',
        'employee-claims', 'vehicle-expenses', 'fuel-expenses',
        'electricity-bills', 'factory-maintenances', 'asset-maintenances',
        'leave-applications', 'overtime', 'employee-bonuses', 'salary-advances', 'employee-loans',
        // Only approved hours can be invoiced, so approval is a distinct act
        // from logging them.
        'project-timesheets',
        /**
         * Healthcare. Both are cases where approval is the moment something
         * becomes irreversible and the signature has to be someone else's:
         * verifying a lab report turns a reading into a clinical result (and
         * billing it moves money), while issuing an indent physically empties a
         * shelf on a ward's say-so.
         */
        'lab-orders', 'hospital-indents',
        /**
         * Settling a warranty claim gives away a replacement, a repair or a
         * refund. Registering the claim is the counter writing down what a
         * customer said; deciding it is the shop spending money, and the two
         * must be separable or every counter can approve its own goodwill.
         */
        'warranty-claims',
        /**
         * Self-checkout. Approving is not "a bigger version of scanning" — it is
         * a different person doing a different thing, which is the whole design:
         * the lane holds create and stops at a restricted item, the supervisor
         * holds approve and clears it. Voiding a line sits here too, because
         * anybody who can silently unscan an item can walk out with it.
         */
        'self-checkout',
        /**
         * Sales commission. Approval is where a calculation becomes somebody's
         * pay: it signs the month's figures off, settles them, and is the only way
         * to reverse a figure already paid. Whoever typed the rates in should not
         * hold it.
         */
        'sales-commissions',
    ];

    /**
     * Reusable module bundles, referenced by role specs. A spec entry is either
     * a group key below or a bare module name, suffixed with an access level.
     *
     * @return array<string, array<int, string>>
     */
    public static function groups(): array
    {
        return [
            'CATALOG' => ['products', 'categories', 'brands', 'units'],
            // Gift vouchers ride with the rest of the customer-rewards bundle:
            // whoever may issue a coupon is who a shop expects to sell a gift
            // card, and both are sold and honoured at the same counter.
            // Customer feedback rides with the rest of the customer bundle: the
            // desk that keeps a customer's record is the desk that hears what
            // they thought, and a CRM role without the complaints book would be
            // holding half a conversation.
            'CRM_CORE' => ['customers', 'customer-categories', 'leads', 'follow-ups', 'loyalty', 'coupons', 'referrals', 'gift-vouchers', 'customer-feedback'],
            'PRICE' => ['price-lists'],
            'COMMS' => ['whatsapp', 'sms', 'email', 'marketing-campaigns'],
            'FIELD_SALES' => ['salesmen', 'salesman-targets', 'beats', 'salesman-visits', 'salesman-locations', 'salesman-collections', 'salesman-expenses'],
            'SALES' => ['sales', 'sales-returns', 'b2b-orders'],
            'PURCHASE' => ['suppliers', 'purchase-indents', 'rfqs', 'vendor-ratings', 'purchases', 'purchase-invoices'],
            'INVENTORY' => ['warehouses', 'stock', 'stock-ledger', 'stock-adjustments', 'stock-transfers', 'stock-returns', 'cycle-counts', 'gate-entries', 'advanced-inventory', 'barcodes'],
            'MFG' => [
                'boms', 'manufacturing-orders', 'colour-printing', 'job-works', 'textile',
                'machines', 'quality-inspections', 'production-scrap',
            ],
            'TAILOR' => ['tailors', 'contractors', 'rate-cards', 'piece-rates', 'tailor-works', 'tailor-alterations', 'tailor-bonuses', 'tailor-payments'],
            'EMBROIDERY' => ['embroidery-vendors', 'embroidery-works', 'embroidery-payments'],
            'EXPENSES' => ['expenses', 'expense-categories', 'employee-claims', 'vehicle-expenses', 'fuel-expenses', 'electricity-bills', 'factory-maintenances', 'asset-maintenances'],
            'TREASURY' => ['payments', 'payment-gateways', 'bank-accounts', 'vouchers', 'tds-tcs-rates'],
            'ASSETS' => ['vehicles', 'assets'],
            'GST' => ['gst-registrations', 'hsn-codes', 'e-invoices', 'e-way-bills'],
            'HR' => ['departments', 'employees', 'attendance', 'salaries', 'leave-types', 'leave-applications', 'overtime', 'employee-bonuses', 'salary-advances', 'employee-loans', 'biometric-devices', 'biometric-punches', 'salary-structures', 'payroll'],
            'TRANSPORT' => ['drivers', 'delivery-routes', 'dispatch-plans', 'dispatch-pods', 'transport-charges'],
            // Import & Export Logistics: the trade reference data …
            'EXIM' => ['countries', 'ports', 'shipping-lines', 'airlines', 'freight-forwarders', 'cha-agents', 'transporters'],
            // … and the shipments built on it. Split so a desk can hold the
            // shipments without being able to edit the masters behind them.
            'EXIM_SHIPMENTS' => ['shipments', 'shipment-containers', 'shipment-documents', 'shipment-customs', 'shipment-expenses', 'shipment-payments'],
            'BROKER' => ['brokers'],
            // Device Manager. Bundled with the event log because reading the log
            // is how you diagnose the device you are allowed to configure.
            'DEVICES' => ['devices', 'device-events'],
            'SERVICE' => ['service-jobs', 'service-contracts'],
            'PROJECTS' => ['projects', 'project-tasks', 'project-timesheets'],
            'PHARMACY' => ['prescriptions', 'drug-register'],
            'JEWELLERY' => ['metal-rates', 'old-gold'],
            'OPTICAL' => ['eye-prescriptions', 'optical-orders', 'optical-appointments'],
            /**
             * Retail's garment trade. The merchandising side — what the shop
             * sells and what it is made of — bundled together, because the
             * person who plans a range does both.
             *
             * The trial room is *not* in it. A floor manager holds the styles;
             * letting goods leave the shop unpaid for is the owner's call, so
             * `garment-trials` is always an explicit extra on a role.
             */
            'GARMENT' => ['garment-styles', 'garment-fabrics'],
            'RESTAURANT' => [
                'restaurant-tables', 'restaurant-orders', 'kitchen-orders',
                'restaurant-menu', 'restaurant-recipes',
                // Menu pricing rides with the menu: whoever may edit the card is
                // who a café expects to set its four-o'clock offer.
                'menu-combos', 'menu-happy-hours',
            ],
            /**
             * The counter-service F&B trades, bundled by the shop they belong to
             * rather than by the vertical — a bakery manager holds the cake book
             * and nothing restaurant-shaped, and a dairy office holds the round and
             * the collection counter together.
             */
            'BAKERY' => ['cake-orders'],
            'DAIRY' => ['milk-collections', 'milk-subscriptions', 'milk-round'],
            'MEMBERSHIP' => ['memberships'],
            /**
             * The supermarket floor. Two grants that go together in practice —
             * whoever lays the planogram out is whoever watches the lanes, in
             * every store small enough to have one floor manager.
             *
             * `pos-counters` is *not* in it. Cash-up is a POS grant, it belongs
             * to trades that never heard of a planogram, and bundling it here
             * would mean a merchandiser who can open a drawer.
             */
            'SUPERMARKET' => ['shelf-planogram', 'self-checkout'],
            // Agriculture: the dealer's compliance desk …
            'AGRI' => ['agri-licences'],
            // … and the farm. Bundled because a farm manager holds the registers
            // and the daily book together; the shed hand gets farm-production
            // alone (see the farm-worker role).
            'LIVESTOCK' => ['livestock-animals', 'livestock-flocks', 'farm-production'],
            // Healthcare: the lab, and the hospital store. Kept apart because a
            // standalone pathology lab has no wards and a nursing home's store
            // keeper has no business signing off a blood report.
            'DIAGNOSTICS' => ['lab-tests', 'lab-orders'],
            'HOSPITAL_STORE' => ['hospital-indents'],
            // Automobile: the three registers a workshop keeps. SERVICE is not
            // folded in — a parts counter runs the vehicle and warranty
            // registers with no job book behind them at all.
            'AUTOMOBILE' => ['automobile-vehicles', 'automobile-bookings', 'automobile-warranties'],
            // Electronics: the two registers an aftersales desk keeps. Neither
            // SERVICE nor WARRANTY is folded in — an electrical goods counter
            // runs the unit register with no job book at all, and warranty is a
            // shared core register whose authority a shop grants separately.
            'ELECTRONICS' => ['electronics-units', 'electronics-requests'],
            /**
             * Computer & IT: the install base, its cover and the call book.
             * Licences are deliberately *not* in the group — granting someone
             * the support desk should not hand them every product key the shop
             * has ever sold, so `it-licences` is always an explicit extra.
             */
            'COMPUTER_IT' => ['it-assets', 'it-warranties', 'it-service-calls'],
            /**
             * Stationery: the school accounts and the booklists. Bundled because
             * one desk keeps both — the person who takes a school's list down is
             * the person who set the school up — and because a booklist without
             * its institution is unreadable, so a group holding only one of the
             * two would grant a screen that could not be used.
             */
            'STATIONERY' => ['stationery-institutions', 'stationery-kits'],
            /**
             * Mobile: the IMEI register, the insurance book and the part-exchange
             * register. `mobile-emi` is deliberately *not* in the group — it is a
             * debtor book, and putting a salesman on the shop floor should not
             * hand them the ability to write off instalments. It is always an
             * explicit extra, for the same reason `it-licences` is.
             */
            'MOBILE' => ['mobile-handsets', 'mobile-insurance', 'mobile-buyback'],
            // Retail warranty. Bundled because the register and its claims are
            // one book to whoever keeps it — but note that granting the group
            // at `c` still leaves settling out, since resolve sits on approve.
            'WARRANTY' => ['warranties', 'warranty-claims'],
            /**
             * Gift & Novelty: the wrap desk and the hamper book, which are the two
             * things a gift counter works all day. `gift-occasions` is deliberately
             * *not* in the group — it is a register of customers' private family
             * dates and `.edit` sends them a message, so it is always an explicit
             * grant, for the same reason `it-licences` and `mobile-emi` are.
             */
            'GIFT' => ['gift-wraps', 'gift-packs'],
            /**
             * Beauty & Personal Care: the shade book and the dating book. Bundled
             * only for the seat that runs the counter as a whole — the two are
             * separate grants precisely because a buyer setting up a forty-shade
             * range and an assistant binning an out-of-date tester are different
             * people, and the roles below give them separately.
             */
            'BEAUTY' => ['cosmetic-shades', 'cosmetic-shelf-life'],
            /**
             * Sports & Fitness: the hire fleet and the hire desk. Bundled only for
             * the seat that answers for the shop as a whole — they are two grants
             * precisely because pricing a bike and lending one out are different
             * decisions, and the counter role below takes the fleet at view only.
             */
            'HIRE' => ['rental-assets', 'rentals'],
        ];
    }

    /**
     * Access levels → concrete actions.
     *   v = view · c = contribute (view/create/edit) · m = manage (+delete) ·
     *   a = approve (manage + approve, where the module supports it)
     *
     * @return array<int, string>
     */
    public static function levelActions(string $level): array
    {
        return match ($level) {
            'v' => ['view'],
            'c' => ['view', 'create', 'edit'],
            'm' => ['view', 'create', 'edit', 'delete'],
            'a' => ['view', 'create', 'edit', 'delete', 'approve'],
            default => ['view'],
        };
    }

    /**
     * The department org chart. Ordered map of department label → role slugs,
     * including the pre-existing roles so the whole hierarchy renders together.
     * The "System" bucket holds the built-in platform roles.
     *
     * @return array<string, array<int, string>>
     */
    public static function departments(): array
    {
        return [
            'Management' => ['company-owner', 'managing-director', 'ceo', 'director', 'general-manager', 'branch-manager', 'operations-manager'],
            'CRM' => ['crm-manager', 'sales-manager', 'sales-executive', 'telecaller', 'marketing-executive'],
            'Customer' => ['customer-support', 'relationship-manager'],
            'Purchase' => ['purchase-manager', 'purchase-executive', 'vendor-coordinator'],
            'Inventory' => ['inventory-manager', 'warehouse-manager', 'store-keeper', 'stock-auditor'],
            'Manufacturing' => ['production-manager', 'production-supervisor', 'factory-manager', 'machine-operator', 'tailor', 'cutting-operator', 'embroidery-operator', 'printing-operator', 'packing-staff', 'qc-inspector'],
            'Broker' => ['broker', 'broker-manager'],
            'Finance' => ['finance-manager', 'senior-accountant', 'accountant', 'cashier', 'auditor'],
            'HR' => ['hr-manager', 'hr-executive', 'payroll-executive'],
            'Transport' => ['transport-manager', 'dispatch-executive', 'transport-coordinator', 'driver', 'delivery-boy'],
            'Import & Export' => ['logistics-manager', 'import-executive', 'export-executive', 'documentation-executive', 'cha-executive'],
            'Agriculture' => ['agri-manager', 'farm-manager', 'farm-worker'],
            // The shop floor. Its own department rather than a corner of CRM
            // because these three are the whole staff of a showroom, and a
            // garment shop's org chart is not a sales pipeline.
            'Retail' => ['showroom-manager', 'garment-merchandiser', 'floor-salesman'],
            // Separate from Retail on purpose: a supermarket's staff are not a
            // showroom's. The front end and the shop floor are two different
            // shifts run by two different people, and the fourth entry is not a
            // person at all — it is the login a self-checkout lane runs under.
            'Store Floor' => ['store-manager', 'checkout-supervisor', 'shelf-stocker', 'self-checkout-lane'],
            'Administration' => ['office-admin', 'receptionist'],
            'System' => ['super-admin', 'admin', 'staff'],
        ];
    }

    /** Look up a role's department for the admin UI. Null for custom roles. */
    public static function departmentOf(string $roleSlug): ?string
    {
        foreach (self::departments() as $department => $roles) {
            if (in_array($roleSlug, $roles, true)) {
                return $department;
            }
        }

        return null;
    }

    /**
     * The NEW roles this catalog introduces (the pre-existing ones are seeded
     * elsewhere with their hand-tuned sets). slug → [label, spec]. A spec of
     * ['*'] means "all permissions". Permissions are tailored by seniority:
     * managers manage + approve their area, executives contribute, operators
     * mostly view and record their own work.
     *
     * @return array<string, array{label: string, spec: array<int, string>}>
     */
    public static function newRoles(): array
    {
        return [
            // ---- Management ----
            'company-owner' => ['label' => 'Company Owner', 'spec' => ['*']],
            'managing-director' => ['label' => 'Managing Director', 'spec' => ['*']],
            'ceo' => ['label' => 'CEO', 'spec' => ['*']],
            'director' => ['label' => 'Director', 'spec' => [
                'CATALOG:m', 'CRM_CORE:m', 'PRICE:m', 'COMMS:m', 'FIELD_SALES:m', 'SALES:a',
                'PURCHASE:a', 'INVENTORY:a', 'MFG:a', 'TAILOR:a', 'EMBROIDERY:m', 'EXPENSES:a',
                'TREASURY:m', 'ASSETS:m', 'GST:m', 'HR:a', 'TRANSPORT:m', 'EXIM:m', 'EXIM_SHIPMENTS:m', 'BROKER:m',
                'accounts:v', 'reports:v', 'workflows:m', 'audit-logs:v',
                'users:v', 'roles:v', 'companies:v', 'branches:v', 'settings:v', 'notifications:v', 'ai:v',
                'credit-control:m',
            ]],
            'general-manager' => ['label' => 'General Manager', 'spec' => [
                'CATALOG:m', 'CRM_CORE:m', 'PRICE:m', 'COMMS:m', 'FIELD_SALES:m', 'SALES:a',
                'PURCHASE:a', 'INVENTORY:a', 'MFG:a', 'TAILOR:a', 'EMBROIDERY:m', 'EXPENSES:a',
                'TREASURY:v', 'ASSETS:m', 'GST:v', 'HR:m', 'TRANSPORT:m', 'EXIM:m', 'EXIM_SHIPMENTS:m', 'BROKER:m',
                'accounts:v', 'reports:v', 'workflows:v', 'notifications:v', 'ai:v',
                'credit-control:c',
            ]],
            'branch-manager' => ['label' => 'Branch Manager', 'spec' => [
                'CATALOG:v', 'CRM_CORE:m', 'PRICE:v', 'COMMS:c', 'FIELD_SALES:m', 'SALES:a',
                'PURCHASE:c', 'INVENTORY:a', 'MFG:v', 'TRANSPORT:m', 'EXPENSES:c', 'BROKER:v', 'DEVICES:m',
                'reports:v', 'notifications:v', 'ai:v',
                'credit-control:c',
                // The desk that settles warranty claims in a shop. Approve, not
                // manage: giving away a replacement pair is exactly the call
                // this role exists to make.
                'WARRANTY:a',
            ]],
            'operations-manager' => ['label' => 'Operations Manager', 'spec' => [
                'INVENTORY:a', 'MFG:a', 'TAILOR:a', 'EMBROIDERY:m', 'PURCHASE:c', 'TRANSPORT:m', 'EXIM:m', 'EXIM_SHIPMENTS:m',
                'CATALOG:v', 'EXPENSES:c', 'DEVICES:m', 'SERVICE:m', 'PROJECTS:m',
                'reports:v', 'workflows:v', 'notifications:v', 'ai:v',
            ]],

            // ---- CRM ----
            'crm-manager' => ['label' => 'CRM Manager', 'spec' => [
                'CRM_CORE:m', 'PRICE:m', 'COMMS:m', 'FIELD_SALES:m', 'SALES:a', 'CATALOG:v',
                'reports:v', 'notifications:v', 'ai:v',
                'credit-control:v',
            ]],
            'sales-executive' => ['label' => 'Sales Executive', 'spec' => [
                'customers:c', 'customer-categories:v', 'leads:c', 'follow-ups:c', 'price-lists:v',
                'SALES:c', 'CATALOG:v', 'stock:v', 'FIELD_SALES:v', 'reports:v', 'notifications:v',
                // Read-only, and load-bearing: an order booked against a stopped
                // party is wasted work, so the person booking it can see the flag.
                'credit-control:v',
            ]],
            'telecaller' => ['label' => 'Telecaller', 'spec' => [
                'leads:c', 'follow-ups:c', 'customers:v', 'whatsapp:c', 'sms:c', 'email:c', 'notifications:v',
            ]],
            'marketing-executive' => ['label' => 'Marketing Executive', 'spec' => [
                'marketing-campaigns:m', 'COMMS:m', 'coupons:m', 'referrals:m', 'loyalty:m',
                'leads:v', 'customers:v', 'reports:v', 'notifications:v',
            ]],

            // ---- Customer ----
            'customer-support' => ['label' => 'Customer Support', 'spec' => [
                'customers:v', 'follow-ups:c', 'b2b-orders:v', 'sales:v', 'sales-returns:c',
                'loyalty:v', 'whatsapp:c', 'sms:c', 'email:c', 'notifications:v',
                /**
                 * The desk a warranty complaint actually arrives at. Contribute,
                 * not manage: writing down what the customer says is this job,
                 * and deciding to give away a replacement is not — settling sits
                 * on warranty-claims.approve, which this level does not carry.
                 */
                'WARRANTY:c',
            ]],
            'relationship-manager' => ['label' => 'Relationship Manager', 'spec' => [
                'customers:m', 'customer-categories:v', 'follow-ups:m', 'loyalty:m', 'referrals:m',
                'coupons:v', 'leads:c', 'sales:v', 'b2b-orders:c', 'price-lists:v', 'reports:v', 'notifications:v',
            ]],

            // ---- Purchase ----
            'purchase-executive' => ['label' => 'Purchase Executive', 'spec' => [
                'suppliers:c', 'purchase-indents:c', 'rfqs:c', 'vendor-ratings:c', 'purchases:c', 'purchase-invoices:c',
                'products:v', 'categories:v', 'stock:v', 'stock-ledger:v', 'email:c', 'reports:v', 'notifications:v',
            ]],
            'vendor-coordinator' => ['label' => 'Vendor Coordinator', 'spec' => [
                'suppliers:c', 'vendor-ratings:c', 'rfqs:c', 'purchase-indents:v', 'purchases:v', 'purchase-invoices:v',
                'products:v', 'email:c', 'notifications:v',
            ]],

            // ---- Inventory ----
            'inventory-manager' => ['label' => 'Inventory Manager', 'spec' => [
                'INVENTORY:a', 'CATALOG:m', 'boms:v', 'manufacturing-orders:v', 'suppliers:v',
                'DEVICES:m', 'reports:v', 'notifications:v', 'ai:v',
            ]],
            'store-keeper' => ['label' => 'Store Keeper', 'spec' => [
                'stock:c', 'stock-ledger:v', 'stock-adjustments:c', 'stock-transfers:c', 'gate-entries:m',
                'warehouses:v', 'products:v', 'barcodes:v', 'advanced-inventory:v', 'devices:v', 'device-events:v', 'notifications:v',
            ]],
            'stock-auditor' => ['label' => 'Stock Auditor', 'spec' => [
                'stock:v', 'stock-ledger:v', 'cycle-counts:c', 'stock-adjustments:v', 'advanced-inventory:v',
                'warehouses:v', 'products:v', 'reports:v', 'audit-logs:v', 'notifications:v',
            ]],

            // ---- Manufacturing ----
            'production-manager' => ['label' => 'Production Manager', 'spec' => [
                'MFG:a', 'boms:m', 'manufacturing-orders:a', 'job-works:a', 'TAILOR:m', 'EMBROIDERY:m', 'textile:m',
                'stock:c', 'stock-ledger:v', 'products:v', 'reports:v', 'notifications:v', 'ai:v',
            ]],
            'production-supervisor' => ['label' => 'Production Supervisor', 'spec' => [
                'manufacturing-orders:c', 'boms:v', 'job-works:c', 'textile:c', 'tailor-works:c', 'embroidery-works:c',
                // Runs the floor: loads machines and logs their stops, records
                // inspections, and raises the scrap that comes out of them.
                'machines:c', 'quality-inspections:c', 'production-scrap:c',
                'stock:v', 'products:v', 'notifications:v',
            ]],
            'factory-manager' => ['label' => 'Factory Manager', 'spec' => [
                'MFG:a', 'TAILOR:a', 'EMBROIDERY:a', 'textile:m', 'factory-maintenances:a', 'asset-maintenances:c',
                'stock:c', 'employees:v', 'attendance:c', 'reports:v', 'notifications:v',
            ]],
            'machine-operator' => ['label' => 'Machine Operator', 'spec' => [
                'manufacturing-orders:v', 'job-works:c', 'textile:v', 'stock:v', 'products:v', 'notifications:v',
                // Logging a stop sits on machines.edit — the operator standing at
                // the machine when it fails is the person who has to record it,
                // and routing that through a supervisor means it is recorded an
                // hour late or not at all.
                'machines:c',
            ]],
            'tailor' => ['label' => 'Tailor', 'spec' => [
                'tailor-works:c', 'tailor-alterations:c', 'piece-rates:v', 'rate-cards:v', 'products:v', 'notifications:v',
            ]],
            'cutting-operator' => ['label' => 'Cutting Operator', 'spec' => [
                'textile:c', 'manufacturing-orders:v', 'boms:v', 'stock:v', 'products:v', 'notifications:v',
            ]],
            'embroidery-operator' => ['label' => 'Embroidery Operator', 'spec' => [
                'embroidery-works:c', 'embroidery-vendors:v', 'embroidery-payments:v', 'products:v', 'notifications:v',
            ]],
            'printing-operator' => ['label' => 'Printing Operator', 'spec' => [
                'colour-printing:c', 'job-works:c', 'textile:v', 'products:v', 'notifications:v',
            ]],
            'packing-staff' => ['label' => 'Packing Staff', 'spec' => [
                'manufacturing-orders:v', 'gate-entries:c', 'dispatch-plans:v', 'stock:v', 'products:v', 'notifications:v',
            ]],
            'qc-inspector' => ['label' => 'QC Inspector', 'spec' => [
                'textile:c', 'manufacturing-orders:v', 'job-works:v', 'stock:v', 'products:v', 'reports:v', 'notifications:v',
                /**
                 * Inspections at contribute level, and scrap at view only. The
                 * inspector says what failed; writing the failure off against the
                 * company's stock is somebody else's call, which is the whole
                 * reason the two are separate permissions.
                 */
                'quality-inspections:c', 'machines:v', 'production-scrap:v',
            ]],

            // ---- Service ----
            'service-manager' => ['label' => 'Service Manager', 'spec' => [
                'SERVICE:m', 'customers:c', 'employees:v', 'stock:v', 'CATALOG:v',
                'sales:v', 'payments:v', 'reports:v', 'notifications:v', 'ai:v',
            ]],
            // A technician works the jobs on the bench: they record diagnosis,
            // parts and resolution, but do not open, close or price the book.
            'service-technician' => ['label' => 'Service Technician', 'spec' => [
                'service-jobs:c', 'service-contracts:v', 'stock:v', 'products:v',
                'customers:v', 'notifications:v',
                // A vehicle on the ramp is read, not edited, by the person
                // working on it — but they do need to see what is still covered
                // before they start replacing parts for free. The same holds for
                // a machine on the bench, which is why both registers are here:
                // one technician often works whatever the shop takes in.
                'automobile-vehicles:v', 'automobile-warranties:v',
                // Electronics reads cover from the shared retail register rather
                // than one of its own, so it is 'warranties' here, not an
                // electronics-specific permission.
                'electronics-units:v', 'warranties:v',
                'it-assets:v', 'it-warranties:v',
            ]],

            // ---- Retail: garment & apparel ----
            /**
             * Runs the shop floor end to end. Holds the trial room outright,
             * which is the distinguishing grant: letting stock walk out of the
             * door on a customer's word is a credit decision, and `garment-trials:m`
             * is also what carries the authority to cancel a slip and release the
             * reservation behind it.
             */
            'showroom-manager' => ['label' => 'Showroom Manager', 'spec' => [
                'GARMENT:m', 'garment-trials:m', 'CATALOG:m', 'SALES:a',
                'stock:c', 'stock-ledger:v', 'advanced-inventory:v', 'barcodes:m',
                'customers:m', 'loyalty:m', 'coupons:m', 'gift-vouchers:m',
                'tailor-alterations:m', 'tailors:v',
                'payments:c', 'EXPENSES:c', 'employees:v',
                'reports:v', 'notifications:v', 'ai:v',
                /**
                 * The floor's two books about its own people and its own
                 * customers. Feedback at manage level because replying to a
                 * complaint about the showroom is this job; commission at approve
                 * level because agreeing what the floor earned this month is also
                 * this job — the person who watched them sell it.
                 */
                'customer-feedback:m', 'sales-commissions:a',
            ]],
            /**
             * Plans the range and builds it into sellable stock: styles, fabrics
             * and the size × colour grid — which is why CATALOG is at manage
             * level, since generating a matrix creates product variants.
             *
             * No trial room and no till. A merchandiser decides what the shop
             * buys, not what leaves it unpaid for.
             */
            'garment-merchandiser' => ['label' => 'Garment Merchandiser', 'spec' => [
                'GARMENT:m', 'CATALOG:m', 'barcodes:c',
                'stock:v', 'stock-ledger:v', 'price-lists:c',
                'purchases:c', 'purchase-indents:c', 'suppliers:v',
                'sales:v', 'reports:v', 'notifications:v', 'ai:v',
            ]],
            /**
             * Sells on the floor. Reads the range and the grid — "have you got
             * this in 40, in navy?" is the entire job — rings up sales and books
             * alterations, which is the promise made at the moment of sale and
             * has to be recorded then or it is not recorded at all.
             *
             * Trials are view-only: a salesman can see what is out and chase it,
             * but issuing one is the manager's call.
             */
            'floor-salesman' => ['label' => 'Floor Salesman', 'spec' => [
                'GARMENT:v', 'garment-trials:v', 'CATALOG:v', 'stock:v',
                'sales:c', 'sales-returns:c', 'customers:c',
                'loyalty:v', 'coupons:v', 'gift-vouchers:v',
                'tailor-alterations:c', 'notifications:v',
            ]],

            // ---- Grocery & Kirana ----
            /**
             * The person who actually runs a kirana, which in most shops is the
             * person who owns it. The distinguishing grant is `supplier-credit:m`:
             * the whole trade turns on knowing what is owed to which distributor
             * and paying it on the day, and it is the one thing on this list
             * nobody else in the shop should see.
             *
             * CATALOG at manage level because combo packs ride on `products.*` —
             * pricing a hamper is pricing, and splitting it out would have meant
             * granting the product master and then finding the pack screen
             * read-only.
             */
            'kirana-owner' => ['label' => 'Kirana / Grocery Manager', 'spec' => [
                'supplier-credit:m', 'CATALOG:m', 'SALES:a', 'barcodes:m',
                'stock:c', 'stock-ledger:v', 'advanced-inventory:m',
                'purchases:m', 'purchase-invoices:m', 'suppliers:m',
                'customers:m', 'loyalty:m', 'coupons:m', 'gift-vouchers:m',
                'payments:m', 'EXPENSES:m', 'devices:v',
                'reports:v', 'notifications:v', 'ai:v',
            ]],
            /**
             * The hand at the till. Bills, takes returns, scans a hamper and
             * knows what the shelf has — and reads the khata not at all. A shop
             * assistant who can see the owner's debts is a shop assistant who can
             * discuss them with the distributor's man, which is exactly the
             * conversation an owner does not want held.
             *
             * `advanced-inventory:v` is here for one reason: expiry. A grocery
             * counter hand is the person who notices the date on a packet, and
             * the batch screen is where that is checked.
             */
            'grocery-counter-staff' => ['label' => 'Grocery Counter Staff', 'spec' => [
                'CATALOG:v', 'stock:v', 'advanced-inventory:v', 'barcodes:v',
                'sales:c', 'sales-returns:c', 'payments:c',
                'customers:c', 'loyalty:v', 'coupons:v', 'gift-vouchers:v',
                'notifications:v',
            ]],

            // ---- Supermarket ----
            /**
             * Runs the floor and the front end. The distinguishing grants are
             * `SUPERMARKET:m` — the planogram and the lane bank — and
             * `pos-counters:m`, which is the authority to define tills and to
             * settle the variance when a drawer comes up short.
             *
             * Not `supplier-credit`. In a store this size, buying and paying are
             * a back office, and the person walking the aisles is not in it.
             */
            'store-manager' => ['label' => 'Store Manager', 'spec' => [
                'SUPERMARKET:m', 'pos-counters:m', 'CATALOG:m', 'SALES:a',
                'stock:m', 'stock-ledger:v', 'advanced-inventory:m', 'stock-transfers:a',
                'barcodes:m', 'customers:m', 'loyalty:m', 'coupons:m',
                'gift-vouchers:m', 'memberships:m',
                'payments:m', 'EXPENSES:c', 'employees:v', 'devices:v',
                'reports:v', 'notifications:v', 'ai:v',
                // Same pair as the showroom manager above, for the same reasons.
                'customer-feedback:m', 'sales-commissions:a',
            ]],
            /**
             * Watches the bank of self-checkout lanes. `self-checkout:a` is the
             * whole job — clearing an age-restricted basket and voiding a line a
             * customer changed their mind about — and it comes with `pos-counters:c`
             * so they can open and close the lanes at the start and end of a
             * shift.
             *
             * `sales:c` is needed and is not decoration: the supervisor is who
             * takes over a lane that has stalled and rings the basket up.
             */
            'checkout-supervisor' => ['label' => 'Checkout Supervisor', 'spec' => [
                'self-checkout:a', 'pos-counters:c', 'shelf-planogram:v',
                'sales:c', 'sales-returns:c', 'payments:c',
                'CATALOG:v', 'stock:v', 'customers:c',
                'loyalty:v', 'coupons:v', 'gift-vouchers:v', 'memberships:v',
                'notifications:v',
            ]],
            /**
             * Walks the round: reads the gap list, picks from the backroom and
             * fills the facings. `stock:c` is what actually lets them do it —
             * replenishment moves stock, and it is gated on moving stock rather
             * than on the planogram, because a filler follows the layout and does
             * not set it. Hence `shelf-planogram:v`, not `:m`.
             */
            'shelf-stocker' => ['label' => 'Shelf Stocker', 'spec' => [
                'shelf-planogram:v', 'stock:c', 'advanced-inventory:v',
                'CATALOG:v', 'barcodes:v', 'stock-transfers:c',
                'notifications:v',
            ]],
            /**
             * The lane itself — a login for the machine, not for a person.
             *
             * Deliberately the thinnest role in the catalogue, and the omissions
             * are the design: no `self-checkout:approve` (or the age check clears
             * itself), no `sales-returns`, no customer editing, no reports. A
             * device standing unattended in a shop doorway is the least trusted
             * thing in the building and should hold the fewest grants.
             */
            'self-checkout-lane' => ['label' => 'Self-Checkout Lane', 'spec' => [
                'self-checkout:c', 'products:v', 'stock:v', 'sales:c', 'payments:c',
            ]],

            // ---- Automobile ----
            // The service advisor owns the front of a workshop: they take the
            // booking, meet the vehicle, open the job card and hand back the
            // keys. Warranty is contribute rather than manage — registering
            // cover a manufacturer has given is part of taking a vehicle in;
            // voiding it is not, and that sits on delete.
            'service-advisor' => ['label' => 'Service Advisor', 'spec' => [
                'AUTOMOBILE:c', 'service-jobs:c', 'service-contracts:v',
                'customers:c', 'stock:v', 'products:v', 'sales:v',
                'employees:v', 'whatsapp:c', 'sms:c', 'reports:v', 'notifications:v',
            ]],
            // Runs the workshop end to end: prices and closes job cards, and
            // holds the warranty register outright because deciding who pays for
            // a repair is exactly this desk's job.
            'workshop-manager' => ['label' => 'Workshop Manager', 'spec' => [
                'AUTOMOBILE:m', 'SERVICE:m', 'CATALOG:v', 'stock:c', 'advanced-inventory:v',
                'customers:c', 'employees:v', 'sales:a', 'payments:v',
                'EXPENSES:c', 'reports:v', 'notifications:v', 'ai:v',
            ]],

            // ---- Electronics & Appliances ----
            /**
             * The desk that answers the phone. It logs requests, schedules
             * visits and books machines onto the register, and it reads the
             * warranty rather than writing it: promising a customer that a repair
             * is free is exactly the decision this seat should be relaying, not
             * making.
             */
            'service-desk-coordinator' => ['label' => 'Service Desk Coordinator', 'spec' => [
                'electronics-requests:c', 'electronics-units:c',
                // Registering a card is part of booking a machine in; deciding a
                // claim is not, so warranty is contribute and claims are read.
                'warranties:c', 'warranty-claims:v',
                'service-jobs:c', 'service-contracts:v',
                'customers:c', 'products:v', 'employees:v',
                'whatsapp:c', 'sms:c', 'reports:v', 'notifications:v',
            ]],
            // The fitter. They see the diary and the machine, mark an
            // installation done from someone's kitchen floor, and work the job
            // card — but they do not open the book or decide what is covered.
            'installation-technician' => ['label' => 'Installation Technician', 'spec' => [
                'electronics-requests:v', 'electronics-units:c', 'warranties:v',
                'service-jobs:c', 'stock:v', 'products:v', 'customers:v', 'notifications:v',
            ]],
            /**
             * Runs the shop end to end and holds the warranty register outright,
             * because deciding who pays for a repair — and settling or voiding a
             * claim — is precisely this desk's job.
             */
            'electronics-store-manager' => ['label' => 'Electronics Store Manager', 'spec' => [
                'ELECTRONICS:m', 'WARRANTY:m', 'SERVICE:m', 'CATALOG:v', 'stock:c', 'advanced-inventory:v',
                'customers:c', 'employees:v', 'sales:a', 'payments:v',
                'EXPENSES:c', 'reports:v', 'notifications:v', 'ai:v',
            ]],

            // ---- Computer & IT ----
            /**
             * The support desk. Logs calls, schedules engineers, keeps the
             * install base straight — and reads cover rather than writing it,
             * because telling a customer their repair is free is the decision
             * this seat relays, not the one it takes.
             *
             * Licences are read-only here on purpose: a coordinator needs to see
             * that a machine has a Windows key on it, and does not need to be
             * able to issue one.
             */
            'it-support-coordinator' => ['label' => 'IT Support Coordinator', 'spec' => [
                'it-service-calls:c', 'it-assets:c', 'it-warranties:v', 'it-licences:v',
                'service-jobs:c', 'service-contracts:v',
                'customers:c', 'products:v', 'employees:v',
                'whatsapp:c', 'sms:c', 'reports:v', 'notifications:v',
            ]],
            /**
             * The engineer who actually goes out. They work the calls assigned
             * to them and the job cards on the bench, and they update the machine
             * they are standing in front of — but they do not open the call book,
             * decide what is covered, or touch a licence key.
             */
            'it-field-engineer' => ['label' => 'IT Field Engineer', 'spec' => [
                'it-service-calls:v', 'it-assets:c', 'it-warranties:v',
                'service-jobs:c', 'stock:v', 'products:v', 'customers:v', 'notifications:v',
            ]],
            /**
             * Runs the shop. Holds the warranty register outright because
             * deciding who pays for a repair is this desk's job, and holds the
             * licence register outright because issuing and revoking keys is the
             * part of the business that walks out of the door if nobody owns it.
             */
            'it-store-manager' => ['label' => 'Computer Shop Manager', 'spec' => [
                'COMPUTER_IT:m', 'it-licences:m', 'SERVICE:m', 'CATALOG:v',
                'stock:c', 'advanced-inventory:v',
                'customers:c', 'employees:v', 'sales:a', 'payments:v',
                'EXPENSES:c', 'reports:v', 'notifications:v', 'ai:v',
            ]],

            // ---- Stationery & School Supplies ----
            /**
             * The desk that owns the season. Takes the schools' lists down as
             * they arrive, keeps the accounts straight and answers tenders — so
             * it holds both registers outright and can raise a quotation or an
             * order against them.
             *
             * Sales is contribute rather than approve: this seat commits the shop
             * to supplying four hundred sets, which is the job, but signing off a
             * discounted institutional deal is the owner's.
             */
            'school-supply-coordinator' => ['label' => 'School Supply Coordinator', 'spec' => [
                'STATIONERY:m', 'sales:c', 'price-lists:v',
                'customers:c', 'products:v', 'stock:v', 'advanced-inventory:v',
                'purchase-indents:c', 'whatsapp:c', 'sms:c', 'reports:v', 'notifications:v',
            ]],
            /**
             * The counter in June. Serves parents against a published list all
             * day and needs to read every one of them — but must not be able to
             * change what a school's list contains or what its parents were
             * promised, because both re-price sets that have already been sold.
             *
             * Read-only on the registers, contribute on the till. That is the
             * whole of this seat.
             */
            'stationery-counter-staff' => ['label' => 'Stationery Counter Staff', 'spec' => [
                'stationery-kits:v', 'stationery-institutions:v',
                'sales:c', 'sales-returns:c', 'customers:c', 'products:v',
                'stock:v', 'barcodes:v', 'loyalty:c', 'notifications:v',
            ]],
            /**
             * Runs the shop. Holds the institution register outright because the
             * two discount rates on it are the shop's margin for the year, and
             * holds purchasing because the season's buying list is worked off the
             * booklists — ordering short in May is the only mistake in this trade
             * that cannot be corrected later.
             */
            'stationery-store-manager' => ['label' => 'Stationery Shop Manager', 'spec' => [
                'STATIONERY:m', 'CATALOG:v', 'PRICE:m', 'SALES:a',
                'PURCHASE:c', 'stock:c', 'stock-ledger:v', 'advanced-inventory:v', 'barcodes:m',
                'customers:m', 'loyalty:m', 'employees:v', 'payments:v',
                'EXPENSES:c', 'reports:v', 'notifications:v', 'ai:v',
            ]],

            // ---- Gift & Novelty ----
            /**
             * The counter at Diwali. Sells, wraps, and takes the parcel in — which
             * is one continuous conversation with a customer, so the till and the
             * wrap desk are one grant.
             *
             * Two limits are deliberate. It cannot design or build hampers
             * (`gift-packs:v`, and no stock authority): what goes in a basket is a
             * merchandising decision, and building forty of them empties the store.
             * And it cannot see or touch the occasion register — a shop's list of
             * its customers' family birthdays is not counter reading, and `.edit`
             * would let a temporary festival hire text them.
             */
            'gift-counter-staff' => ['label' => 'Gift Counter Staff', 'spec' => [
                'gift-wraps:c', 'gift-packs:v',
                'sales:c', 'sales-returns:c', 'CATALOG:v', 'stock:v', 'barcodes:v',
                'customers:c', 'loyalty:c', 'gift-vouchers:c', 'notifications:v',
            ]],
            /**
             * The wrap bench itself, for a shop that puts somebody on it for the
             * season. Works the queue and nothing else — no till, no customers, no
             * prices. `gift-wraps:m` rather than `:c` because cancelling a parcel
             * (which sits on delete) is exactly this seat's call: the bench is where
             * anyone finds out that a parcel was abandoned.
             */
            'gift-wrap-assistant' => ['label' => 'Gift Wrap Assistant', 'spec' => [
                'gift-wraps:m', 'stock:v', 'products:v', 'notifications:v',
            ]],
            /**
             * Runs the shop. Holds the occasion register outright because it *is*
             * the shop's repeat business — the calls that get made in the nine days
             * before an anniversary are the difference between a gift shop and a
             * shelf of mugs — and holds stock because assembling hampers is this
             * desk's decision and its liability.
             */
            'gift-store-manager' => ['label' => 'Gift Shop Manager', 'spec' => [
                'GIFT:m', 'gift-occasions:m', 'CATALOG:m', 'PRICE:c', 'SALES:a',
                'PURCHASE:c', 'stock:m', 'stock-ledger:v', 'advanced-inventory:v', 'barcodes:m',
                'customers:m', 'loyalty:m', 'coupons:m', 'gift-vouchers:m',
                'employees:v', 'payments:v', 'whatsapp:c', 'sms:c',
                'EXPENSES:c', 'reports:v', 'notifications:v', 'ai:v',
            ]],

            // ---- Toys & Games ----
            /**
             * The counter. Sells, wraps, and answers the only question a toy shop
             * is really asked — "is this all right for a four-year-old?" — which
             * is why safety is read-only here rather than absent: the answer is
             * this seat's to *give* and not to *decide*.
             *
             * The wrap desk rides along at contribute for the same reason it does
             * in the gift shop: taking a parcel in is the end of the same
             * conversation that sold the toy.
             */
            'toy-counter-staff' => ['label' => 'Toy Counter Staff', 'spec' => [
                'toy-safety:v',
                'gift-wraps:c', 'gift-packs:v',
                'sales:c', 'sales-returns:c', 'CATALOG:v', 'stock:v', 'barcodes:v',
                'customers:c', 'loyalty:c', 'gift-vouchers:c', 'notifications:v',
            ]],
            /**
             * Runs the shop, and owns the compliance book outright — `toy-safety:m`
             * is the grant that carries withdrawing a certificate, which stops the
             * counter selling everything behind it. That is a decision about the
             * shop's licence to trade, and it belongs to whoever answers for it.
             */
            'toy-store-manager' => ['label' => 'Toy Shop Manager', 'spec' => [
                'toy-safety:m', 'GIFT:m', 'CATALOG:m', 'PRICE:c', 'SALES:a',
                'PURCHASE:c', 'stock:m', 'stock-ledger:v', 'barcodes:m',
                'customers:m', 'loyalty:m', 'coupons:m', 'gift-vouchers:m',
                'employees:v', 'payments:v', 'whatsapp:c', 'sms:c',
                'EXPENSES:c', 'reports:v', 'notifications:v', 'ai:v',
            ]],

            // ---- Books & Periodicals ----
            /**
             * The counter. Sells, and looks a title up by the number on the back
             * cover — which is the whole of what a bookshop counter is asked to
             * do, in front of the customer, several times an hour.
             *
             * The catalogue is read-only here rather than absent for the same
             * reason toy safety is: the answer to "do you have this?" is this
             * seat's to *give* and not to *decide*. Cataloguing a title, choosing
             * its house and spelling its translator's name is the next role down.
             */
            'bookshop-counter-staff' => ['label' => 'Bookshop Counter Staff', 'spec' => [
                'book-catalogue:v',
                'sales:c', 'sales-returns:c', 'CATALOG:v', 'stock:v', 'barcodes:v',
                'customers:c', 'loyalty:c', 'gift-vouchers:c', 'notifications:v',
            ]],
            /**
             * The person who buys and catalogues. `book-catalogue:m` is the whole
             * register — houses, people, languages and credits — and `CATALOG:m`
             * beside it because in this trade they are the same act: a title is
             * created as a product and catalogued in the same sitting, and a seat
             * that can do one and not the other cannot finish a carton.
             *
             * `PURCHASE:m` rather than `:c`, unusually for a shop role, because
             * the buying *is* the job here: what a bookshop stocks is the only
             * thing that distinguishes it from the one down the road.
             */
            'bookshop-buyer' => ['label' => 'Bookshop Buyer / Cataloguer', 'spec' => [
                'book-catalogue:m', 'CATALOG:m', 'PRICE:c',
                'PURCHASE:m', 'stock:m', 'stock-ledger:v', 'barcodes:m',
                'sales:v', 'reports:v', 'notifications:v', 'ai:v',
            ]],
            /**
             * Runs the shop. Everything the buyer has, plus the money and the
             * channel: a book shop's online orders are a real part of its trade
             * and the person answering for the month's numbers is the one who
             * should see them.
             */
            'bookshop-manager' => ['label' => 'Bookshop Manager', 'spec' => [
                'book-catalogue:m', 'CATALOG:m', 'PRICE:c', 'SALES:a',
                'PURCHASE:c', 'stock:m', 'stock-ledger:v', 'barcodes:m',
                'customers:m', 'loyalty:m', 'coupons:m', 'gift-vouchers:m',
                'employees:v', 'payments:v', 'whatsapp:c', 'sms:c',
                'EXPENSES:c', 'reports:v', 'notifications:v', 'ai:v',
            ]],

            // ---- Beauty & Personal Care ----
            /**
             * The counter. Sells, matches a shade, and opens testers — which is
             * most of what happens at a beauty counter all day.
             *
             * Two limits are deliberate. Shades are read-only: the assistant
             * *uses* the card in front of the customer and does not decide which
             * shades the shop carries, which is a buying decision with forty SKUs
             * behind it. Shelf life is contribute, not manage: opening a tester
             * and marking one finished is the job, while changing whether the
             * till refuses expired stock is not — that grant sits on `.delete`
             * and belongs to the manager below.
             */
            'beauty-counter-staff' => ['label' => 'Beauty Counter Staff', 'spec' => [
                'cosmetic-shades:v', 'cosmetic-shelf-life:c',
                'sales:c', 'sales-returns:c', 'CATALOG:v', 'stock:v', 'barcodes:v',
                'customers:c', 'loyalty:c', 'memberships:v', 'gift-vouchers:c', 'notifications:v',
            ]],
            /**
             * Runs the counter and buys for it. `cosmetic-shelf-life:m` is the
             * grant that carries the policy — whether an expired line stops a
             * bill — which is a decision about the shop's licence to trade and
             * belongs to whoever answers for it, exactly as certificate
             * withdrawal does in the toy shop.
             */
            'beauty-store-manager' => ['label' => 'Beauty Store Manager', 'spec' => [
                'BEAUTY:m', 'CATALOG:m', 'PRICE:c', 'SALES:a',
                'PURCHASE:c', 'stock:m', 'stock-ledger:v', 'barcodes:m',
                'customers:m', 'loyalty:m', 'coupons:m', 'memberships:m', 'gift-vouchers:m',
                'employees:v', 'payments:v', 'whatsapp:c', 'sms:c',
                'EXPENSES:c', 'reports:v', 'notifications:v', 'ai:v',
            ]],

            // ---- Sports & Fitness ----
            /**
             * The hire counter. Books kit, hands it over, takes it back and settles
             * it — the whole of a hire desk's day, all of it with a customer
             * standing there.
             *
             * Three limits are deliberate. The fleet is read-only: this seat needs
             * to see what a bike costs by the day and what deposit it carries, and
             * has no business repricing it or writing it off. `sales:c` is here
             * because settling a hire raises an invoice, and a desk that could take
             * kit back but not bill for it would leave every contract half-finished
             * for somebody else to close. And `service-jobs:c` because the bike that
             * comes back with a bent wheel is booked onto the bench by the person
             * who took it back and looked at it.
             */
            'sports-hire-desk' => ['label' => 'Hire Desk Staff', 'spec' => [
                'rental-assets:v', 'rentals:c',
                'service-jobs:c', 'warranties:c', 'warranty-claims:v',
                'sales:c', 'sales-returns:c', 'CATALOG:v', 'stock:v', 'barcodes:v',
                'customers:c', 'loyalty:c', 'gift-vouchers:c', 'notifications:v',
            ]],
            /**
             * Runs the shop and owns the fleet. `rental-assets:m` is the grant that
             * carries the rate card, the deposit against each piece of kit and
             * writing one off — decisions about the shop's capital, which belong to
             * whoever answers for it. `rentals:m` adds cancelling a booking.
             */
            'sports-store-manager' => ['label' => 'Sports Shop Manager', 'spec' => [
                'HIRE:m', 'SERVICE:m', 'WARRANTY:c', 'CATALOG:m', 'PRICE:c', 'SALES:a',
                'PURCHASE:c', 'stock:m', 'stock-ledger:v', 'barcodes:m',
                'customers:m', 'loyalty:m', 'coupons:m', 'gift-vouchers:m',
                'employees:v', 'payments:v', 'whatsapp:c', 'sms:c',
                'EXPENSES:c', 'reports:v', 'notifications:v', 'ai:v',
            ]],

            // ---- Mobile ----
            /**
             * The counter. Sells handsets and accessories, books IMEIs in, writes
             * cover and quotes a trade-in — which is the whole conversation at a
             * phone shop counter, all of it in front of the customer.
             *
             * Two limits are deliberate. Warranty is contribute, not manage:
             * issuing a card is part of finishing a sale, while deciding a claim is
             * not. And the finance book is read-only — a salesman needs to answer
             * "how many EMIs are left?" and has no business collecting against a
             * file or waiving an instalment.
             */
            'mobile-counter-executive' => ['label' => 'Mobile Counter Executive', 'spec' => [
                'mobile-handsets:c', 'mobile-insurance:c', 'mobile-buyback:c', 'mobile-emi:v',
                'warranties:c', 'warranty-claims:v',
                'service-jobs:c', 'sales:c', 'CATALOG:v', 'stock:v',
                'customers:c', 'loyalty:c', 'whatsapp:c', 'sms:c',
                'reports:v', 'notifications:v',
            ]],
            /**
             * The bench. Works the repairs in front of them and updates the handset
             * they are holding, reads what is covered rather than deciding it, and
             * never sees the insurance book or the finance files.
             */
            'mobile-repair-technician' => ['label' => 'Mobile Repair Technician', 'spec' => [
                'mobile-handsets:c', 'mobile-insurance:v', 'warranties:v',
                'service-jobs:c', 'stock:v', 'products:v', 'customers:v', 'notifications:v',
            ]],
            /**
             * Runs the shop. Holds the warranty and insurance registers outright
             * because deciding who pays for a broken screen is this desk's job, and
             * holds the finance book outright because writing off an instalment is
             * the part of this business that leaks money if nobody owns it.
             */
            'mobile-store-manager' => ['label' => 'Mobile Shop Manager', 'spec' => [
                'MOBILE:m', 'mobile-emi:m', 'WARRANTY:m', 'SERVICE:m', 'CATALOG:v',
                'stock:c', 'advanced-inventory:v',
                'customers:c', 'employees:v', 'sales:a', 'payments:c',
                'EXPENSES:c', 'reports:v', 'notifications:v', 'ai:v',
            ]],

            // ---- Restaurant ----
            'restaurant-manager' => ['label' => 'Restaurant Manager', 'spec' => [
                'RESTAURANT:m', 'sales:a', 'CATALOG:m', 'stock:v', 'EXPENSES:c',
                'customers:c', 'employees:v', 'payments:c', 'reports:v', 'notifications:v', 'ai:v',
                // Club cards are sold and honoured at the same counter the bills
                // are settled at.
                'MEMBERSHIP:m', 'loyalty:c',
            ]],
            // A waiter opens tables and fires food but cannot settle a bill or
            // change the menu. They read the menu to know what is off tonight.
            'waiter' => ['label' => 'Waiter / Steward', 'spec' => [
                'restaurant-tables:v', 'restaurant-orders:c', 'kitchen-orders:v',
                'restaurant-menu:v', 'products:v', 'customers:c', 'notifications:v',
            ]],
            // The kitchen needs its own board, the recipes it cooks from, and the
            // ability to mark a dish sold out — the pass is where that is known
            // first, and routing it through a manager just delays the bad news.
            'kitchen-staff' => ['label' => 'Kitchen Staff', 'spec' => [
                'kitchen-orders:c', 'restaurant-orders:v', 'restaurant-menu:c',
                'restaurant-recipes:v', 'products:v', 'notifications:v',
            ]],

            // ---- Bakery / counter-service F&B ----
            /**
             * The cake book is the bakery's own document. A baker takes the order,
             * prices it and hands it over, so they hold the book and the authority
             * to raise the sale — but not the menu, the recipes or the kitchen
             * boards, none of which a counter bakery runs.
             */
            'bakery-manager' => ['label' => 'Bakery Manager', 'spec' => [
                'BAKERY:m', 'sales:a', 'CATALOG:m', 'stock:c', 'advanced-inventory:v',
                'customers:c', 'payments:c', 'MEMBERSHIP:c', 'boms:v', 'manufacturing-orders:c',
                'reports:v', 'notifications:v', 'ai:v',
            ]],
            // Takes orders at the counter and works the board; cannot bill, which
            // is what keeps the advance and the handover on the manager's authority.
            'bakery-counter' => ['label' => 'Bakery Counter Staff', 'spec' => [
                'cake-orders:c', 'products:v', 'customers:c', 'memberships:v', 'notifications:v',
            ]],

            // ---- Dairy ----
            /**
             * The dairy office holds both halves: what came in off the round and
             * what goes out on standing order. Settling farmers and billing
             * households are both money, so both carry the invoice permissions
             * rather than the entry ones.
             */
            'dairy-manager' => ['label' => 'Dairy Manager', 'spec' => [
                'DAIRY:m', 'purchase-invoices:a', 'sales:a', 'suppliers:c', 'customers:c',
                'CATALOG:m', 'stock:c', 'delivery-routes:c', 'payments:c',
                'reports:v', 'notifications:v', 'ai:v',
            ]],
            /**
             * The collection counter: keys cans and reads the chart, and cannot
             * settle a farmer or touch the delivery round. The chart itself is
             * view-only by way of milk-collections:c — creating a chart is
             * :m — because the rate governs every slip in the centre.
             */
            'milk-collector' => ['label' => 'Milk Collection Clerk', 'spec' => [
                'milk-collections:c', 'suppliers:v', 'products:v', 'notifications:v',
            ]],
            // Walks the round and corrects the sheet. No billing, no standing-order
            // edits — a delivery boy changing a household's rate is not a thing,
            // which is exactly why the sheet has its own permission.
            'milk-delivery' => ['label' => 'Milk Delivery Staff', 'spec' => [
                'milk-round:c', 'milk-subscriptions:v', 'customers:v', 'notifications:v',
            ]],

            // ---- Optical ----
            // The optometrist tests eyes and records powers; the dispensing
            // optician turns a test into a pair of spectacles.
            'optometrist' => ['label' => 'Optometrist', 'spec' => [
                'eye-prescriptions:m', 'optical-orders:v', 'customers:c',
                // Works their own diary: marks patients in and closes the slot
                // against the test it produced. Cannot delete a slot — a
                // cancelled clinic is the practice's decision, not the chair's.
                'optical-appointments:e', 'products:v', 'notifications:v',
            ]],
            'optician' => ['label' => 'Dispensing Optician', 'spec' => [
                'OPTICAL:m', 'sales:c', 'CATALOG:c', 'stock:v',
                'customers:c', 'payments:v', 'reports:v', 'notifications:v',
                // Hands pairs over, which raises the invoice and grants the
                // cover — so the register that cover lands in has to be readable
                // by the person who granted it.
                'warranties:v',
            ]],
            /**
             * The front desk. Books the chair, rings the customer whose
             * spectacles are ready, and hands them over — but may not record what
             * a test found, which is the optometrist's clinical judgement and the
             * one thing in this shop a receptionist must not be able to write.
             */
            'optical-receptionist' => ['label' => 'Optical Receptionist', 'spec' => [
                'optical-appointments:m', 'eye-prescriptions:v', 'optical-orders:e',
                'customers:c', 'products:v', 'notifications:v',
            ]],

            // ---- Jewellery ----
            // Quoting the morning rate governs every bill in the shop, so it
            // sits with the owner/manager rather than with the counter.
            'jewellery-manager' => ['label' => 'Jewellery Manager', 'spec' => [
                'JEWELLERY:m', 'sales:a', 'CATALOG:m', 'stock:v', 'advanced-inventory:v',
                'customers:c', 'payments:c', 'reports:v', 'notifications:v', 'ai:v',
            ]],
            'jewellery-salesman' => ['label' => 'Jewellery Salesman', 'spec' => [
                'metal-rates:v', 'old-gold:c', 'sales:c', 'products:v', 'stock:v',
                'customers:c', 'notifications:v',
            ]],

            // ---- Pharmacy ----
            // A registered pharmacist dispenses and answers for the register;
            // a counter assistant can write a slip and sell against it but not
            // produce the statutory record.
            'pharmacist' => ['label' => 'Pharmacist', 'spec' => [
                'PHARMACY:m', 'sales:c', 'stock:v', 'advanced-inventory:v', 'CATALOG:c',
                'customers:c', 'reports:v', 'notifications:v',
            ]],
            'pharmacy-assistant' => ['label' => 'Pharmacy Assistant', 'spec' => [
                'prescriptions:c', 'sales:c', 'stock:v', 'products:v',
                'customers:c', 'notifications:v',
            ]],

            // ---- Diagnostic Lab ----
            /**
             * The pathologist owns the test definitions and is the only one who
             * signs a report off — which is the same authority as billing it, so
             * lab-orders is granted at approve level here and nowhere below.
             */
            'pathologist' => ['label' => 'Pathologist / Lab Manager', 'spec' => [
                'lab-tests:m', 'lab-orders:a', 'customers:c', 'prescriptions:v',
                'sales:v', 'payments:v', 'reports:v', 'notifications:v', 'ai:v',
            ]],
            /**
             * A technician runs the bench: registers patients, marks samples in
             * and types values. Deliberately no approve — the whole point of
             * verification is that the person who entered the number is not the
             * person who stands behind it.
             */
            'lab-technician' => ['label' => 'Lab Technician', 'spec' => [
                'lab-orders:c', 'lab-tests:v', 'customers:v', 'notifications:v',
            ]],
            // The desk that registers walk-ins and hands reports over. Cannot
            // touch a result.
            'lab-receptionist' => ['label' => 'Lab Receptionist', 'spec' => [
                'lab-orders:c', 'lab-tests:v', 'customers:c', 'sales:v',
                'payments:v', 'notifications:v',
            ]],

            // ---- Hospital Store ----
            /**
             * The store keeper approves and issues; a ward sister raises. Split
             * across two roles rather than one so that requesting stock for your
             * own department and releasing it are never the same signature.
             */
            'hospital-store-keeper' => ['label' => 'Hospital Store Keeper', 'spec' => [
                'hospital-indents:a', 'INVENTORY:c', 'CATALOG:v', 'departments:v',
                'advanced-inventory:v', 'purchase-indents:c', 'reports:v', 'notifications:v',
            ]],
            'ward-in-charge' => ['label' => 'Ward In-charge', 'spec' => [
                // warehouses:v is not decoration — raising an indent means
                // picking the store to draw from, and without it the form's
                // first dropdown 403s.
                'hospital-indents:c', 'products:v', 'stock:v', 'warehouses:v',
                'departments:v', 'prescriptions:v', 'lab-orders:v', 'notifications:v',
            ]],

            // ---- Agriculture ----
            // An agri counter is a licensed shop: the person running it answers
            // for the licences as well as the till, so compliance and selling sit
            // in one role rather than being split off to a back office nobody in
            // a village shop has.
            'agri-manager' => ['label' => 'Agri Store Manager', 'spec' => [
                'AGRI:m', 'sales:a', 'CATALOG:m', 'stock:c', 'advanced-inventory:v',
                'customers:c', 'suppliers:v', 'payments:c', 'reports:v', 'notifications:v', 'ai:v',
            ]],
            // Runs the herd or the sheds: owns the registers, the daily book and
            // the feed that comes out of the store to fill it.
            'farm-manager' => ['label' => 'Farm Manager', 'spec' => [
                'LIVESTOCK:m', 'CATALOG:c', 'stock:c', 'stock-ledger:v', 'advanced-inventory:v',
                'employees:v', 'attendance:c', 'sales:c', 'purchases:v', 'suppliers:v',
                'reports:v', 'notifications:v', 'ai:v',
            ]],
            // The milker at 5am. Keys the day's output — which is what moves stock
            // — but does not decide which animal is culled or what a batch cost.
            'farm-worker' => ['label' => 'Farm Worker / Milker', 'spec' => [
                'farm-production:c', 'livestock-animals:v', 'livestock-flocks:v',
                'products:v', 'stock:v', 'notifications:v',
            ]],

            // ---- Projects ----
            'project-manager' => ['label' => 'Project Manager', 'spec' => [
                'PROJECTS:m', 'project-timesheets:a', 'customers:v', 'employees:v',
                'expenses:c', 'sales:v', 'payments:v', 'reports:v', 'notifications:v', 'ai:v',
            ]],
            // A member works the board and logs their own hours; they cannot
            // approve those hours or touch the budget they are spending.
            'project-member' => ['label' => 'Project Member', 'spec' => [
                'projects:v', 'project-tasks:c', 'project-timesheets:c',
                'customers:v', 'notifications:v',
            ]],

            // ---- Broker ----
            'broker' => ['label' => 'Broker', 'spec' => [
                'brokers:v', 'sales:v', 'b2b-orders:v', 'reports:v', 'notifications:v',
            ]],
            'broker-manager' => ['label' => 'Broker Manager', 'spec' => [
                'brokers:m', 'sales:v', 'b2b-orders:v', 'payments:c', 'reports:v', 'notifications:v',
            ]],

            // ---- Finance ----
            'finance-manager' => ['label' => 'Finance Manager', 'spec' => [
                'EXPENSES:a', 'TREASURY:m', 'GST:m', 'accounts:v', 'reports:v',
                'purchase-invoices:a', 'sales:v', 'purchases:v', 'financial-years:m',
                'payroll:v', 'salaries:v', 'suppliers:v', 'customers:v', 'notifications:v', 'ai:v',
                // Distribution: whose limit gets raised and whose supply stops is
                // this desk's call, so it holds the lever, not just the view.
                'credit-control:c',
                // The file that goes to the shop's chartered accountant. This is
                // the desk that hands it over, so it is the desk that holds it.
                'accounting-exports:v',
                // The commission book, read-only: it is a cost this desk must see
                // in the month's numbers, and a scheme it does not set or settle.
                'sales-commissions:v',
            ]],
            'senior-accountant' => ['label' => 'Senior Accountant', 'spec' => [
                'EXPENSES:a', 'TREASURY:m', 'GST:m', 'accounts:v', 'reports:v',
                'purchase-invoices:a', 'purchases:v', 'sales:v', 'suppliers:v', 'customers:v', 'notifications:v',
                'credit-control:c',
                // Whoever closes the books is who the CA asks for the Tally file.
                'accounting-exports:v',
            ]],
            'cashier' => ['label' => 'Cashier', 'spec' => [
                'payments:c', 'vouchers:c', 'bank-accounts:v', 'expenses:c', 'accounts:v',
                'sales:v', 'customers:v', 'devices:v', 'device-events:v', 'notifications:v',
                // Read-only: the counter needs to know a party is stopped before
                // billing them, and must not be the one who un-stops them.
                'credit-control:v',
                /**
                 * Issuing the card is part of finishing the sale, so the counter
                 * holds it. Claims are view-only from here — the till is not
                 * where a customer's complaint gets adjudicated.
                 */
                'warranties:c', 'warranty-claims:v',
            ]],

            // ---- HR ----
            'hr-manager' => ['label' => 'HR Manager', 'spec' => [
                'HR:a', 'DEVICES:m', 'reports:v', 'notifications:v', 'ai:v',
                /**
                 * Sales commission at approve level: this is the desk that agrees
                 * the month's figures and settles them with the payroll run. The
                 * *rates* are the owner's — `:a` includes edit, which is correcting
                 * an open earning, and that is a real part of this job when a sale
                 * was credited to the wrong person.
                 */
                'sales-commissions:a',
            ]],
            'hr-executive' => ['label' => 'HR Executive', 'spec' => [
                'employees:c', 'attendance:c', 'leave-applications:c', 'overtime:c', 'leave-types:v',
                'salaries:v', 'biometric-punches:v', 'departments:v', 'notifications:v',
            ]],
            'payroll-executive' => ['label' => 'Payroll Executive', 'spec' => [
                'payroll:c', 'salaries:c', 'salary-structures:c', 'salary-advances:c', 'employee-loans:c',
                'employee-bonuses:c', 'attendance:v', 'employees:v', 'bank-accounts:v', 'notifications:v',
                /**
                 * Read-only on commission, deliberately. This desk needs the
                 * month's figures in order to pay them, and must not be able to
                 * change what the shop promised or to sign its own numbers off.
                 * Settling is the HR manager's grant above.
                 */
                'sales-commissions:v',
            ]],

            // ---- Transport ----
            'transport-manager' => ['label' => 'Transport Manager', 'spec' => [
                'TRANSPORT:m', 'EXIM:m', 'EXIM_SHIPMENTS:m', 'vehicles:m', 'vehicle-expenses:a', 'fuel-expenses:a',
                'reports:v', 'notifications:v',
            ]],
            'dispatch-executive' => ['label' => 'Dispatch Executive', 'spec' => [
                'dispatch-plans:m', 'dispatch-pods:m', 'delivery-routes:m', 'transport-charges:c',
                'drivers:v', 'vehicles:v', 'gate-entries:c', 'EXIM:v', 'shipments:v', 'shipment-containers:c',
                'notifications:v',
            ]],
            // Books the inland leg — trucks between port/CFS/ICD and the
            // warehouse. Sits in Transport on the org chart but serves the
            // Import & Export desk, hence the shipment/container access.
            'transport-coordinator' => ['label' => 'Transport Coordinator', 'spec' => [
                'dispatch-plans:c', 'dispatch-pods:c', 'delivery-routes:c', 'transport-charges:c',
                'drivers:v', 'vehicles:v', 'gate-entries:c', 'warehouses:v',
                'EXIM:v', 'shipments:v', 'shipment-containers:c', 'shipment-expenses:c',
                'reports:v', 'notifications:v',
            ]],
            'driver' => ['label' => 'Driver', 'spec' => [
                'dispatch-plans:v', 'dispatch-pods:c', 'delivery-routes:v', 'vehicles:v', 'fuel-expenses:c', 'notifications:v',
            ]],
            'delivery-boy' => ['label' => 'Delivery Boy', 'spec' => [
                'dispatch-plans:v', 'dispatch-pods:c', 'delivery-routes:v', 'notifications:v',
            ]],

            // ---- Import & Export ----
            // The cross-border desk. The manager owns the whole module; the
            // executives each own one lane or one document type, which is how a
            // freight desk actually divides up: buying/selling side, paperwork,
            // and the customs filing that only a licensed CHA handles.
            //
            // Two more roles work this module from their own departments rather
            // than from here: warehouse-manager (Inventory — receives inbound
            // cargo, seeded with its hand-tuned set in RolePermissionSeeder) and
            // transport-coordinator (Transport — the inland leg).
            'logistics-manager' => ['label' => 'Logistics Manager', 'spec' => [
                'EXIM:m', 'EXIM_SHIPMENTS:m', 'TRANSPORT:m', 'suppliers:v', 'customers:v',
                'purchases:v', 'sales:v', 'stock:v', 'warehouses:v',
                'reports:v', 'workflows:v', 'notifications:v', 'ai:v',
            ]],
            'import-executive' => ['label' => 'Import Executive', 'spec' => [
                'EXIM:v', 'shipments:c', 'shipment-containers:c', 'shipment-documents:c',
                'shipment-expenses:c', 'shipment-customs:v', 'shipment-payments:v',
                'suppliers:v', 'purchases:v', 'purchase-invoices:v', 'stock:v', 'warehouses:v',
                'reports:v', 'notifications:v',
            ]],
            'export-executive' => ['label' => 'Export Executive', 'spec' => [
                'EXIM:v', 'shipments:c', 'shipment-containers:c', 'shipment-documents:c',
                'shipment-expenses:c', 'shipment-customs:v', 'shipment-payments:v',
                'customers:v', 'sales:v', 'stock:v', 'warehouses:v',
                'reports:v', 'notifications:v',
            ]],
            'documentation-executive' => ['label' => 'Documentation Executive', 'spec' => [
                'shipments:v', 'shipment-documents:m', 'shipment-containers:v', 'shipment-customs:v',
                'EXIM:v', 'suppliers:v', 'customers:v', 'email:c', 'notifications:v',
            ]],
            'cha-executive' => ['label' => 'CHA Executive', 'spec' => [
                'shipments:v', 'shipment-customs:m', 'shipment-documents:c', 'shipment-containers:c',
                'shipment-expenses:c', 'EXIM:v', 'notifications:v',
            ]],

            // ---- Administration ----
            'office-admin' => ['label' => 'Office Admin', 'spec' => [
                'users:v', 'employees:c', 'attendance:c', 'departments:v', 'ASSETS:c', 'expenses:c',
                'companies:v', 'branches:v', 'settings:v', 'DEVICES:m', 'notifications:v',
            ]],
            'receptionist' => ['label' => 'Receptionist', 'spec' => [
                'customers:v', 'leads:c', 'follow-ups:c', 'employees:v', 'attendance:v',
                'whatsapp:c', 'sms:c', 'notifications:v',
            ]],
        ];
    }

    /**
     * Expand a role spec into concrete "module.action" permission names,
     * skipping actions a module doesn't support. Returns null for a ['*'] spec
     * (meaning "grant everything"), which the caller handles specially.
     *
     * @param  array<int, string>  $spec
     * @return array<int, string>|null
     */
    public static function expand(array $spec): ?array
    {
        if ($spec === ['*']) {
            return null;
        }

        $groups = self::groups();
        $out = [];

        foreach ($spec as $entry) {
            [$token, $level] = array_pad(explode(':', $entry, 2), 2, 'v');
            $modules = $groups[$token] ?? [$token];

            foreach ($modules as $module) {
                foreach (self::levelActions($level) as $action) {
                    if (self::permissionExists($module, $action)) {
                        $out[] = "{$module}.{$action}";
                    }
                }
            }
        }

        return array_values(array_unique($out));
    }

    /** Whether a given module/action pair is a real permission. */
    private static function permissionExists(string $module, string $action): bool
    {
        if (in_array($module, self::VIEW_ONLY_MODULES, true)) {
            return $action === 'view';
        }

        if (! in_array($module, self::MODULES, true)) {
            return false;
        }

        if ($action === 'approve') {
            return in_array($module, self::APPROVABLE, true);
        }

        return in_array($action, ['view', 'create', 'edit', 'delete'], true);
    }
}
