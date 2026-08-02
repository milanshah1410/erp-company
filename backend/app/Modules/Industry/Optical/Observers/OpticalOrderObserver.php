<?php

declare(strict_types=1);

namespace App\Modules\Industry\Optical\Observers;

use App\Models\OpticalOrder;
use App\Models\ProductWarranty;
use App\Modules\Industry\Optical\Services\SpectacleWarrantyService;

/**
 * The two things that must happen wherever a spectacle order changes hands.
 *
 * A pair can reach `fitted` and `delivered` from the core workshop screen
 * (OpticalOrderController::transition) or from this plugin's collection desk.
 * Anything wired into only one of those two paths is a rule that holds on
 * Tuesdays — a customer's warranty would depend on which screen the counter
 * happened to open. So both live here, attached in OpticalPlugin::boot().
 *
 *  - **fitted_at.** Core stamps ordered, received and delivered. It never
 *    stamped the moment the lenses actually went into the frame, which is the
 *    clock the collection desk is judged on: "ready eleven days" is the number
 *    that matters, not "ordered three weeks ago".
 *
 *  - **Warranty.** Handing the pair over grants whatever cover its frame and its
 *    lens carry. Idempotent in SpectacleWarrantyService, so a re-save of a
 *    delivered order cannot print a second card.
 *
 * Both write with saveQuietly()/forceFill inside the service rather than
 * triggering another round of events, so nothing here can recurse.
 */
class OpticalOrderObserver
{
    public function __construct(private readonly SpectacleWarrantyService $warranties) {}

    public function updated(OpticalOrder $order): void
    {
        if ($order->wasChanged('status')) {
            if ($order->status === 'fitted' && ! $order->fitted_at) {
                $order->forceFill(['fitted_at' => now()])->saveQuietly();
            }

            if ($order->status === 'delivered' && ! $order->warranty_issued_at) {
                $this->warranties->issueFor($order);
            }
        }

        /**
         * Both delivery paths stamp the status first and bill immediately
         * afterwards, so the cards above are written a moment before the invoice
         * exists and would otherwise hold a null one for good. Filling it in
         * when the order gains its invoice is what keeps a claim traceable back
         * to the money — and it is written this way round, rather than by
         * delaying the cards, because an order billed at zero (a remake, a
         * goodwill replacement) never gains an invoice at all and must still
         * carry cover.
         */
        if ($order->wasChanged('invoice_id') && $order->invoice_id) {
            ProductWarranty::query()
                ->where('optical_order_id', $order->id)
                ->whereNull('invoice_id')
                ->update(['invoice_id' => $order->invoice_id]);
        }
    }
}
