<?php

declare(strict_types=1);

namespace App\Modules\Industry\Grocery;

use App\Modules\Industry\Contracts\IndustryPlugin;

/**
 * Grocery & Kirana — the shop that lives on turnover, not on margin.
 *
 * `.claude/shops-modules.md` §"🛒 6. Grocery / Kirana": POS Billing, Barcode,
 * Batch Number, Expiry Date, Weight Scale, Offers, Combo Products, Loyalty,
 * Daily Cash, Supplier Credit.
 *
 * The trade's defining fact is that almost nothing here is the shop's own money.
 * A kirana makes two to eight percent on somebody else's brands, bought on the
 * distributor's credit and sold for cash across the counter the same week. Two
 * numbers therefore decide whether it survives, and neither of them is profit:
 * how fast the shelf turns, and what is owed to whom on which day. Everything
 * this plugin ships follows from the second one.
 *
 * What it deliberately does **not** ship, because core already does it and a
 * second, worse copy would only drift:
 *
 *  - Counter billing, barcode scanning and label printing are `pos` and
 *    `devices`, unchanged. A weighing scale is a Device Manager adapter like a
 *    thermal printer.
 *  - Batch numbers and expiry dates are `inventory`: the batch register, FEFO
 *    consumption at delivery, the expiry alerts and the near-expiry report all
 *    exist and are not grocery-specific — a pharmacy and a dairy want them
 *    identically.
 *  - Offers, coupons and points are `loyalty`, which is why this trade's preset
 *    now ticks it. Marking those two capabilities 'planned' while withholding
 *    the module that provides them was a preset accident, not a missing feature.
 *  - The daily cash book is `accounts`. A kirana's "day book" is the day book.
 *  - Supplier-wise payables *ageing* is already the Purchase module's Payables
 *    report. See SupplierCreditService for what that report cannot answer and
 *    this vertical therefore adds.
 *
 * That leaves exactly two things a general ERP has no reason to know:
 *
 *  - A **combo pack** — "1kg sugar + 1kg ghee + 500g dry fruit for ₹999" — is one
 *    scan at the till and three items off the shelf. Unlike a café's meal deal
 *    (Food & Beverage's MenuCombo, whose components are dishes costed from their
 *    own recipes), a grocery pack's components *are* stock, so the till has to
 *    take them out of it. See ComboPackService.
 *  - **Supplier credit** — the shop's own khata, read from the debtor's side. The
 *    limit and the payment terms already sit on the supplier master and nothing
 *    has ever read them; what a kirana needs every morning is "whom must I pay
 *    today, and which distributor will stop supplying me if I don't".
 */
class GroceryPlugin implements IndustryPlugin
{
    public function key(): string
    {
        return 'grocery';
    }

    public function label(): string
    {
        return 'Grocery & Kirana';
    }

    public function icon(): string
    {
        return 'pi pi-shopping-cart';
    }

    public function description(): string
    {
        return 'Kirana counters and neighbourhood grocery stores: counter billing, batch and expiry, combo packs, loyalty and distributor credit.';
    }

    public function trades(): array
    {
        return [
            /**
             * The key is unchanged from the hand-written preset this trade used
             * to be — it lived in ShopProfiles, then in the Retail plugin — so a
             * live tenant already sitting on `shop_profile = 'grocery'` does not
             * shift underneath itself. Only the module set widens (`grocery` and
             * `loyalty` join it), which can add nav rows but can never take one
             * away.
             */
            'grocery' => [
                'label' => 'Grocery / Kirana',
                'icon' => 'pi pi-shopping-cart',
                'description' => 'Counter billing, barcode, batch & expiry, combo packs, offers & loyalty, the daily cash book and distributor credit',
                /**
                 * `devices` is not decoration here: the counter scale and the
                 * barcode scanner are how the bill gets made at all. `gst` is
                 * what makes the bill a bill. `loyalty` carries the coupons and
                 * points a kirana runs against the supermarket down the road.
                 */
                'modules' => ['grocery', 'pos', 'gst', 'devices', 'loyalty'],
                'features' => [
                    'pos' => 'available',
                    'barcode' => 'available',
                    'batch' => 'available',
                    'expiry' => 'available',
                    'weight_scale' => 'available',
                    'offers' => 'available',
                    'combo_products' => 'available',
                    'loyalty' => 'available',
                    'daily_cash' => 'available',
                    'supplier_credit' => 'available',
                ],
            ],

            /**
             * Supermarket (`.claude/shops-modules.md` §"🏬 7. Supermarket"): Multi
             * Counter POS, Barcode, Self Checkout, Membership, Coupons, Gift Card,
             * Shelf Management, Batch, Expiry, Stock Transfer.
             *
             * Its own trade rather than a note under Grocery, and in *this*
             * vertical rather than Retail, for the same reason: a supermarket buys
             * the same goods from the same distributors on the same credit and
             * sells them at the same margin as the kirana across the road. It
             * inherits the whole grocery trade — packs, khata, batch, expiry — and
             * differs in one structural way, which is that the customer picks the
             * goods off a shelf instead of asking for them.
             *
             * Everything peculiar to that follows from it: the shelf has to be laid
             * out and kept filled (Shelf Management), the customer may ring
             * themselves up (Self Checkout), and there are six tills instead of one
             * (Multi Counter POS).
             *
             * `loyalty` is doing a lot of work in this preset. Membership, Coupons
             * and Gift Card are three of the ten things the trade asked for and all
             * three are core Loyalty & Offers features — a club card is a club card
             * whether it is sold by a café or a hypermarket. `inventory` is core, so
             * Batch, Expiry and Stock Transfer need no key at all.
             */
            'supermarket' => [
                'label' => 'Supermarket',
                'icon' => 'pi pi-shop',
                'description' => 'Multi-counter tills with cash-up, self-checkout lanes, shelf planogram and replenishment, membership, coupons and gift cards',
                'modules' => ['grocery', 'supermarket', 'pos', 'gst', 'devices', 'loyalty'],
                'features' => [
                    'multi_counter_pos' => 'available',
                    'barcode' => 'available',
                    'self_checkout' => 'available',
                    'membership' => 'available',
                    'coupons' => 'available',
                    'gift_card' => 'available',
                    'shelf_management' => 'available',
                    'batch' => 'available',
                    'expiry' => 'available',
                    'stock_transfer' => 'available',
                    // Carried over from the kirana trade, which this one is a
                    // bigger version of. Both are real here — a hypermarket runs
                    // more multi-buys than a corner shop, not fewer — and both are
                    // served by the same screens under the same modules.
                    'combo_products' => 'available',
                    'loyalty' => 'available',
                    'supplier_credit' => 'available',
                    'weight_scale' => 'available',
                    'daily_cash' => 'available',
                ],
            ],
        ];
    }
}
