<?php

declare(strict_types=1);

namespace App\Modules\Industry\Sports\Services;

use App\Modules\Industry\Sports\Models\Rental;
use App\Modules\Industry\Sports\Models\RentalAsset;
use App\Modules\Industry\Sports\Models\RentalItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * "Is bike 7 free on Sunday?"
 *
 * The one question a hire counter cannot answer from a stock figure, and the one
 * it is asked twenty times a weekend. Getting it wrong in the optimistic
 * direction is the failure that costs a hire business its reputation: two
 * customers promised the same six bikes, and the second one finds out on Sunday
 * morning.
 *
 * Two rules make the answer trustworthy:
 *
 * **An unreturned line occupies the asset indefinitely.** Not until its due time
 * — until it is actually back. A bike that was due Saturday and is still out on
 * Sunday is not available on Sunday, and an availability check that reads the due
 * date instead of the return would cheerfully promise it. This is the single most
 * important line in the file.
 *
 * **A booking occupies its window exactly as a hire does.** Otherwise the shop
 * can double-book everything it has held for next weekend, which is precisely
 * what holding it was for.
 */
class RentalAvailabilityService
{
    /**
     * Asset ids that are spoken for during [$from, $to).
     *
     * @param  int|null  $ignoreRentalId  Exclude one contract's own lines — needed
     *                                    whenever an existing hire is being edited,
     *                                    or it would collide with itself.
     * @return list<int>
     */
    public function occupiedAssetIds(Carbon $from, Carbon $to, ?int $ignoreRentalId = null): array
    {
        return RentalItem::query()
            ->whereNull('returned_at')
            ->when($ignoreRentalId, fn (Builder $q) => $q->where('rental_id', '!=', $ignoreRentalId))
            ->whereHas('rental', function (Builder $q) use ($from, $to) {
                // Both alternatives live inside one nested group. Written flat,
                // the trailing orWhere would escape the status filter and start
                // matching closed contracts.
                $q->whereIn('status', Rental::HOLDING_STATUSES)
                    ->where(function (Builder $group) use ($from, $to) {
                        /**
                         * Anything already out has no end date that can be relied
                         * on — it is occupied from the moment it left until it
                         * comes back, whatever the paperwork promised. See the
                         * class note: this is the clause that stops the fleet
                         * being promised twice.
                         */
                        $group->where(function (Builder $inner) use ($to) {
                            $inner->where('status', 'out')
                                ->where(fn (Builder $b) => $b->whereNull('issued_at')->orWhere('issued_at', '<', $to));
                        })
                            ->orWhere(function (Builder $inner) use ($from, $to) {
                                // A booking occupies the window it reserved:
                                // overlap is start-before-our-end and
                                // end-after-our-start.
                                $inner->where('status', 'booked')
                                    ->where('due_at', '>', $from)
                                    ->where(fn (Builder $b) => $b->whereNull('booked_from')->orWhere('booked_from', '<', $to));
                            });
                    });
            })
            ->distinct()
            ->pluck('asset_id')
            ->map(static fn ($id) => (int) $id)
            ->all();
    }

    /**
     * The fleet that can actually go out over a window.
     *
     * Withdrawn and written-off kit is excluded by `hireable()`; everything else
     * is filtered on the contracts. The optional basis filter is not cosmetic — an
     * asset with no monthly rate is genuinely not on offer by the month, and
     * showing it to a counter taking a monthly booking only produces a contract
     * that then has to be refused.
     *
     * @return Collection<int, RentalAsset>
     */
    public function availableBetween(
        Carbon $from,
        Carbon $to,
        ?string $basis = null,
        ?int $warehouseId = null,
        ?int $ignoreRentalId = null,
    ): Collection {
        $occupied = $this->occupiedAssetIds($from, $to, $ignoreRentalId);

        return RentalAsset::query()
            ->hireable()
            ->when($warehouseId, fn (Builder $q) => $q->where('warehouse_id', $warehouseId))
            ->when($occupied !== [], fn (Builder $q) => $q->whereNotIn('id', $occupied))
            ->when($basis, function (Builder $q) use ($basis) {
                $column = RentalAsset::RATE_COLUMNS[$basis] ?? null;

                return $column ? $q->whereNotNull($column) : $q;
            })
            ->with(['product:id,sku,name', 'variant:id,name,attributes', 'brand:id,name', 'warehouse:id,name'])
            ->orderBy('category')
            ->orderBy('asset_code')
            ->get();
    }

    /**
     * Why this asset cannot be put on a contract over this window — null when it
     * can.
     *
     * A sentence rather than a boolean, because the counter has to say something
     * to the person in front of it, and "unavailable" is not something anyone can
     * act on. "Out with a customer, due back Sunday 6pm" is.
     */
    public function refusalFor(
        RentalAsset $asset,
        Carbon $from,
        Carbon $to,
        ?string $basis = null,
        ?int $ignoreRentalId = null,
    ): ?string {
        if (! $asset->is_active || $asset->status !== 'available') {
            return $asset->unavailableReason() ?? "{$asset->asset_code} is not available to hire.";
        }

        if ($basis !== null && $asset->rateFor($basis) === null) {
            return "{$asset->label()} has no {$basis} rate — it is not offered on that basis.";
        }

        if (! in_array($asset->id, $this->occupiedAssetIds($from, $to, $ignoreRentalId), true)) {
            return null;
        }

        $line = $asset->openLine();
        $rental = $line?->rental;

        if (! $rental) {
            return "{$asset->label()} is already spoken for over that period.";
        }

        $when = $rental->due_at?->format('D j M, H:i');

        return $rental->status === 'out'
            ? "{$asset->label()} is out on {$rental->rental_number}, due back {$when}."
            : "{$asset->label()} is held on booking {$rental->rental_number} until {$when}.";
    }

    /**
     * How hard each asset is working, over a period.
     *
     * The report that decides what to buy next season and what to sell off. Days
     * out against days in the period, and earnings against what it cost — a hire
     * fleet is capital, and the only honest question about a bike nobody hires is
     * why the shop is still storing it.
     *
     * Counted from returned lines only. An open hire has not earned anything yet;
     * including a live contract at its running quote would make utilisation drift
     * upward every time the report was refreshed.
     *
     * @return list<array<string, mixed>>
     */
    public function utilisation(Carbon $from, Carbon $to, ?int $warehouseId = null): array
    {
        $assets = RentalAsset::query()
            ->when($warehouseId, fn (Builder $q) => $q->where('warehouse_id', $warehouseId))
            ->orderBy('asset_code')
            ->get();

        $lines = RentalItem::query()
            ->whereNotNull('returned_at')
            ->whereBetween('returned_at', [$from, $to])
            ->with('rental:id,rate_basis,issued_at,due_at')
            ->get()
            ->groupBy('asset_id');

        $periodSeconds = max(1, $from->diffInSeconds($to));

        $rows = $assets->map(function (RentalAsset $asset) use ($lines, $periodSeconds) {
            /** @var Collection<int, RentalItem> $own */
            $own = $lines->get($asset->id, collect());

            $secondsOut = 0;
            $earned = 0.0;

            foreach ($own as $item) {
                $start = $item->rental?->issued_at;
                if ($start && $item->returned_at) {
                    $secondsOut += max(0, $start->diffInSeconds($item->returned_at));
                }
                $earned += $item->chargeTotal();
            }

            $cost = (float) ($asset->purchase_cost ?? 0);

            return [
                'asset_id' => $asset->id,
                'asset_code' => $asset->asset_code,
                'name' => $asset->name,
                'category' => $asset->category,
                'condition' => $asset->condition,
                'status' => $asset->derivedStatus(),
                'hires' => $own->count(),
                'days_out' => round($secondsOut / 86400, 2),
                'utilisation_percent' => round(min(100, $secondsOut / $periodSeconds * 100), 1),
                'earned' => round($earned, 2),
                'purchase_cost' => $cost > 0 ? round($cost, 2) : null,
                /**
                 * Null rather than zero when the shop never recorded what the
                 * asset cost: "0% recovered" is a claim, and an empty cell is the
                 * truth.
                 */
                'cost_recovered_percent' => $cost > 0 ? round($earned / $cost * 100, 1) : null,
            ];
        })->all();

        // Hardest-working kit first — the order a buying decision is read in.
        usort($rows, static fn (array $a, array $b) => $b['utilisation_percent'] <=> $a['utilisation_percent']);

        return $rows;
    }
}
