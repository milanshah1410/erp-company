<?php

namespace App\Services;

use App\Models\Salesman;
use App\Models\SalesmanTarget;
use App\Models\SalesOrder;
use Carbon\CarbonImmutable;

/**
 * Target achievement + incentive maths for the salesman module.
 *
 * Achievement is derived live from the orders a salesman booked in the period
 * (sales_orders.salesman_id, excluding cancelled), so it always reflects the
 * current order book rather than a stored snapshot. Incentive is earned only
 * once the amount target is met, at the target's effective rate applied to the
 * achieved sales value.
 */
class SalesmanIncentiveService
{
    /**
     * Compute achievement for a salesman in a given month. Accepts an optional
     * target so callers that already loaded one avoid a re-query.
     *
     * @return array{
     *     period_year:int, period_month:int,
     *     target_amount:float, target_orders:?int,
     *     achieved_amount:float, achieved_orders:int,
     *     achievement_percent:float, target_met:bool,
     *     incentive_percent:float, incentive_amount:float
     * }
     */
    public function achievement(Salesman $salesman, int $year, int $month, ?SalesmanTarget $target = null): array
    {
        $target ??= $salesman->targets()
            ->where('period_year', $year)
            ->where('period_month', $month)
            ->first();

        [$start, $end] = $this->monthBounds($year, $month);

        $orders = SalesOrder::query()
            ->where('salesman_id', $salesman->id)
            ->whereBetween('order_date', [$start->toDateString(), $end->toDateString()])
            ->where('status', '!=', 'cancelled');

        $achievedAmount = round((float) (clone $orders)->sum('total'), 2);
        $achievedOrders = (int) $orders->count();

        $targetAmount = round((float) ($target?->target_amount ?? 0), 2);
        $incentivePercent = $target
            ? $target->effectiveIncentivePercent()
            : (float) $salesman->default_incentive_percent;

        $achievementPercent = $targetAmount > 0
            ? round($achievedAmount / $targetAmount * 100, 2)
            : 0.0;
        $targetMet = $targetAmount > 0 && $achievedAmount + 0.005 >= $targetAmount;

        $incentiveAmount = $targetMet
            ? round($achievedAmount * $incentivePercent / 100, 2)
            : 0.0;

        return [
            'period_year' => $year,
            'period_month' => $month,
            'target_amount' => $targetAmount,
            'target_orders' => $target?->target_orders,
            'achieved_amount' => $achievedAmount,
            'achieved_orders' => $achievedOrders,
            'achievement_percent' => $achievementPercent,
            'target_met' => $targetMet,
            'incentive_percent' => $incentivePercent,
            'incentive_amount' => $incentiveAmount,
        ];
    }

    /** First and last day of the given month as immutable dates. */
    private function monthBounds(int $year, int $month): array
    {
        $start = CarbonImmutable::create($year, $month, 1)->startOfDay();

        return [$start, $start->endOfMonth()];
    }
}
