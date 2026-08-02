<?php

declare(strict_types=1);

namespace App\Modules\Industry\Toys;

use App\Models\Product;
use App\Modules\Industry\Contracts\IndustryPlugin;
use App\Modules\Industry\Toys\Models\AgeGrade;
use App\Modules\Industry\Toys\Models\SafetyCategory;

/**
 * Toys & Games — the shop where the buyer is not the user.
 *
 * `.claude/shops-modules.md` §"🧸 9. Toy Shop": Age Category, Safety Category,
 * Barcode, Offers, Combo Sales, Gift Packaging.
 *
 * Almost everything a toy shop does is ordinary retail, and this plugin
 * re-implements none of it. What is not ordinary is that the person paying is
 * buying for someone who is not in the shop — usually a child whose age they
 * will name and whose safety they are assuming somebody has thought about. Two
 * things follow, and they are the entire vertical:
 *
 *  - **An article has to know who it is for.** An age band is a merchandising
 *    fact with a legal edge: read against the safety class it catches the one
 *    mistake that matters, which is a choking hazard graded onto the toddler
 *    shelf.
 *  - **Some of it may not be sold at all.** Under the Toys (Quality Control)
 *    Order a toy needs a live BIS licence behind it. The licence belongs to the
 *    manufacturer, expires on a date nobody has in a diary, and nothing on the
 *    shelf looks any different afterwards — so the shop finds out during an
 *    inspection. The certificate register exists to make that visible before
 *    then, and the counter check (SafetyComplianceService) is what turns it into
 *    a refusal at the till when the class says it must be.
 *
 * The other four bullets are deliberately somebody else's, because a plugin must
 * not offer a shop two answers to the same question:
 *
 *  - **Combo Sales** and **Gift Packaging** are the Gift & Novelty module
 *    (`gift`), which the toy-shop preset ticks: hamper packs that draw their
 *    contents down when they are assembled, and a wrap desk with a queue, a
 *    charge and its own material. A toy shop's Diwali box and birthday wrap are
 *    the same two problems a gift counter has, and its plugin already solves them
 *    properly. A shop that would rather bill a set as one scan and expand it at
 *    the till — the kirana model — can switch on Grocery & Kirana (`grocery`)
 *    instead; both are wired into the POS already.
 *  - **Barcode** is `inventory` and `devices`. A toy's barcode is not special.
 *  - **Offers** are the till's line and bill discounts, plus whichever pack
 *    mechanism above the shop chose. The `coupons` register exists in core for
 *    the order desk, but the POS does not take a code — declared as planned
 *    below rather than implied.
 *
 * So the plugin owns exactly what a general ERP has no reason to know: that a
 * toy is for a three-year-old, that it has a button cell in it, and that the
 * licence covering it ran out five weeks ago.
 */
class ToysPlugin implements IndustryPlugin
{
    public function key(): string
    {
        return 'toys';
    }

    public function label(): string
    {
        return 'Toys & Games';
    }

    public function icon(): string
    {
        return 'pi pi-star';
    }

    public function description(): string
    {
        return 'Toy shops: age grading, safety classes with their hazards, and the BIS certificates that decide what may legally be sold.';
    }

    /**
     * Teach the core product about age and safety, without core knowing this
     * plugin exists.
     *
     * The dependency has to point plugin → core, or a workspace that never
     * bought Toys would still carry these classes. resolveRelationUsing is the
     * seam: the relations are attached at boot, so with the plugin unregistered
     * the columns are unused nullables and nothing asks for a relation that is
     * not there.
     */
    public function boot(): void
    {
        Product::resolveRelationUsing(
            'toyAgeGrade',
            static fn (Product $product) => $product->belongsTo(AgeGrade::class, 'toy_age_grade_id'),
        );
        Product::resolveRelationUsing(
            'toySafetyCategory',
            static fn (Product $product) => $product->belongsTo(SafetyCategory::class, 'toy_safety_category_id'),
        );
    }

    /**
     * What this trade adds on top of the Core ERP set. Selling, buying, stocking
     * and keeping books are core and merged in by ShopProfiles::all().
     *
     * @return array<string, array<string, mixed>>
     */
    public function trades(): array
    {
        return [
            'toy_shop' => [
                'label' => 'Toy & Game Shop',
                'icon' => 'pi pi-star',
                'description' => 'Toys graded by age and safety class, BIS certificate tracking, gift packs and the wrap desk, counter billing',
                /**
                 * `gift` is not decoration here — it is where two of the six
                 * things this trade was asked for actually live (see the note
                 * above). `pos` and `devices` because this trade *is* a counter
                 * with a scanner and a label printer, `loyalty` because a toy
                 * shop lives on the same families coming back every birthday,
                 * and `gst` because it bills them.
                 */
                'modules' => ['toys', 'gift', 'pos', 'gst', 'loyalty', 'devices'],
                'features' => [
                    'age_category' => 'available',
                    'safety_category' => 'available',
                    // Everything below is delivered by a module this preset
                    // ticks, not by this plugin — named anyway, because they are
                    // what the trade asks about and a feature map that only
                    // listed one plugin's work would read as a gap.
                    'barcode' => 'available',
                    'combo_sales' => 'available',
                    'gift_packaging' => 'available',
                    'offers' => 'available',
                    'loyalty' => 'available',
                    /**
                     * A coupon register exists (core `coupons`) and the order
                     * desk can redeem against it, but the counter cannot key a
                     * code into a POS bill. Declared rather than implied, so a
                     * shop is not sold a promise the till does not keep.
                     */
                    'coupon_at_till' => 'planned',
                    /**
                     * A withdrawal notice arrives naming a batch, and the shop
                     * needs every article of it off the shelf and every customer
                     * who bought one told. Batch tracking exists in core
                     * inventory; the sweep that turns a recall into a list of
                     * buyers does not.
                     */
                    'recall_notices' => 'planned',
                ],
            ],
        ];
    }
}
