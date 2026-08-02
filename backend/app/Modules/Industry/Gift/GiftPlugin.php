<?php

declare(strict_types=1);

namespace App\Modules\Industry\Gift;

use App\Modules\Industry\Contracts\IndustryPlugin;

/**
 * Gift & Novelty — the trade where the object is the least of it.
 *
 * A gift shop's stock is unremarkable: mugs, frames, soft toys, chocolate, all
 * ordinary products with ordinary barcodes and ordinary variants. What makes it a
 * different business from the general store next door is that nobody buys any of
 * it for themselves. Three consequences follow, and they are the whole vertical:
 *
 *  1. **It has to be wrapped.** Which is a small job with a queue, a promised
 *     time, material off the shelf and a rule that the receiver must not see the
 *     price. At Diwali the wrap desk *is* the shop's bottleneck.
 *  2. **It is often a hamper.** A combo pack is a physical thing assembled out of
 *     other stock — twenty baskets built on Tuesday means twenty fewer of
 *     everything inside them, and one new article on the shelf with its own
 *     barcode and its own cost.
 *  3. **It is bought for a date.** And the date belongs to somebody who is not the
 *     customer. A shop that knows a regular's wife's birthday nine days out sells
 *     the gift; a shop that finds out on the morning does not.
 *
 * Its own vertical rather than a trade under Retail, for the reason Mobile and
 * Computer & IT are: the module it needs (`gift`) is not one any other retail
 * trade wants switched on, and a toy shop or a novelty counter — the neighbours
 * this vertical will grow into — has the same three problems and none of the
 * garment showroom's.
 *
 * What it deliberately does **not** ship, because core already has it and a plugin
 * must not offer a shop two answers:
 *
 *  - **Gift vouchers.** App\Models\GiftVoucher, under `loyalty`, redeemed by
 *     PosController. Core cannot depend on a vertical that may not be installed.
 *  - **Product variants and barcodes.** Ordinary `product_variants` and the
 *     `devices` module's label printing.
 *  - **Loyalty points.** The `loyalty` module, which this trade's preset ticks.
 */
class GiftPlugin implements IndustryPlugin
{
    public function key(): string
    {
        return 'gift';
    }

    public function label(): string
    {
        return 'Gift & Novelty';
    }

    public function icon(): string
    {
        return 'pi pi-gift';
    }

    public function description(): string
    {
        return 'Gift shops and novelty counters: the wrap desk, hamper packs and the occasions your customers buy for.';
    }

    public function trades(): array
    {
        return [
            'gift_shop' => [
                'label' => 'Gift Shop',
                'icon' => 'pi pi-gift',
                'description' => 'Gift wrap desk, hamper packs, occasion reminders, loyalty and gift vouchers',
                /**
                 * `loyalty` is not optional decoration here: it carries both the
                 * points a gift shop's regulars collect *and* gift vouchers, which
                 * are the trade's second-largest line at festival time. `devices`
                 * carries barcode and label printing — a shop pricing five hundred
                 * small items by hand at Diwali is a shop that stops selling.
                 */
                'modules' => ['pos', 'gst', 'gift', 'loyalty', 'devices'],
                'features' => [
                    'product_variants' => 'available',
                    'gift_wrap' => 'available',
                    'combo_pack' => 'available',
                    'occasion_reminder' => 'available',
                    'loyalty' => 'available',
                    'barcode' => 'available',
                    // Vouchers come with Loyalty & Offers, which this preset ticks
                    // — worth naming, because it is what a buyer asks about second.
                    'gift_voucher' => 'available',
                    'label_printing' => 'available',
                ],
            ],
        ];
    }
}
