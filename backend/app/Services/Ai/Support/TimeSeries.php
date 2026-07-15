<?php

declare(strict_types=1);

namespace App\Services\Ai\Support;

/**
 * Small, dependency-free time-series toolkit shared by the AI forecasting and
 * demand engines. Everything here is deterministic ordinary maths — no external
 * calls — so forecasts are reproducible and testable.
 *
 * @phpstan-type Point array{x:int, y:float}
 */
class TimeSeries
{
    /**
     * Ordinary-least-squares linear fit of y against the index 0..n-1.
     *
     * @param  list<float>  $y
     * @return array{slope:float, intercept:float}
     */
    public static function linearFit(array $y): array
    {
        $n = count($y);
        if ($n === 0) {
            return ['slope' => 0.0, 'intercept' => 0.0];
        }
        if ($n === 1) {
            return ['slope' => 0.0, 'intercept' => $y[0]];
        }

        $sumX = 0.0;
        $sumY = 0.0;
        $sumXY = 0.0;
        $sumXX = 0.0;
        foreach ($y as $i => $val) {
            $sumX += $i;
            $sumY += $val;
            $sumXY += $i * $val;
            $sumXX += $i * $i;
        }

        $denom = ($n * $sumXX) - ($sumX * $sumX);
        if ($denom == 0.0) {
            return ['slope' => 0.0, 'intercept' => $sumY / $n];
        }

        $slope = (($n * $sumXY) - ($sumX * $sumY)) / $denom;
        $intercept = ($sumY - ($slope * $sumX)) / $n;

        return ['slope' => $slope, 'intercept' => $intercept];
    }

    /**
     * Trailing simple moving average of the last $window points.
     *
     * @param  list<float>  $y
     */
    public static function movingAverage(array $y, int $window): float
    {
        if ($y === []) {
            return 0.0;
        }
        $window = max(1, min($window, count($y)));
        $slice = array_slice($y, -$window);

        return array_sum($slice) / count($slice);
    }

    /**
     * Multiplicative seasonal indices keyed by calendar month (1..12), derived
     * from the average of each month relative to the overall mean. An index of
     * 1.2 means that month typically runs 20% above average. Months with no data
     * default to 1.0 (neutral).
     *
     * @param  array<int,float>  $byMonthTotals  month(1-12) => summed value
     * @param  array<int,int>  $byMonthCounts  month(1-12) => number of samples
     * @return array<int,float>
     */
    public static function seasonalIndices(array $byMonthTotals, array $byMonthCounts): array
    {
        $means = [];
        foreach ($byMonthTotals as $month => $total) {
            $count = $byMonthCounts[$month] ?? 0;
            if ($count > 0) {
                $means[$month] = $total / $count;
            }
        }

        $indices = array_fill(1, 12, 1.0);
        if ($means === []) {
            return $indices;
        }

        $overall = array_sum($means) / count($means);
        if ($overall == 0.0) {
            return $indices;
        }

        foreach ($means as $month => $mean) {
            $indices[$month] = round($mean / $overall, 4);
        }

        return $indices;
    }

    /**
     * Standard deviation of a series (population). Used to size confidence bands.
     *
     * @param  list<float>  $y
     */
    public static function stdDev(array $y): float
    {
        $n = count($y);
        if ($n < 2) {
            return 0.0;
        }
        $mean = array_sum($y) / $n;
        $variance = 0.0;
        foreach ($y as $val) {
            $variance += ($val - $mean) ** 2;
        }

        return sqrt($variance / $n);
    }

    /**
     * A 0..1 confidence heuristic: more history and lower relative volatility
     * yield higher confidence. Kept simple and explainable for the UI.
     *
     * @param  list<float>  $y
     */
    public static function confidence(array $y): float
    {
        $n = count($y);
        if ($n < 2) {
            return 0.25;
        }

        $mean = array_sum($y) / $n;
        $cv = $mean > 0 ? self::stdDev($y) / $mean : 1.0; // coefficient of variation
        $volatilityScore = max(0.0, 1.0 - min($cv, 1.0));
        $historyScore = min(1.0, $n / 12);

        return round(0.35 + 0.65 * (0.5 * $volatilityScore + 0.5 * $historyScore), 2);
    }
}
