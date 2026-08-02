<?php

declare(strict_types=1);

namespace App\Modules\Industry\Optical\Services;

use App\Models\OpticalOrder;
use App\Models\Product;
use App\Models\ProductWarranty;
use App\Support\EnabledModules;
use App\Support\TenantContext;
use Illuminate\Support\Carbon;

/**
 * Cover on a pair of spectacles.
 *
 * There is deliberately no warranty table in this plugin. Core's
 * `product_warranties` is already the register a claim is settled against, and
 * `products.warranty_months` is already where the policy lives — an optician
 * holding a second warranty screen that disagreed with the first would be worse
 * than holding none. What was missing was anybody *issuing* the card, because a
 * spectacle order is not billed like an ordinary sale and never passed the
 * counter's "register this warranty?" step.
 *
 * The one thing this trade does differently is that a pair is two articles under
 * two different promises. A frame's cover is against breakage and hinge failure,
 * usually a year from the manufacturer; a lens's is against the coating peeling
 * or crazing, often six months and from a different maker entirely. Honouring
 * one does not touch the other — a customer sits on their frame and comes back
 * with perfectly good lenses — so they are two cards, each carrying its own
 * article's term. A single card for "the spectacles" would have to pick one of
 * the two terms and would be wrong about the other.
 *
 * Issued from the *article's* policy, snapshotted at handover: shortening next
 * season's terms must not retroactively cancel cover a customer already holds.
 */
class SpectacleWarrantyService
{
    /**
     * Issue whatever cover the pair's articles carry.
     *
     * Idempotent twice over: `warranty_issued_at` latches the order, and each
     * card is checked against (order, product) before it is written. Both are
     * needed — the latch stops the ordinary re-save, and the per-card check
     * stops a pair whose lens was registered by hand from acquiring a duplicate
     * when it is delivered.
     *
     * @return list<ProductWarranty>
     */
    public function issueFor(OpticalOrder $order, ?int $userId = null): array
    {
        if (! $this->registerAvailable()) {
            // The workspace does not hold the warranty register. Writing cards
            // it has no screen to read would be cover the shop cannot honour
            // because it cannot find it.
            return [];
        }

        $order->loadMissing(['frame', 'lens']);

        $start = $order->delivered_at ? Carbon::parse($order->delivered_at) : now();
        $issued = [];

        foreach ([$order->frame, $order->lens] as $product) {
            $card = $this->issueOne($order, $product, $start, $userId);

            if ($card) {
                $issued[] = $card;
            }
        }

        // Stamped whether or not anything was issued. "Nobody has looked at
        // cover for this pair" and "we looked, and neither article carries any"
        // are different facts, and only the first is worth revisiting.
        if (! $order->warranty_issued_at) {
            $order->forceFill(['warranty_issued_at' => now()])->saveQuietly();
        }

        return $issued;
    }

    /** The cover an article would grant today, for the desk to show before handing over. */
    public function quoteFor(OpticalOrder $order): array
    {
        $order->loadMissing(['frame', 'lens']);

        $quote = [];

        foreach (['frame' => $order->frame, 'lens' => $order->lens] as $slot => $product) {
            $months = (int) ($product?->warranty_months ?? 0);

            $quote[$slot] = $months > 0 ? [
                'product' => $product?->name,
                'months' => $months,
                'terms' => $product?->warranty_terms,
            ] : null;
        }

        return $quote;
    }

    private function issueOne(OpticalOrder $order, ?Product $product, Carbon $start, ?int $userId): ?ProductWarranty
    {
        $months = (int) ($product?->warranty_months ?? 0);

        if (! $product || $months < 1) {
            // No policy on the article: a card with no term covers nothing, and
            // an optician who wants one anyway can still write it by hand on the
            // warranty screen.
            return null;
        }

        $exists = ProductWarranty::query()
            ->where('optical_order_id', $order->id)
            ->where('product_id', $product->id)
            ->exists();

        if ($exists) {
            return null;
        }

        return ProductWarranty::create([
            'warranty_number' => ProductWarranty::nextNumber(),
            'customer_id' => $order->customer_id,
            'product_id' => $product->id,
            'invoice_id' => $order->invoice_id,
            'optical_order_id' => $order->id,
            /**
             * Both a frame and a coated lens are made by somebody else and their
             * cover is passed up the chain, which is what `manufacturer` decides
             * — not merely how the card reads. A shop standing behind a pair out
             * of its own pocket changes it on the card.
             */
            'source' => 'manufacturer',
            'start_date' => $start->toDateString(),
            'end_date' => $start->copy()->addMonths($months)->toDateString(),
            'coverage_months' => $months,
            'status' => 'active',
            'terms' => $product->warranty_terms,
            'notes' => "Issued on handover of spectacle order {$order->order_number}.",
            'created_by' => $userId,
        ]);
    }

    /** Does this workspace actually hold the warranty register? */
    private function registerAvailable(): bool
    {
        $enabled = EnabledModules::forTenant(app(TenantContext::class)->id());

        // null means the workspace never chose a module set, so gating is a
        // no-op everywhere else and must be here too.
        return $enabled === null || in_array('warranty', $enabled, true);
    }
}
