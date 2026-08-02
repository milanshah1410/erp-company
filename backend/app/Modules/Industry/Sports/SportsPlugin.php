<?php

declare(strict_types=1);

namespace App\Modules\Industry\Sports;

use App\Models\ServiceJob;
use App\Modules\Industry\Contracts\IndustryPlugin;
use App\Modules\Industry\Sports\Models\RentalAsset;

/**
 * Sports & Fitness — the retail shop whose goods come back.
 *
 * `.claude/shops-modules.md` §"🏏 12. Sports Shop": Brand, Size, Color, Warranty,
 * Barcode, Repair, Rental (Optional).
 *
 * Six of those seven are already built, and this plugin re-implements none of
 * them. A sports shop sells branded goods in sizes and colours, labels them,
 * warrants them and repairs them — which is to say it is a footwear shop with
 * cricket bats on the wall, and the ERP has served that shop since the Retail
 * vertical shipped:
 *
 *  - **Brand** is the core brand master.
 *  - **Size** and **Color** are the Retail plugin's size × colour matrix over
 *    ordinary `product_variants` — the same grid a shoe shop lays its size runs
 *    out on, which is exactly the problem a rack of shorts and a wall of racquets
 *    has. The trade preset ticks `garment` for it, as `footwear` does.
 *  - **Warranty** is core's `product_warranties` and its claims register. A
 *    treadmill and a pair of running shoes are warranted by the same card as an
 *    appliance, and a shop must never be given two answers about who pays.
 *  - **Barcode** is `inventory` and `devices`. A cricket bat's barcode is not
 *    special.
 *  - **Repair** is a `ServiceJob` on the `service` module — the bench that
 *    restrings a racquet, trues a wheel or replaces a grip is the same bench,
 *    with the same job card, parts issue and billing, that every repair trade in
 *    this product already uses. The plugin adds one nullable column to it
 *    (`sports_asset_id`) so a job can name a bike from the hire fleet instead of
 *    a customer's, which is the same move Automobile made with `vehicle_id`.
 *
 * That leaves the bullet marked *optional*, and it is the only one nothing else
 * in this product does at all. It is also the one that makes this a different
 * business rather than a different aisle.
 *
 * **Hire breaks the assumption the ERP is built on.** Everywhere else an article
 * leaves the shop once and the transaction is finished. A hire bike leaves eighty
 * times and comes back seventy-nine; it earns by the day rather than by the piece;
 * it is worth less after every trip; and the money that changes hands at the
 * counter is mostly a deposit, which is not the shop's money at all. None of that
 * is expressible as a sale, and every shortcut that tries — hiring "stock",
 * banking the deposit as revenue, tracking returns on the header — fails in a way
 * the shop only discovers when it has double-booked a weekend or paid tax on a
 * float. So the vertical is a hire desk: a fleet of individually identified kit, a
 * contract that books it, issues it and takes it back a piece at a time, and a
 * settlement where the deposit meets the bill.
 *
 * Deliberately narrow, on the Toys precedent. One register, one document, and no
 * second catalogue or second till.
 */
class SportsPlugin implements IndustryPlugin
{
    public function key(): string
    {
        return 'sports';
    }

    public function label(): string
    {
        return 'Sports & Fitness';
    }

    public function icon(): string
    {
        return 'pi pi-flag';
    }

    public function description(): string
    {
        return 'Sports shops: a hire fleet with rates, deposits and an overdue board, kit repair on the bench, sizes, colours and warranty from core.';
    }

    /**
     * Teach the core job card about the hire fleet, without core knowing this
     * plugin exists.
     *
     * The dependency points plugin → core: with the vertical unregistered,
     * `service_jobs.sports_asset_id` is an unused nullable column and nothing ever
     * asks for a relation that is not there.
     *
     * The relation is prefixed `sportsAsset` rather than taking the plain `asset`
     * because four verticals now hang relations off this model, and the second to
     * boot silently replaces the first's in a workspace running both.
     */
    public function boot(): void
    {
        ServiceJob::resolveRelationUsing(
            'sportsAsset',
            static fn (ServiceJob $job) => $job->belongsTo(RentalAsset::class, 'sports_asset_id'),
        );

        ServiceJob::$pluginRelations['sportsAsset'] = 'sportsAsset';
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
            'sports_shop' => [
                'label' => 'Sports & Fitness Shop',
                'icon' => 'pi pi-flag',
                'description' => 'Equipment hire with deposits and an overdue board, kit repair, size & colour runs, brand-wise stock, warranty and counter billing',
                /**
                 * Every key here is carrying one of the seven bullets, and none of
                 * them is decoration:
                 *
                 *  - `sports` — the hire desk, which is all this plugin ships.
                 *  - `garment` — the size × colour matrix, for the same reason the
                 *    footwear trade takes it. A wall of racquets in three grip
                 *    sizes and a rack of jerseys in six is the same stock problem.
                 *  - `warranty` — the card and the claims register.
                 *  - `service` — the repair bench. Without it the Repair bullet is
                 *    a promise with no screen behind it.
                 *  - `pos`, `devices` — this trade is a counter with a scanner and
                 *    a label printer.
                 *  - `loyalty` — a sports shop lives on clubs, schools and the
                 *    same families through a season.
                 *  - `gst` — it bills them.
                 */
                'modules' => ['sports', 'garment', 'warranty', 'service', 'pos', 'gst', 'loyalty', 'devices'],
                'features' => [
                    // This plugin's own.
                    'rental' => 'available',
                    'rental_deposit' => 'available',
                    'late_fee' => 'available',
                    'fleet_utilisation' => 'available',
                    // Delivered by a module this preset ticks, not by this plugin
                    // — named anyway, because they are what the trade asks about
                    // and a map that listed only the hire desk would read as a gap.
                    'brand' => 'available',
                    'size_matrix' => 'available',
                    'colour_matrix' => 'available',
                    'warranty' => 'available',
                    'barcode' => 'available',
                    'label_printing' => 'available',
                    'repair' => 'available',
                    'loyalty' => 'available',
                    /**
                     * A shop with a net, a turf or a court hires *time* rather
                     * than an object: the same slot, over and over, with nothing
                     * to hand over and nothing to get back. The fleet answers "is
                     * bike 7 free?", which is not the same question as "is the
                     * 6 p.m. slot free?", and pretending one register does both
                     * would give a shop a booking diary that cannot be
                     * double-booked only by accident.
                     */
                    'court_booking' => 'planned',
                    /**
                     * Kitting out a school team is an institutional supply — a
                     * quotation against a list, a purchase-order number, phased
                     * delivery. The Stationery vertical built exactly that for
                     * booklists (`institution` channel), and the honest answer is
                     * that it has not been generalised to a kit list yet.
                     */
                    'team_kitting' => 'planned',
                ],
            ],
        ];
    }
}
