<?php

declare(strict_types=1);

namespace App\Modules\Industry\Toys\Services;

use App\Models\Product;
use App\Modules\Industry\Toys\Models\AgeGrade;
use App\Modules\Industry\Toys\Models\SafetyCertificate;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Answers, for a cart or a single article, the two questions a toy counter is
 * responsible for: who is this for, and are we allowed to sell it?
 *
 * The second one has teeth. Under the Toys (Quality Control) Order a toy in
 * India needs a live BIS licence behind it, and the licence belongs to the
 * manufacturer, not to the shop — so a shop can be selling a perfectly good toy
 * illegally because a piece of paper it never saw expired last month. The
 * counter cannot be asked to remember that, and the customer certainly cannot,
 * which is why the check happens where the sale does.
 *
 * The service is deliberately two-speed:
 *
 *  - inspect() is advisory and never refuses anything. It is what the till calls
 *    while the customer is still choosing, and what puts "not for under 3s —
 *    small parts" in front of the person buying for a toddler.
 *  - assertSellable() refuses, and only ever for a category whose owner ticked
 *    `blocks_sale_without_certificate`. A shop half-way through capturing its
 *    catalogue must not have its till shut down by missing data.
 *
 * Nothing here is a judgement about whether a toy is dangerous. The shop makes
 * that call once, in the safety category; this only reports what it said, and
 * whether the paperwork behind it is still good today.
 */
class SafetyComplianceService
{
    /**
     * Look over a cart and report what the counter should say.
     *
     * @param  list<array{product_id: int|string, quantity?: mixed}>  $items
     * @return array{
     *     lines: list<array<string, mixed>>,
     *     has_warnings: bool,
     *     blocked: list<string>,
     * }
     */
    public function inspect(array $items): array
    {
        $products = $this->load($items);

        $lines = [];
        $blocked = [];

        foreach ($products as $product) {
            $line = $this->assess($product);

            if ($line['warnings'] !== [] || $line['certificate_state'] !== 'not_required') {
                $lines[] = $line;
            }

            if ($line['blocks_sale']) {
                $blocked[] = $line['refusal'];
            }
        }

        return [
            'lines' => $lines,
            'has_warnings' => (bool) array_filter($lines, static fn (array $l) => $l['warnings'] !== []),
            'blocked' => $blocked,
        ];
    }

    /**
     * Refuse the sale if anything on it may not legally leave the shop.
     *
     * Called before a single row is written, so a refused bill leaves no stock
     * movement or half-built order behind — the same contract PharmacyService's
     * prescription gate keeps at the same point in the checkout.
     *
     * @param  list<array{product_id: int|string, quantity?: mixed}>  $items
     *
     * @throws RuntimeException
     */
    public function assertSellable(array $items): void
    {
        $blocked = $this->inspect($items)['blocked'];

        if ($blocked !== []) {
            throw new RuntimeException(implode(' ', $blocked));
        }
    }

    /**
     * Everything known about one article's suitability and paperwork.
     *
     * @return array<string, mixed>
     */
    public function assess(Product $product): array
    {
        $grade = $product->toyAgeGrade ?? null;
        $category = $product->toySafetyCategory ?? null;

        $warnings = [];

        if ($category?->warning()) {
            $warnings[] = $category->warning();
        }

        if ($grade?->requires_supervision) {
            $warnings[] = 'An adult should be supervising.';
        }

        /**
         * The mislabelling check. A shop that grades a toy with small parts as
         * suitable from eighteen months has not made a typing mistake — it has
         * put a choking hazard on the toddler shelf — and neither field can
         * catch it alone.
         */
        if ($grade && $category?->min_age_months && $grade->conflictsWithFloor((int) $category->min_age_months)) {
            $warnings[] = sprintf(
                'Age grade "%s" is below the %s floor this safety class requires.',
                $grade->label,
                AgeGrade::describeMonths((int) $category->min_age_months),
            );
        }

        [$state, $certificate, $reason] = $this->certificateState($product);

        if ($reason) {
            $warnings[] = $reason;
        }

        $blocks = $state !== 'live'
            && $state !== 'not_required'
            && (bool) $category?->blocks_sale_without_certificate;

        return [
            'product_id' => $product->id,
            'product' => $product->name,
            'sku' => $product->sku,
            'age_grade' => $grade ? [
                'id' => $grade->id,
                'label' => $grade->label,
                'range' => $grade->range(),
                'warning' => $grade->warning,
                'requires_supervision' => (bool) $grade->requires_supervision,
            ] : null,
            'safety_category' => $category ? [
                'id' => $category->id,
                'label' => $category->label,
                'standard' => $category->standard,
                'hazards' => $category->hazardLabels(),
                'min_age_months' => $category->min_age_months,
            ] : null,
            'certificate_state' => $state,
            'certificate' => $certificate ? [
                'id' => $certificate->id,
                'certificate_number' => $certificate->certificate_number,
                'standard' => $certificate->standard,
                'covers' => $certificate->coverageLabel(),
                'expires_on' => $certificate->expires_on?->toDateString(),
                'days_remaining' => $certificate->daysRemaining(),
            ] : null,
            'warnings' => $warnings,
            'blocks_sale' => $blocks,
            'refusal' => $blocks
                ? sprintf('%s may not be sold: %s', $product->name, lcfirst((string) ($reason ?? 'its safety certificate is not in order.')))
                : null,
        ];
    }

    /**
     * The narrowest live certificate covering an article, or why there is none.
     *
     * States, and what each means to a counter:
     *  - not_required — the class says the article is outside the scheme.
     *  - live         — covered today.
     *  - expired      — paperwork exists and has lapsed. The dangerous case: the
     *                   shop believes it is covered.
     *  - missing      — nothing was ever recorded.
     *
     * @return array{0: string, 1: ?SafetyCertificate, 2: ?string}
     */
    public function certificateState(Product $product): array
    {
        $category = $product->toySafetyCategory ?? null;

        if (! $category || ! $category->requires_certificate || $category->standard === 'none') {
            return ['not_required', null, null];
        }

        $candidates = SafetyCertificate::query()->covering($product)->get();

        foreach ($candidates as $certificate) {
            if ($certificate->isLive()) {
                return ['live', $certificate, null];
            }
        }

        if ($candidates->isEmpty()) {
            return [
                'missing',
                null,
                sprintf('No %s certificate is on file for this article.', strtoupper($category->standard)),
            ];
        }

        // Report the one that lapsed most recently: it is the paper the shop
        // thinks it has, and the one it needs to go and renew.
        $latest = $candidates->first();

        return ['expired', $latest, $latest->lapseReason()];
    }

    /**
     * Load the cart's products with the two relations every assessment reads.
     *
     * @param  list<array{product_id: int|string, quantity?: mixed}>  $items
     * @return Collection<int, Product>
     */
    private function load(array $items): Collection
    {
        $ids = array_values(array_unique(array_filter(array_map(
            static fn (array $item) => (int) ($item['product_id'] ?? 0),
            $items,
        ))));

        if ($ids === []) {
            return collect();
        }

        /**
         * The relations exist only while the plugin is registered (ToysPlugin
         * attaches them at boot), so this is also the guard that keeps a
         * workspace without Toys from ever reaching the assessment path.
         */
        return Product::query()
            ->whereIn('id', $ids)
            ->with(['toyAgeGrade', 'toySafetyCategory'])
            ->get();
    }
}
