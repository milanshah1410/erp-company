<?php

declare(strict_types=1);

namespace App\Modules\Industry\Stationery\Observers;

use App\Models\SalesOrder;
use App\Models\SalesQuotation;

/**
 * Carries a school's identity across the one hop where it would otherwise be
 * lost: quotation → sales order.
 *
 * A tender is answered with a quotation and, weeks later, converted. Core's
 * `SalesQuotationController::convert` builds the order from an explicit list of
 * columns — correctly, since it must not know that a stationery shop exists — so
 * the institution and the booklist behind the quote would simply not arrive. The
 * order would still be right about the money and wrong about who it was for,
 * which is the version of this bug nobody notices until the season is over and
 * the school-wise report is empty.
 *
 * Done as an observer rather than in this plugin's own endpoints because the
 * conversion usually happens on the ordinary Quotations screen — that is where a
 * shop running both the counter and the school desk will press the button, and a
 * fix that only worked from the plugin's screen would work in the demo and fail
 * in the shop.
 */
class SchoolQuotationObserver
{
    /**
     * Stamp the order a quotation has just been converted into.
     *
     * Fires only on the transition: `sales_order_id` going from empty to set is
     * exactly the moment of conversion, and testing for it means an ordinary
     * edit to a converted quote does not keep re-writing the order.
     */
    public function updated(SalesQuotation $quotation): void
    {
        if (! $quotation->wasChanged('sales_order_id') || ! $quotation->sales_order_id) {
            return;
        }

        if (! $quotation->stn_institution_id) {
            return;
        }

        $order = SalesOrder::find($quotation->sales_order_id);

        if (! $order || $order->stn_institution_id) {
            return;
        }

        /**
         * The channel moves with the references. An institutional order that
         * bills on the `standard` channel is invisible to every report the shop
         * would use to ask how the season went, and the whole reason the channel
         * was widened was so that question needs no report of its own.
         */
        $order->forceFill([
            'stn_institution_id' => $quotation->stn_institution_id,
            'stn_class_kit_id' => $quotation->stn_class_kit_id,
            'channel' => 'institution',
        ])->save();
    }
}
