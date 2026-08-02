<?php

declare(strict_types=1);

namespace App\Modules\Industry\Cosmetics;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Modules\Industry\Contracts\IndustryPlugin;
use App\Modules\Industry\Cosmetics\Models\CosmeticShade;

/**
 * Beauty & Personal Care — the counter where the article is a shade and the
 * stock has a clock in it.
 *
 * `.claude/shops-modules.md` §"💄 13. Cosmetic Store": Batch, Expiry,
 * Shade/Color, Barcode, Offers, Membership.
 *
 * Four of those six already exist and this plugin rebuilds none of them:
 *
 *  - **Batch** and **Expiry** are core `batches`, which every trade stocking a
 *    dated good runs on, with FEFO picking already wired into the outward path.
 *  - **Barcode** is `inventory` and `devices`. A lipstick's barcode is not
 *    special.
 *  - **Offers** and **Membership** are core Loyalty & Offers (`loyalty`), which
 *    the preset ticks. A club card is a club card whether it is sold by a café,
 *    a supermarket or a beauty counter, and the module already carries points,
 *    coupons, gift vouchers and membership plans.
 *
 * What is left is what a general ERP has no reason to know, and it is two
 * things:
 *
 *  - **A shade is not a colour.** A garment colour is a word attached to a size
 *    run; a shade is an identity a customer knows by number and will not
 *    substitute, carrying the four properties that decide whether it suits the
 *    person holding it — family, finish, undertone and depth. A foundation range
 *    is forty of them, it never sells evenly, and the shop's real stock question
 *    is which four of the forty are missing right now. That question is
 *    unanswerable if the shade is a string inside a JSON column, which is why
 *    there is a master and why the card joins against it. What the card
 *    *writes*, though, is ordinary `product_variants` — see ShadeCardService.
 *
 *  - **Opened stock has a second clock.** Core knows the printed expiry, and for
 *    make-up that is the less important date. A mascara is finished six months
 *    after the seal is broken whatever the carton says, and the units that get
 *    opened in a cosmetics shop are the testers sitting on the counter being
 *    used by strangers all day. Nothing in an ERP has a place for stock that has
 *    left the shelf, will never be sold, and dies on a date computed from the
 *    day somebody opened it. So the register exists, the unit is written off as
 *    it is opened, and the board shows what should already have been binned.
 *
 * And one thing core *could* have done and does not: refuse. An ERP will happily
 * bill a foundation that went off in March, because for most trades a date
 * slipping past is a markdown decision. Here it is an offence under the Drugs
 * and Cosmetics Rules, so the counter check (ShelfLifeService) turns the date
 * into a refusal at the till — under a shop-wide policy, because a chain with a
 * compliance officer and a single counter clearing its shelf at 40% off need
 * different answers.
 *
 * The vertical is registered under its own key rather than as a Retail trade
 * because it is not one: Retail is organised around how the *article* is
 * identified (a style, a purity, a prescription), and beauty is organised around
 * how long the article stays fit to sell. It also has obvious siblings to come —
 * perfumery, salon retail, ayurvedic and herbal — which would each be a trade
 * here and none of which belongs under a garment showroom.
 */
class CosmeticsPlugin implements IndustryPlugin
{
    public function key(): string
    {
        return 'cosmetics';
    }

    public function label(): string
    {
        return 'Beauty & Personal Care';
    }

    public function icon(): string
    {
        return 'pi pi-heart-fill';
    }

    public function description(): string
    {
        return 'Cosmetics and beauty counters: shade cards, batch and period-after-opening dating, tester stock and a till that will not sell expired make-up.';
    }

    /**
     * Teach the core catalogue about shades, without core knowing this plugin
     * exists.
     *
     * The dependency has to point plugin → core, or a workspace that never
     * bought Beauty would still carry these relations. resolveRelationUsing is
     * the seam: they are attached at boot, so with the plugin unregistered the
     * columns are unused nullables and nothing asks for a relation that is not
     * there.
     *
     * Both models get it, because both shop styles are real — a chain keys one
     * product per range with forty variants under it, a single counter keys
     * forty products — and `shadeFor()` below is the one place that difference
     * is resolved.
     */
    public function boot(): void
    {
        Product::resolveRelationUsing(
            'cosmeticShade',
            static fn (Product $product) => $product->belongsTo(CosmeticShade::class, 'cosmetic_shade_id'),
        );
        ProductVariant::resolveRelationUsing(
            'cosmeticShade',
            static fn (ProductVariant $variant) => $variant->belongsTo(CosmeticShade::class, 'cosmetic_shade_id'),
        );
    }

    /**
     * Which shade is in the customer's hand — the variant's where there is one,
     * otherwise the article's own.
     *
     * One method rather than every caller writing the same `??`, because getting
     * the precedence backwards is silent: a range product carries the shade of
     * whichever variant happened to be keyed first, and a bill would go out
     * naming the wrong one.
     */
    public static function shadeFor(?Product $product, ?ProductVariant $variant = null): ?CosmeticShade
    {
        if ($variant?->cosmetic_shade_id) {
            return $variant->cosmeticShade;
        }

        return $product?->cosmetic_shade_id ? $product->cosmeticShade : null;
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
            'cosmetic' => [
                'label' => 'Cosmetic / Beauty Store',
                'icon' => 'pi pi-heart-fill',
                'description' => 'Shade cards by family, finish and undertone; batch and expiry with period-after-opening; tester stock; membership, offers and counter billing',
                /**
                 * `loyalty` is not decoration here — it is where two of the six
                 * things this trade was asked for actually live (membership and
                 * offers, see the note above). `pos` and `devices` because this
                 * trade *is* a counter with a scanner and a label printer, and
                 * `gst` because it bills.
                 */
                'modules' => ['cosmetics', 'pos', 'gst', 'loyalty', 'devices'],
                'features' => [
                    'shade_master' => 'available',
                    'shade_card' => 'available',
                    'period_after_opening' => 'available',
                    'tester_stock' => 'available',
                    'expiry_block_at_till' => 'available',
                    // Everything below is delivered by a module this preset ticks
                    // rather than by this plugin — named anyway, because they are
                    // what the trade asks about and a feature map listing only one
                    // plugin's work would read as a gap.
                    'batch' => 'available',
                    'expiry' => 'available',
                    'barcode' => 'available',
                    'label_printing' => 'available',
                    'offers' => 'available',
                    'membership' => 'available',
                    'loyalty' => 'available',
                    'gift_voucher' => 'available',
                    /**
                     * What the customer is allergic to, and what is in the
                     * product. The question a beauty counter is asked daily —
                     * "does this have parabens / is it fragrance-free" — and
                     * there is nowhere to hold the answer: an ingredient list is
                     * not a description, and matching it against a customer's
                     * sensitivities is not a filter on a product name.
                     */
                    'ingredient_allergens' => 'planned',
                    /**
                     * A recall names a batch, and the shop needs every article of
                     * it off the shelf and every customer who bought one told.
                     * Batch tracking exists in core; the sweep that turns a
                     * withdrawal notice into a list of buyers does not — the same
                     * gap the toy shop has.
                     */
                    'recall_notices' => 'planned',
                ],
            ],
        ];
    }
}
