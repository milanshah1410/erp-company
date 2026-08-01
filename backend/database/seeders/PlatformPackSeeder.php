<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\PlatformPack;
use Illuminate\Database\Seeder;

/**
 * The shipped module marketplace (Volume 11 §Module Marketplace): Industry Packs,
 * Feature Packs and AI Packs.
 *
 * Every pack grants entitlement the product already has — module keys from
 * App\Support\Modules and bumps to the caps App\Support\TenantLimits meters — so
 * nothing here can promise a capability that does not exist.
 *
 * The catalogue below is the *default offering*, not the source of truth: prices,
 * bundling and the module mix are all editable in the panel (Marketplace → Packs),
 * where changes are audit-logged. Re-running this seeder rewrites the packs it
 * owns, so a per-deployment price change should be made in the panel and re-seeded
 * with care.
 *
 * The `included_in_plans` lists are what keep this honest against PlanSeeder: an
 * industry pack whose modules the Business tier already sells is *bundled* with
 * Business rather than sold twice — a workspace on that tier gets the entitlement
 * with no grant row, and only a lower tier has anything to buy.
 */
class PlatformPackSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->packs() as $pack) {
            PlatformPack::updateOrCreate(['slug' => $pack['slug']], $pack);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function packs(): array
    {
        return [
            // --- Industry packs -------------------------------------------------
            // One per vertical the product has real screens for. Each is bundled
            // with the tier that already sells its modules, so the pack only sells
            // to workspaces below that tier.
            [
                'slug' => 'restaurant-pack',
                'name' => 'Restaurant Pack',
                'type' => 'industry',
                'tagline' => 'Tables, kitchen tickets and running bills',
                'description' => 'Table management, KOT printing, running bills and the QR menu — everything a dine-in or takeaway counter needs on top of POS.',
                'icon' => 'pi pi-shop',
                'price' => 499,
                'billing_cycle' => 'monthly',
                'modules' => ['restaurant'],
                'industry_key' => 'restaurant',
                'included_in_plans' => ['business', 'professional', 'enterprise'],
                'sort_order' => 10,
            ],
            [
                'slug' => 'pharmacy-pack',
                'name' => 'Pharmacy Pack',
                'type' => 'industry',
                'tagline' => 'Drug schedules, prescriptions and the H1/X register',
                'description' => 'Batch + expiry-aware dispensing, prescription capture and the Schedule H1/X register a pharmacy inspection asks for.',
                'icon' => 'pi pi-heart-fill',
                'price' => 499,
                'billing_cycle' => 'monthly',
                'modules' => ['pharmacy'],
                'industry_key' => 'healthcare',
                'included_in_plans' => ['business', 'professional', 'enterprise'],
                'sort_order' => 11,
            ],
            [
                'slug' => 'jewellery-pack',
                'name' => 'Jewellery Pack',
                'type' => 'industry',
                'tagline' => 'Daily metal rates, purity and old-gold exchange',
                'description' => 'Rate-of-the-day pricing, purity and making charges, and old-gold exchange against a new sale.',
                'icon' => 'pi pi-star',
                'price' => 699,
                'billing_cycle' => 'monthly',
                'modules' => ['jewellery'],
                'industry_key' => 'retail',
                'included_in_plans' => ['business', 'professional', 'enterprise'],
                'sort_order' => 12,
            ],
            [
                'slug' => 'optical-pack',
                'name' => 'Optical Pack',
                'type' => 'industry',
                'tagline' => 'Eye powers, lens specs and spectacle orders',
                'description' => 'Prescription powers, lens specifications and the spectacle order flow from measurement to fitting.',
                'icon' => 'pi pi-eye',
                'price' => 499,
                'billing_cycle' => 'monthly',
                'modules' => ['optical'],
                'industry_key' => 'retail',
                'included_in_plans' => ['business', 'professional', 'enterprise'],
                'sort_order' => 13,
            ],
            /**
             * The shop floor on its own, for the eight non-garment trades the
             * Manufacturing vertical sells — plastic, steel, packaging,
             * chemical, printing, furniture, food and the general factory.
             *
             * It exists because `manufacturing` is a Professional-tier module
             * and the garment pack below is the only thing that unlocks it, so
             * a three-machine moulding unit on Business had to buy the whole
             * textile chain — fabric rolls, tailors, embroidery vendors — to get
             * a BOM and a QC register. Priced like the other single-module
             * industry packs rather than like that chain.
             */
            [
                'slug' => 'manufacturing-pack',
                'name' => 'Manufacturing Pack',
                'type' => 'industry',
                'tagline' => 'BOMs, production runs, machines, QC, scrap and costing',
                'description' => 'Bills of materials and production orders, the machine master with downtime and utilisation, quality inspections with defect analysis, scrap and rejection with recovery, and the finished-cost breakdown behind them.',
                'icon' => 'pi pi-cog',
                'price' => 999,
                'billing_cycle' => 'monthly',
                'modules' => ['manufacturing'],
                'industry_key' => 'manufacturing',
                // Professional already sells `manufacturing`, so only Business
                // and below have anything to buy here.
                'included_in_plans' => ['professional', 'enterprise'],
                'sort_order' => 14,
            ],
            [
                'slug' => 'textile-manufacturing-pack',
                'name' => 'Textile & Garment Pack',
                'type' => 'industry',
                'tagline' => 'Fabric rolls, cutting, tailoring and embroidery',
                'description' => 'Roll inspection and shrinkage, cutting plans, piece-rate tailoring and embroidery job work — the garment production chain end to end, on top of the manufacturing shop floor.',
                'icon' => 'pi pi-th-large',
                'price' => 1499,
                'billing_cycle' => 'monthly',
                'modules' => ['textile', 'tailoring', 'embroidery', 'manufacturing'],
                'industry_key' => 'manufacturing',
                // Left as shipped. Strictly, Professional sells `manufacturing`
                // and `textile` but not `tailoring` or `embroidery`, so this
                // bundling is more generous than the rule above describes —
                // narrowing it would take entitlement away from subscribers who
                // already have it, which is a pricing decision, not a fix.
                'included_in_plans' => ['professional', 'enterprise'],
                'sort_order' => 15,
            ],
            [
                'slug' => 'logistics-exim-pack',
                'name' => 'Logistics & EXIM Pack',
                'type' => 'industry',
                'tagline' => 'Dispatch planning, proof of delivery, import & export',
                'description' => 'Vehicles and drivers, dispatch planning and proof of delivery, plus the import/export masters and shipment file.',
                'icon' => 'pi pi-truck',
                'price' => 999,
                'billing_cycle' => 'monthly',
                'modules' => ['logistics'],
                'industry_key' => 'logistics',
                'included_in_plans' => ['business', 'professional', 'enterprise'],
                'sort_order' => 15,
            ],
            /*
             * Healthcare's other two. Sold apart from the Pharmacy pack and from
             * each other because the trades genuinely are apart: a standalone
             * pathology lab has no wards to issue to, and a nursing home may run
             * a store with no lab of its own. Bundling all three would charge a
             * two-room lab for a hospital store it will never open.
             */
            [
                'slug' => 'diagnostics-pack',
                'name' => 'Diagnostic Lab Pack',
                'type' => 'industry',
                'tagline' => 'Test master, sample collection, results and verified reports',
                'description' => 'A test and panel master with reference ranges, the registration-to-report worklist, result entry with automatic high/low flagging, and pathologist verification before a report is released.',
                'icon' => 'pi pi-filter',
                'price' => 699,
                'billing_cycle' => 'monthly',
                'modules' => ['diagnostics'],
                'industry_key' => 'healthcare',
                'included_in_plans' => ['business', 'professional', 'enterprise'],
                'sort_order' => 16,
            ],
            [
                'slug' => 'hospital-store-pack',
                'name' => 'Hospital Store Pack',
                'type' => 'industry',
                'tagline' => 'Ward indents, department-wise issue and consumption',
                'description' => 'The central store\'s side of a hospital: wards raise indents, the store approves and issues against them, and consumption is reported by department at the cost the stock actually left at.',
                'icon' => 'pi pi-building',
                'price' => 899,
                'billing_cycle' => 'monthly',
                'modules' => ['hospital'],
                'industry_key' => 'healthcare',
                'included_in_plans' => ['business', 'professional', 'enterprise'],
                'sort_order' => 17,
            ],

            [
                'slug' => 'distribution-pack',
                'name' => 'Distribution & Wholesale Pack',
                'type' => 'industry',
                'tagline' => 'Party credit limits, supply holds and outstanding ageing',
                'description' => 'Every party\'s limit against live exposure, a supply hold that stops the order desk, the counter and the despatch door together, and receivables aged by days past due — sliced by salesman, beat and town.',
                'icon' => 'pi pi-truck',
                'price' => 699,
                'billing_cycle' => 'monthly',
                // Sold apart from the Field Force pack on purpose: beats are how
                // a distributor reaches its parties, this is how it decides
                // whether to supply them. A cash & carry counter buys this and
                // has no salesman at all; a pharma field team buys the other and
                // may bill on cash.
                'modules' => ['distribution'],
                'industry_key' => 'distribution',
                'included_in_plans' => ['business', 'professional', 'enterprise'],
                'sort_order' => 18,
            ],
            [
                'slug' => 'agriculture-pack',
                'name' => 'Agri Inputs Pack',
                'type' => 'industry',
                'tagline' => 'Input licences, crop-wise stock and technical names',
                'description' => 'The licence register a seed, fertiliser or pesticide dealer is inspected against, plus crop, variety, nutrient grade and technical name on the product master.',
                'icon' => 'pi pi-sun',
                'price' => 499,
                'billing_cycle' => 'monthly',
                'modules' => ['agriculture'],
                'industry_key' => 'agriculture',
                'included_in_plans' => ['business', 'professional', 'enterprise'],
                'sort_order' => 16,
            ],
            [
                'slug' => 'livestock-pack',
                'name' => 'Livestock & Poultry Pack',
                'type' => 'industry',
                'tagline' => 'Herd and flock registers, daily yield, feed and mortality',
                'description' => 'Per-animal milk yield with fat/SNF, flock batches with mortality and lay percentage, and a daily book that posts produce into stock and draws feed out of it.',
                'icon' => 'pi pi-heart',
                'price' => 699,
                'billing_cycle' => 'monthly',
                // Sold apart from the Agri Inputs pack on purpose: a dairy farm
                // holds no dealer licences, and a fertiliser counter has no
                // animals. Bundling them would make each trade pay for the
                // other's screens.
                'modules' => ['livestock'],
                'industry_key' => 'agriculture',
                'included_in_plans' => ['business', 'professional', 'enterprise'],
                'sort_order' => 17,
            ],
            [
                'slug' => 'automobile-pack',
                'name' => 'Automobile Pack',
                'type' => 'industry',
                'tagline' => 'Vehicle register, service bookings, job cards and warranty',
                'description' => 'A VIN-keyed vehicle register with service history and papers, the appointment book that feeds it, and warranty tracked on both limbs — time and distance — so a claim is settled from the record rather than from memory.',
                'icon' => 'pi pi-car',
                'price' => 699,
                'billing_cycle' => 'monthly',
                /**
                 * `service` rides along because the pack would be hollow without
                 * it: a job card *is* a service job, and a workshop that bought
                 * the vehicle register without the job book could look a car up
                 * and then do nothing to it. A parts counter that wants only the
                 * registers unticks Service afterwards.
                 */
                'modules' => ['automobile', 'service'],
                'industry_key' => 'automobile',
                'included_in_plans' => ['business', 'professional', 'enterprise'],
                'sort_order' => 18,
            ],

            // --- Feature packs -------------------------------------------------
            // Cut across verticals. These are the two shapes a feature pack can
            // take: unlocking modules, and raising metered caps.
            [
                'slug' => 'multichannel-pack',
                'name' => 'Multi-channel Selling Pack',
                'type' => 'feature',
                'tagline' => 'Marketplace connectors and channel orders',
                'description' => 'Amazon, Flipkart, Meesho and ONDC connectors, the channel-order inbox, and listing sync against your own catalogue.',
                'icon' => 'pi pi-globe',
                'price' => 1299,
                'billing_cycle' => 'monthly',
                'modules' => ['ecommerce'],
                'included_in_plans' => ['professional', 'enterprise'],
                'sort_order' => 20,
            ],
            [
                'slug' => 'field-force-pack',
                'name' => 'Field Force Pack',
                'type' => 'feature',
                'tagline' => 'Beat plans, visits, live tracking and collections',
                'description' => 'Salesmen and beat plans, visit check-ins with live tracking, order capture on the road and cash collection reconciliation.',
                'icon' => 'pi pi-map',
                'price' => 899,
                'billing_cycle' => 'monthly',
                'modules' => ['field_sales', 'crm'],
                'included_in_plans' => ['business', 'professional', 'enterprise'],
                'sort_order' => 21,
            ],
            [
                'slug' => 'compliance-pack',
                'name' => 'GST & Compliance Pack',
                'type' => 'feature',
                'tagline' => 'e-Invoice, e-Way bill, TDS/TCS and GSTR exports',
                'description' => 'GSTIN validation, the HSN master, e-invoice and e-way bill generation, TDS/TCS on purchases, and GSTR-ready exports.',
                'icon' => 'pi pi-verified',
                'price' => 699,
                'billing_cycle' => 'monthly',
                'modules' => ['gst'],
                'included_in_plans' => ['business', 'professional', 'enterprise'],
                'sort_order' => 22,
            ],
            [
                'slug' => 'workforce-pack',
                'name' => 'Workforce Pack',
                'type' => 'feature',
                'tagline' => 'Attendance, payroll and 10 extra seats',
                'description' => 'Employees, attendance and leave, payroll runs and salary advances — plus ten additional user seats, because a workspace that runs payroll has more people in it.',
                'icon' => 'pi pi-id-card',
                'price' => 999,
                'billing_cycle' => 'monthly',
                'modules' => ['hrms'],
                'limit_bumps' => ['max_users' => 10],
                'included_in_plans' => ['business', 'professional', 'enterprise'],
                'sort_order' => 23,
            ],
            [
                'slug' => 'growth-capacity-pack',
                'name' => 'Capacity Pack',
                'type' => 'feature',
                'tagline' => '+10 users, +3 warehouses, +5,000 products',
                'description' => 'Raises your plan\'s limits without moving tier: ten more seats, three more warehouses and five thousand more products. Stacks — buy it twice for twice the headroom.',
                'icon' => 'pi pi-arrows-alt',
                'price' => 799,
                'billing_cycle' => 'monthly',
                // Grants no module: this pack exists purely to raise the caps
                // EnforcePlanLimit meters.
                'modules' => [],
                'limit_bumps' => ['max_users' => 10, 'max_warehouses' => 3, 'max_products' => 5000],
                'sort_order' => 24,
            ],

            // --- AI packs ------------------------------------------------------
            [
                'slug' => 'ai-assistant-pack',
                'name' => 'AI Assistant Pack',
                'type' => 'ai',
                'tagline' => 'Forecasts, demand insights and the bilingual assistant',
                'description' => 'Sales forecasting, demand and low-stock insights, purchase suggestions, and the bilingual chat/voice assistant over your own ERP data.',
                'icon' => 'pi pi-sparkles',
                'price' => 1499,
                'billing_cycle' => 'monthly',
                'modules' => ['ai'],
                // AI ships in every tier today, so this pack is bundled
                // everywhere: it is listed for the shelf, and sells to nobody
                // until a future tier drops it.
                'included_in_plans' => ['starter', 'business', 'professional', 'enterprise'],
                'sort_order' => 30,
            ],
            [
                'slug' => 'automation-pack',
                'name' => 'Automation Pack',
                'type' => 'ai',
                'tagline' => 'IF/THEN workflows across approvals and alerts',
                'description' => 'Rule-driven approvals, notifications and field updates fired off your own documents — no code, and it runs on the same events the ERP already emits.',
                'icon' => 'pi pi-share-alt',
                'price' => 599,
                'billing_cycle' => 'monthly',
                'modules' => ['workflow'],
                'included_in_plans' => ['starter', 'business', 'professional', 'enterprise'],
                'sort_order' => 31,
            ],
        ];
    }
}
