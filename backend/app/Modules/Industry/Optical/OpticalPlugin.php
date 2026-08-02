<?php

declare(strict_types=1);

namespace App\Modules\Industry\Optical;

use App\Models\EyePrescription;
use App\Models\OpticalOrder;
use App\Models\Product;
use App\Models\ProductWarranty;
use App\Modules\Industry\Contracts\IndustryPlugin;
use App\Modules\Industry\Optical\Models\OpticalAppointment;
use App\Modules\Industry\Optical\Observers\OpticalOrderObserver;

/**
 * Optical & Eyewear — the shop that sells a thing it has not made yet.
 *
 * `.claude/shops-modules.md` §"👓 15. Optical Shop": Eye Power, Lens Details,
 * Frame Details, Prescription Upload, Appointment, Delivery, Warranty.
 *
 * Four of those already existed in core, and this plugin re-implements none of
 * them. The eye test (`eye_prescriptions`), the lens specification on the
 * article, the spectacle order and its workshop flow are the `optical` module,
 * and they are the spine everything here hangs off. What was missing is
 * everything that happens *around* the grinding:
 *
 *  - **The paper the customer walked in with.** Powers were keyed and could not
 *    be scanned. An optician settles a dispute about what was ordered by
 *    producing the prescription that was handed over the counter — a typed copy
 *    of it is the shop's word against the customer's, so the original is now
 *    attached to the test.
 *  - **The appointment book.** An eye test is not a sale, it is twenty minutes
 *    of one person's day, and the thing that goes wrong is two people booked
 *    into the same twenty minutes. So the book refuses a clash rather than
 *    recording one, which is the only rule it really has to enforce.
 *  - **The collection desk.** `delivered` existed as a status; who actually
 *    collected the spectacles did not. A pair is very often picked up by
 *    somebody other than the person whose eyes were tested — a son, a driver,
 *    a neighbour — and "we gave them to a man who said he was your husband" is
 *    not an answer. The desk also owns telling the customer they are ready,
 *    which is the single most common reason a finished pair sits in a drawer
 *    for three weeks.
 *  - **The warranty.** A frame breaks and a coating peels, and both are
 *    routinely honoured by the shop. Core already has the register
 *    (`product_warranties`) and the policy on the article
 *    (`products.warranty_months`); what was missing was anybody issuing the
 *    card. Handing the pair over now does it, from the *article's* policy — a
 *    frame's two years and a lens coating's one are two different promises and
 *    become two different cards.
 *
 * **Frame Details** is the one core capability that was overstated. A frame was
 * a boolean on the product and nothing else, which is not enough to sell one:
 * the trade's whole vocabulary is the boxed marking (52▫18-140), the shape, the
 * rim and the material, and a customer asks for exactly those. They are columns
 * on `products` rather than a table of their own, for the same reason the lens
 * specification is: a frame *is* an article the shop stocks, prices, barcodes
 * and counts, and giving it a parallel master would mean two records of one
 * thing that could disagree about the price.
 *
 * Everything else an optical shop does — buying, stock, counter billing, GST,
 * loyalty, labels — is core, ticked by the trade preset below.
 */
class OpticalPlugin implements IndustryPlugin
{
    public function key(): string
    {
        return 'optical';
    }

    public function label(): string
    {
        return 'Optical & Eyewear';
    }

    public function icon(): string
    {
        return 'pi pi-eye';
    }

    public function description(): string
    {
        return 'Opticians and eyewear stores: eye tests, lens and frame specifications, the appointment book, spectacle orders through the lab, collection and warranty.';
    }

    /**
     * Teach core about the columns this plugin added to it, and put the one rule
     * that must hold on every path behind an observer.
     *
     * The observer is not decoration. An order can reach `delivered` through the
     * core workshop flow (OpticalOrderController::transition) or through this
     * plugin's collection desk, and a warranty card that only one of those two
     * issued would mean a customer's cover depending on which screen the counter
     * happened to use. Same for `fitted_at`: core stamps ordered/received/
     * delivered and never stamped the moment a pair actually became collectable,
     * which is the clock the desk is judged on.
     */
    public function boot(): void
    {
        OpticalOrder::observe(OpticalOrderObserver::class);

        // A spectacle order's appointments: the fitting and the collection slot
        // booked against a specific pair.
        OpticalOrder::resolveRelationUsing(
            'appointments',
            static fn (OpticalOrder $order) => $order->hasMany(OpticalAppointment::class),
        );

        // The eye test's own diary entry — the slot it came out of.
        EyePrescription::resolveRelationUsing(
            'appointments',
            static fn (EyePrescription $rx) => $rx->hasMany(OpticalAppointment::class),
        );

        // The cards issued when a pair was handed over, reachable from the pair.
        OpticalOrder::resolveRelationUsing(
            'warranties',
            static fn (OpticalOrder $order) => $order->hasMany(ProductWarranty::class),
        );
        ProductWarranty::resolveRelationUsing(
            'opticalOrder',
            static fn (ProductWarranty $card) => $card->belongsTo(OpticalOrder::class),
        );

        // Frames and lenses are both ordinary products; these scopes are what
        // the order form's two pickers are built from.
        Product::resolveRelationUsing(
            'opticalOrdersAsFrame',
            static fn (Product $product) => $product->hasMany(OpticalOrder::class, 'frame_product_id'),
        );
    }

    /**
     * What this trade adds on top of the Core ERP set, which ShopProfiles::all()
     * merges in.
     *
     * @return array<string, array<string, mixed>>
     */
    public function trades(): array
    {
        return [
            /**
             * Trade key unchanged from when this was one of Retail's five. It is
             * stored on tenants as `shop_profile`, so renaming it would strand
             * every optician already on the platform — the key moves between
             * plugins, it does not change.
             */
            'optical' => [
                'label' => 'Optical Shop',
                'icon' => 'pi pi-eye',
                'description' => 'Eye tests and the appointment book, lens and frame specifications, spectacle orders through the lab, collection and warranty',
                /**
                 * `warranty` is new to this preset and is not optional: the
                 * cards this plugin issues on handover are that module's
                 * register, and without it a shop would be told cover was
                 * granted with no screen to read it on. `loyalty` because
                 * spectacles are a two-yearly repeat purchase and the shop
                 * lives on the same faces coming back; `devices` for the label
                 * printer that marks up a frame tray.
                 */
                'modules' => ['optical', 'warranty', 'sales', 'pos', 'purchase', 'inventory', 'accounts', 'gst', 'loyalty', 'crm', 'devices', 'ai'],
                'features' => [
                    'eye_power' => 'available',
                    'lens_details' => 'available',
                    'frame_details' => 'available',
                    'prescription_upload' => 'available',
                    'appointment' => 'available',
                    'spectacle_order' => 'available',
                    'delivery' => 'available',
                    'warranty' => 'available',
                    // Delivered by modules this preset ticks, not by this
                    // plugin — named because they are what the trade asks
                    // about, and a map listing only one plugin's work would
                    // read as a gap.
                    'barcode' => 'available',
                    'loyalty' => 'available',
                    /**
                     * Contact lenses are sold by the same counter and are not
                     * the same article: base curve and diameter rather than
                     * axis and PD, a replacement schedule rather than a pair,
                     * and cover that is a batch recall rather than a warranty.
                     * Declared rather than implied, so nobody buys the vertical
                     * expecting a contact-lens fitting record.
                     */
                    'contact_lens_fitting' => 'planned',
                    /**
                     * The lab bill. Grinding is bought from an outside lab and
                     * the docket is chased on the order, but reconciling the
                     * lab's monthly invoice against the pairs it actually
                     * ground is done on paper today.
                     */
                    'lab_billing' => 'planned',
                ],
            ],
        ];
    }
}
