<?php

declare(strict_types=1);

namespace App\Modules\Industry\Agriculture;

use App\Modules\Industry\Contracts\IndustryPlugin;

/**
 * Agriculture — the agri-input counter and the farm behind it.
 *
 * The vertical splits cleanly in two, and pretending otherwise is what makes
 * most "agriculture ERP" screens useless to half their users:
 *
 *  - A **dealer** (seeds, fertiliser, pesticides, or all three) is a retail shop
 *    whose stock is legally controlled. It cannot trade without a current
 *    licence per input type, its goods expire, and what a farmer asks for is a
 *    crop and a technical name ("something for sucking pests in cotton"), not an
 *    SKU. So the work here is the licence register and crop/technical attributes
 *    on the product master.
 *
 *  - A **farm** (dairy or poultry) *produces*. Its output is created rather than
 *    bought, so nothing exists in inventory until an animal or a flock makes it,
 *    and the cost that governs the whole business — feed — leaves the store
 *    every single day whether or not anything is sold. So the work there is the
 *    animal/flock register and a daily production log that moves both.
 *
 * That mirror is worth naming: Food & Beverage consumes stock into a dish
 * (RecipeConsumptionService), Agriculture creates stock out of a herd
 * (FarmProductionService). Same spine, opposite direction.
 *
 * Trade keys are flat and globally unique across verticals. Two notes on them:
 *  - `agriculture` is deliberately kept as a trade key. It was a hand-written
 *    ShopProfiles preset before this plugin existed and is stored on live
 *    tenants as `shop_profile`; renaming it would strand them on the `custom`
 *    fallback.
 *  - `dairy_farm` is not F&B's `dairy`. That one is milk *distribution* — a
 *    round, a route, a subscription. This one milks the cows.
 */
class AgriculturePlugin implements IndustryPlugin
{
    public function key(): string
    {
        return 'agriculture';
    }

    public function label(): string
    {
        return 'Agriculture';
    }

    public function icon(): string
    {
        return 'pi pi-sun';
    }

    public function description(): string
    {
        return 'Seed, fertiliser and pesticide dealers, plus dairy and poultry farms.';
    }

    /**
     * What every agri trade adds on top of the Core ERP set. Nothing exotic: it
     * buys, stocks, sells and keeps books — all of which is core now — so the
     * only thing shared across the vertical is filing under GST.
     *
     * Core is merged in by ShopProfiles::all(); nothing here restates it. In
     * particular `inventory` is no longer declared and no longer optional, which
     * suits this vertical: batch and expiry are the whole difference between a
     * legal sale and a prosecutable one for a dealer, and a farm's produce has a
     * shelf life measured in hours.
     *
     * @return list<string>
     */
    private function base(): array
    {
        return ['gst'];
    }

    /**
     * A licensed input counter: base plus the shop-front and the licence
     * register, plus the village-level selling every agri dealer does on credit
     * during a season.
     *
     * @return list<string>
     */
    private function dealer(): array
    {
        return [...$this->base(), 'pos', 'agriculture', 'field_sales', 'devices'];
    }

    /**
     * A farm: base plus the herd/flock registers, the labour that works them and
     * the transport that moves output off site.
     *
     * @return list<string>
     */
    private function farm(): array
    {
        return [...$this->base(), 'livestock', 'hrms', 'logistics'];
    }

    public function trades(): array
    {
        return [
            /**
             * The general agro centre — the most common shape by far. One
             * counter selling all three controlled inputs, which means three
             * licences to keep current and one product master carrying crop,
             * variety, nutrient content and technical name side by side.
             */
            'agriculture' => [
                'label' => 'Agri Centre / Agro Dealer',
                'icon' => 'pi pi-sun',
                'description' => 'Seeds, fertiliser and pesticides over one counter, with licences, batches and expiry',
                'modules' => [...$this->dealer(), 'broker', 'logistics'],
                'features' => [
                    'pos' => 'available',
                    'licence_register' => 'available',
                    'agri_attributes' => 'available',
                    'batch' => 'available',
                    'expiry' => 'available',
                    // Field sales carries the beat/collection plumbing a village
                    // round needs; customer credit limits are core.
                    'farmer_credit' => 'available',
                    'village_route' => 'available',
                    // A soil-test-driven recommendation, and the subsidy claim a
                    // fertiliser sale generates, are both their own documents.
                    'soil_health_card' => 'planned',
                    'subsidy_claim' => 'planned',
                ],
            ],

            'seeds' => [
                'label' => 'Seed Dealer / Nursery',
                'icon' => 'pi pi-box',
                'description' => 'Crop-wise varieties, germination percentage, lot tracking and seed licence',
                'modules' => $this->dealer(),
                'features' => [
                    'pos' => 'available',
                    'licence_register' => 'available',
                    // Crop, variety and germination % live on the product row.
                    'agri_attributes' => 'available',
                    'batch' => 'available',
                    'expiry' => 'available',
                    // A germination test is a dated lab result against a lot,
                    // re-run when the lot ages. Not the same as the printed
                    // percentage on the packet, which is captured today.
                    'germination_test' => 'planned',
                    // Raising your own seedlings is production, not retail.
                    'nursery_batch' => 'planned',
                ],
            ],

            'fertilizer' => [
                'label' => 'Fertiliser Dealer',
                'icon' => 'pi pi-database',
                'description' => 'Nutrient grades, MRP-controlled stock, batch lots and the fertiliser licence',
                'modules' => [...$this->dealer(), 'logistics'],
                'features' => [
                    'pos' => 'available',
                    'licence_register' => 'available',
                    // NPK grade on the product row.
                    'agri_attributes' => 'available',
                    'batch' => 'available',
                    // Weighbridge/counter scales are Device Manager adapters.
                    'weight_scale' => 'available',
                    'farmer_credit' => 'available',
                    // Subsidised urea is sold through the government's own POS
                    // device and reconciled against iFMS — an integration, not a
                    // screen, and not built.
                    'subsidy_pos' => 'planned',
                    'fco_label' => 'planned',
                ],
            ],

            'pesticides' => [
                'label' => 'Pesticides / Agro Chemicals',
                'icon' => 'pi pi-shield',
                'description' => 'Technical names, crop targets, expiry-controlled stock and the insecticide licence',
                'modules' => $this->dealer(),
                'features' => [
                    'pos' => 'available',
                    'licence_register' => 'available',
                    // The technical (active ingredient + formulation) is what a
                    // farmer and an inspector both ask for.
                    'agri_attributes' => 'available',
                    'batch' => 'available',
                    'expiry' => 'available',
                    // Restricted-use molecules need a named-buyer register the
                    // way Schedule H1 drugs do. The pharmacy register is not
                    // reusable as-is, so this is honestly not here yet.
                    'restricted_sale_register' => 'planned',
                    'spray_advisory' => 'planned',
                ],
            ],

            'dairy_farm' => [
                'label' => 'Dairy Farm',
                'icon' => 'pi pi-heart',
                'description' => 'Cattle register, per-animal milk yield with fat/SNF, feed consumption and herd status',
                'modules' => [...$this->farm(), 'pos', 'assets'],
                'features' => [
                    'animal_register' => 'available',
                    // The daily log posts milk into stock and feed out of it.
                    'milk_yield' => 'available',
                    'feed_consumption' => 'available',
                    'batch' => 'available',
                    'expiry' => 'available',
                    // Breeding cycles, AI dates and a vaccination calendar are
                    // date-driven schedules of their own, not columns on a herd
                    // list.
                    'breeding' => 'planned',
                    'vaccination' => 'planned',
                    // Buying milk *in* from member farmers at fat/SNF rates is
                    // the co-operative side of dairy — see F&B's `dairy` trade.
                    'milk_collection' => 'planned',
                ],
            ],

            'poultry' => [
                'label' => 'Poultry Farm',
                'icon' => 'pi pi-building',
                'description' => 'Flock batches, egg collection, feed conversion, mortality and culling',
                'modules' => [...$this->farm(), 'assets'],
                'features' => [
                    // A flock is group-tracked: 5,000 birds are one record, not
                    // 5,000 records.
                    'flock_register' => 'available',
                    'egg_production' => 'available',
                    'feed_consumption' => 'available',
                    'mortality' => 'available',
                    'batch' => 'available',
                    // Broiler FCR needs live weight at sale, which nothing
                    // records yet; feed per bird and lay % are computed today.
                    'weight_gain' => 'planned',
                    'hatchery' => 'planned',
                    'vaccination' => 'planned',
                ],
            ],
        ];
    }
}
