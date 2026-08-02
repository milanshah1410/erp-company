<?php

declare(strict_types=1);

namespace App\Modules\Industry\Manufacturing;

use App\Modules\Industry\Contracts\IndustryPlugin;

/**
 * Manufacturing — the second industry plugin.
 *
 * Ten trades sit under this vertical and, unlike retail, what separates them is
 * not the counter but the *conversion*: a printing press sells the same paper it
 * bought with ink on it, a plastics unit sells granules that have been melted
 * into a shape, a garment unit sells cloth that has been cut and joined by hand.
 * All of them share one sentence — material goes in, something else comes out,
 * and the difference is where the money is made or lost.
 *
 * That shared sentence is why the common shop floor is built once rather than
 * ten times. A bill of materials, a production run, a machine, an inspection and
 * a scrap note mean the same thing in every one of these trades; only the nouns
 * change. The trade-specific parts that genuinely differ — a fabric roll, an
 * embroidery vendor, a colour-printing docket — already exist as their own
 * modules, and the presets below simply switch on the ones each trade runs.
 *
 * Feature statuses are load-bearing, as in Food & Beverage. A trade capability
 * marked 'planned' is one the trade names and the ERP does not yet do; showing
 * it as available would sell something that is not there.
 */
class ManufacturingPlugin implements IndustryPlugin
{
    public function key(): string
    {
        return 'manufacturing';
    }

    public function label(): string
    {
        return 'Manufacturing';
    }

    public function icon(): string
    {
        return 'pi pi-cog';
    }

    public function description(): string
    {
        return 'Garment, textile, embroidery, printing, furniture, plastic, steel, packaging, chemical and food manufacturing units.';
    }

    /**
     * What every factory adds on top of the Core ERP set regardless of what it
     * makes: it converts (manufacturing), files under GST, pays people (hrms) and
     * owns plant (assets). Buying material, keeping stock, invoicing and books are
     * core, merged in by ShopProfiles::all().
     *
     * No `pos` here, deliberately — a factory invoices, it does not bill at a
     * counter.
     *
     * @return list<string>
     */
    private function base(): array
    {
        return ['manufacturing', 'gst', 'hrms', 'assets'];
    }

    /**
     * The shop-floor capabilities this plugin itself ships, shared by all ten
     * trades. Declared once and spread into each, so a capability arriving later
     * is switched on for the whole vertical in one edit rather than ten.
     *
     * @return array<string, string>
     */
    private function shopFloor(): array
    {
        return [
            'bom' => 'available',
            'production' => 'available',
            'qc' => 'available',
            'machine' => 'available',
            'maintenance' => 'available',
            'scrap' => 'available',
            'costing' => 'available',
            // Labour reaches the floor two ways: piece-rate work through the
            // Tailoring module, and shift wages through HR & Payroll. What is
            // charged to a run is its labour cost bucket.
            'labour' => 'available',
        ];
    }

    public function trades(): array
    {
        return [
            /*
             * Note on keys: 'garment' already exists as a hand-written
             * ShopProfiles preset for a garment *shop* — a retailer with size
             * and colour variants who occasionally alters a piece. A unit that
             * cuts and stitches for others is a different business, so it gets
             * its own key rather than quietly redefining the retailer's.
             */
            'garment_manufacturing' => [
                'label' => 'Garment / Apparel Unit',
                'icon' => 'pi pi-shopping-bag',
                'description' => 'Cutting, stitching, piece-rate labour, embroidery and finishing',
                'modules' => [...$this->base(), 'textile', 'tailoring', 'embroidery', 'logistics', 'devices'],
                'features' => [
                    ...$this->shopFloor(),
                    'cutting' => 'available',
                    'tailoring' => 'available',
                    'embroidery' => 'available',
                    'job_work' => 'available',
                    // Sizes and colours are product variants, which every trade
                    // gets; a size-wise ratio grid on the order is not.
                    'size_ratio' => 'planned',
                ],
            ],

            'textile_mill' => [
                'label' => 'Textile Mill / Processing',
                'icon' => 'pi pi-th-large',
                'description' => 'Fabric rolls, four-point inspection, shrinkage and roll-wise cutting',
                'modules' => [...$this->base(), 'textile', 'tailoring', 'logistics', 'devices'],
                'features' => [
                    ...$this->shopFloor(),
                    'fabric_roll' => 'available',
                    'fabric_inspection' => 'available',
                    'shrinkage' => 'available',
                    'cutting' => 'available',
                    // Dyeing is a recipe of chemicals against a shade card, and
                    // the lot-to-shade matching that goes with it.
                    'dyeing' => 'planned',
                ],
            ],

            'embroidery_unit' => [
                'label' => 'Embroidery Unit',
                'icon' => 'pi pi-star',
                'description' => 'Job-work dispatch and receipt, vendor ledger and per-stitch rates',
                'modules' => [...$this->base(), 'embroidery', 'tailoring', 'textile'],
                'features' => [
                    ...$this->shopFloor(),
                    'embroidery' => 'available',
                    'job_work' => 'available',
                    'vendor_ledger' => 'available',
                    // A design file with a stitch count, priced per thousand
                    // stitches, is the unit's real rate card.
                    'design_library' => 'planned',
                ],
            ],

            'printing_press' => [
                'label' => 'Printing Press',
                'icon' => 'pi pi-print',
                'description' => 'Colour printing jobs, plates, paper issue and wastage',
                'modules' => [...$this->base(), 'textile', 'logistics', 'devices'],
                'features' => [
                    ...$this->shopFloor(),
                    'colour_printing' => 'available',
                    'job_work' => 'available',
                    // Setup waste is the press's largest single loss and is
                    // recorded as scrap with a `setup_waste` reason.
                    'setup_waste' => 'available',
                    // An imposition/plate plan and paper-size calculators are
                    // the press's own arithmetic, not general production.
                    'imposition' => 'planned',
                ],
            ],

            'furniture' => [
                'label' => 'Furniture Manufacturing',
                'icon' => 'pi pi-inbox',
                'description' => 'Made-to-order carcasses, timber and board issue, polish and assembly',
                'modules' => [...$this->base(), 'projects', 'logistics', 'service'],
                'features' => [
                    ...$this->shopFloor(),
                    'job_work' => 'available',
                    // Installation at the customer's site is a service job.
                    'installation' => 'available',
                    // A board-cutting plan that minimises offcut is its own
                    // optimisation problem, not a BOM.
                    'cutting_list' => 'planned',
                ],
            ],

            'plastic' => [
                'label' => 'Plastic / Moulding',
                'icon' => 'pi pi-box',
                'description' => 'Injection and blow moulding, cycle-wise output, regrind and rejection',
                'modules' => [...$this->base(), 'logistics', 'devices'],
                'features' => [
                    ...$this->shopFloor(),
                    // Regrind is scrap with a recovery value, which the scrap
                    // note carries.
                    'regrind' => 'available',
                    // A mould is plant that wears out by shot count, not by
                    // hours — it needs its own life tracking.
                    'mould_master' => 'planned',
                    'cycle_time' => 'planned',
                ],
            ],

            'steel' => [
                'label' => 'Steel / Fabrication',
                'icon' => 'pi pi-wrench',
                'description' => 'Cutting and fabrication to drawing, weight-based issue and offcut recovery',
                'modules' => [...$this->base(), 'projects', 'logistics', 'devices'],
                'features' => [
                    ...$this->shopFloor(),
                    'job_work' => 'available',
                    // Offcut sold by the kilo — again scrap with recovery.
                    'offcut_recovery' => 'available',
                    // Heat-number traceability from mill certificate to finished
                    // member is a batch chain the ERP does not yet keep.
                    'heat_number' => 'planned',
                    'weight_calculation' => 'planned',
                ],
            ],

            'packaging' => [
                'label' => 'Packaging / Corrugation',
                'icon' => 'pi pi-inbox',
                'description' => 'Board conversion, printing, die-cutting and reel-wise consumption',
                'modules' => [...$this->base(), 'logistics', 'devices'],
                'features' => [
                    ...$this->shopFloor(),
                    'colour_printing' => 'available',
                    'setup_waste' => 'available',
                    // Reel width to sheet size, and the trim that falls out of
                    // it, is the converter's core calculation.
                    'reel_planning' => 'planned',
                    'die_master' => 'planned',
                ],
            ],

            'chemical' => [
                'label' => 'Chemical / Paints',
                'icon' => 'pi pi-filter',
                'description' => 'Batch formulation, batch-wise QC release and shelf life',
                'modules' => [...$this->base(), 'logistics', 'devices'],
                'features' => [
                    ...$this->shopFloor(),
                    // Batch and expiry are advanced-inventory features; QC
                    // inspects a batch, which is what release means here.
                    'batch' => 'available',
                    'expiry' => 'available',
                    'batch_qc' => 'available',
                    // A formulation that scales by density and assay, and a
                    // material safety sheet per product, are both their own
                    // documents.
                    'formulation' => 'planned',
                    'msds' => 'planned',
                ],
            ],

            'food_manufacturing' => [
                'label' => 'Food Manufacturing / Processing',
                'icon' => 'pi pi-shop',
                'description' => 'Recipe-driven batches, shelf life, batch QC and FSSAI-traceable lots',
                // Recipes are reached through Manufacturing → Production →
                // Recipes, the same second door a bakery uses: the screen is the
                // F&B plugin's, the trade is a factory's.
                'modules' => [...$this->base(), 'restaurant', 'logistics', 'devices'],
                'features' => [
                    ...$this->shopFloor(),
                    'recipe' => 'available',
                    'batch' => 'available',
                    'expiry' => 'available',
                    'batch_qc' => 'available',
                    // A lot genealogy from raw lot to dispatched carton — what a
                    // recall actually needs — is more than batch numbers.
                    'traceability' => 'planned',
                ],
            ],

            /*
             * The general factory, and the reason this key is 'manufacturing'.
             *
             * It was a hand-written ShopProfiles preset before this plugin
             * existed, and tenants are already provisioned against it. Declaring
             * it here — same key, same module set — moves it under the vertical
             * without orphaning a single one of them: a plugin trade wins over a
             * PROFILES entry of the same key, exactly as 'restaurant' did when
             * Food & Beverage took it over.
             *
             * It also has to exist for a second reason. The industry template is
             * plugin-backed now, and a plugin-backed template reports the
             * plugin's trades *instead of* its curated ones — so leaving this
             * one behind in ShopProfiles would have quietly removed the generic
             * factory from the signup picker the moment the vertical shipped.
             *
             * Kept last: a shop that is one of the ten above should pick that
             * one, because the preset is better and the trade-specific screens
             * come with it.
             */
            'manufacturing' => [
                'label' => 'Manufacturing / Factory (general)',
                'icon' => 'pi pi-cog',
                'description' => 'Any other production unit — BOMs, runs, machines, QC and costing',
                'modules' => [...$this->base(), 'textile', 'tailoring', 'embroidery'],
                'features' => $this->shopFloor(),
            ],
        ];
    }
}
