<?php

declare(strict_types=1);

namespace App\Modules\Industry\Retail;

use App\Models\Product;
use App\Modules\Industry\Contracts\IndustryPlugin;
use App\Modules\Industry\Retail\Models\GarmentStyle;

/**
 * Retail — the shop-counter vertical.
 *
 * Retail is the widest vertical the ERP sells and the one with the least in
 * common between its trades: a garment showroom, a shoe shop, an optician and a
 * jeweller all ring up a bill and none of them work the same way. What makes
 * them one vertical is that the *sale* is the whole business — nothing is
 * manufactured, assembled or delivered on a route — and what separates them is
 * how the thing being sold is identified. An optician identifies stock by the
 * powers ground into it; a jeweller by metal and purity; a garment shop by
 * style, size and colour.
 *
 * Garment is the trade this plugin ships code for. The others already ran on
 * modules of their own (`optical`, `jewellery`, `warranty` for footwear) and
 * moved here from the hand-written ShopProfiles list so the Retail template —
 * which is plugin-backed once this vertical registers — keeps offering them.
 * Their module sets are unchanged apart from footwear, which gains `garment` for
 * the matrix screen it shares: a live tenant already sitting on
 * `shop_profile = 'jewellery'` must not shift underneath itself.
 *
 * Grocery / Kirana was declared here too, until the Grocery & Kirana vertical
 * shipped the combo-pack and distributor-credit screens that trade was missing
 * and took the key with it. See the note where it used to sit.
 *
 * What Garment adds that core has no place for:
 *
 *  - A **style** is the unit an apparel buyer, a supplier and a shop floor all
 *    speak in — "SS-2214, cotton, half sleeve" — and it is not a product. One
 *    style becomes thirty or forty products-with-variants once it is cut in six
 *    sizes and five colours, and pricing, reordering and season-end markdown all
 *    happen at the style, not at the SKU.
 *  - The **size × colour matrix** is the screen that turns the one into the
 *    other. It writes ordinary `product_variants` rows — the matrix is a way of
 *    *entering* variants, not a second variant system — because everything
 *    downstream (POS scan, stock, barcode labels, channel listings) already
 *    speaks variants and must keep working untouched.
 *  - The **trial room** is custody without a sale: garments leave the shop on a
 *    customer's word and mostly come back. See TrialService for why that is a
 *    reservation and not a stock movement.
 *  - **Gift vouchers** are a tender. Sold at the counter, spent at the counter,
 *    and therefore wired into PosController next to loyalty points and old gold
 *    rather than kept as a register nobody can actually redeem against.
 *
 * Alterations are deliberately *not* here. An alteration is a `TailorAlteration`
 * — the same document a workroom raises — with a customer and a bill behind it,
 * so the retail side of it is three nullable columns on that table rather than a
 * second, worse alteration register.
 */
class RetailPlugin implements IndustryPlugin
{
    public function key(): string
    {
        return 'retail';
    }

    public function label(): string
    {
        return 'Retail';
    }

    public function icon(): string
    {
        return 'pi pi-shop';
    }

    public function description(): string
    {
        return 'Garment and apparel showrooms, footwear shops, opticians and jewellers.';
    }

    /**
     * Teach the core product about the style it was cut from.
     *
     * Same seam the Automobile plugin uses on service jobs: the column is added
     * by this plugin's migration and the relation is attached at boot, so a
     * workspace that never bought Retail carries an unused nullable and nothing
     * ever asks for a relation that does not exist.
     */
    public function boot(): void
    {
        Product::resolveRelationUsing(
            'style',
            static fn (Product $product) => $product->belongsTo(GarmentStyle::class, 'style_id'),
        );
    }

    /**
     * What every retail trade adds on top of the Core ERP set: a counter and GST
     * billing. Selling, buying, stocking and keeping books are core and are
     * merged in by ShopProfiles::all().
     *
     * @return list<string>
     */
    private function base(): array
    {
        return ['pos', 'gst'];
    }

    public function trades(): array
    {
        return [
            'garment' => [
                'label' => 'Garment / Apparel',
                'icon' => 'pi pi-shopping-bag',
                'description' => 'Styles, fabrics, size & colour matrix, trials, alterations, loyalty and gift vouchers',
                /**
                 * `tailoring` rides along because "Darji" work is the alteration
                 * register, which is a tailoring document. A showroom that never
                 * alters anything simply unticks it — the rest of the trade is
                 * unaffected, since nothing else here depends on it.
                 */
                'modules' => [...$this->base(), 'garment', 'loyalty', 'tailoring', 'devices'],
                'features' => [
                    'style_master' => 'available',
                    'fabric' => 'available',
                    'size_matrix' => 'available',
                    'colour_matrix' => 'available',
                    'barcode' => 'available',
                    'qr_code' => 'available',
                    'label_printing' => 'available',
                    'offers' => 'available',
                    'loyalty' => 'available',
                    'alteration' => 'available',
                    'trial_room' => 'available',
                    'gift_voucher' => 'available',
                ],
            ],

            /**
             * Footwear. Its own trade rather than a note under Garment, because
             * the two diverge on exactly the module that matters: a garment shop
             * alters, a footwear shop warrants. Nobody takes a shirt back for a
             * failed sole.
             *
             * It carries `garment` for one reason — the size × colour matrix. A
             * shoe shop's whole stock problem is the size run per colour, which
             * is the same grid, so it gets the same screen rather than a
             * duplicate one. It does not carry `tailoring`: there is no darji
             * behind a shoe rack.
             */
            'footwear' => [
                'label' => 'Footwear Shop',
                'icon' => 'pi pi-shopping-bag',
                'description' => 'Size runs by colour, barcode labels, loyalty, exchange, return and sole warranty',
                'modules' => [...$this->base(), 'garment', 'loyalty', 'warranty', 'devices'],
                'features' => [
                    'size_matrix' => 'available',
                    'colour_matrix' => 'available',
                    'brand' => 'available',
                    'barcode' => 'available',
                    'label_printing' => 'available',
                    'loyalty' => 'available',
                    'exchange' => 'available',
                    'return' => 'available',
                    'warranty' => 'available',
                    // A style master fits footwear ("article number") as well as
                    // apparel, and the same screen serves it.
                    'style_master' => 'available',
                    // A shoe shop has no trial room in this sense — trying a pair
                    // on happens at the rack, not at home — so the register is
                    // deliberately not offered here even though the module
                    // technically carries it.
                ],
            ],

            /**
             * Grocery / Kirana used to be declared here. It moved to the Grocery
             * & Kirana vertical (app/Modules/Industry/Grocery) when that plugin
             * shipped the two things it was missing — combo packs and distributor
             * credit — and deleting it from this list is the whole handover:
             * trade keys are globally unique, so declaring `grocery` in two
             * plugins would leave whichever registered first silently shadowing
             * the other. Same move `restaurant` and `dairy` made out of
             * ShopProfiles when Food & Beverage took them.
             *
             * Optical left the same way and for the same reason, three of its
             * seven features having been marked planned here: the appointment
             * book, the prescription scan and the warranty card issued on
             * handover now exist, and they belong to the Optical & Eyewear
             * vertical (app/Modules/Industry/Optical) that shipped them. Both
             * trade keys are unchanged, so no shop already on either profile
             * shifts underneath it — optical's module set gained `warranty`,
             * which is an addition rather than a removal: the register the new
             * cards are written into.
             */
            'jewellery' => [
                'label' => 'Jewellery',
                'icon' => 'pi pi-star',
                'description' => 'Rate card, purity & making charges, old-gold exchange, hallmarking',
                'modules' => [...$this->base(), 'jewellery', 'loyalty'],
                'features' => [
                    'gold_rate_update' => 'available',
                    'gold_purity' => 'available',
                    'making_charges' => 'available',
                    'hallmark' => 'available',
                    'stone_details' => 'available',
                    'old_gold_exchange' => 'available',
                    'barcode' => 'available',
                    'rfid' => 'available',
                    'purchase_order' => 'available',
                    // Weight and value of the stones is held; a certificate
                    // register with carat/cut/clarity/colour per stone is not.
                    'diamond_details' => 'planned',
                ],
            ],
        ];
    }
}
