<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Models\Invoice;
use App\Models\Product;
use App\Models\StockLedger;
use App\Services\Ai\Support\TimeSeries;
use Carbon\CarbonImmutable;

/**
 * Statistical sales & demand forecasting (SRS §22). Fully local and
 * deterministic: it blends a linear trend (OLS) with month-of-year seasonal
 * indices and a moving-average level, then projects future periods with a
 * confidence band sized from historical volatility.
 *
 * All queries are tenant-scoped automatically by the BelongsToTenant global
 * scope on the underlying models.
 */
class ForecastService
{
    /**
     * Monthly revenue forecast for the whole business.
     *
     * @return array{history:list<array{period:string,label:string,value:float}>, forecast:list<array{period:string,label:string,value:float,lower:float,upper:float}>, trend:string, growth_rate:float, confidence:float, seasonality:array<int,float>}
     */
    public function salesForecast(int $historyMonths = 12, int $periods = 6): array
    {
        $historyMonths = max(3, min(36, $historyMonths));
        $periods = max(1, min(24, $periods));

        $start = CarbonImmutable::now()->startOfMonth()->subMonths($historyMonths - 1);

        $rows = Invoice::query()
            ->where('invoice_date', '>=', $start->toDateString())
            ->selectRaw("DATE_FORMAT(invoice_date, '%Y-%m') as ym, SUM(total) as revenue")
            ->groupBy('ym')
            ->pluck('revenue', 'ym');

        $history = $this->buildMonthlySeries($start, $historyMonths, fn (string $ym) => (float) ($rows[$ym] ?? 0));

        return $this->project($history, $periods);
    }

    /**
     * Per-product demand (units) forecast for the next period, ranked by
     * projected demand. Uses goods-out ledger movements (sales) as the signal.
     *
     * @return list<array{product:array{id:int,sku:string,name:string}, history:list<float>, forecast_next:float, avg_monthly:float, trend:string, confidence:float}>
     */
    public function demandPrediction(int $limit = 20, int $historyMonths = 12, int $periods = 1): array
    {
        $historyMonths = max(3, min(24, $historyMonths));
        $periods = max(1, min(12, $periods));
        $limit = max(1, min(200, $limit));

        $start = CarbonImmutable::now()->startOfMonth()->subMonths($historyMonths - 1);

        // product_id => [ 'YYYY-MM' => qty ]
        $rows = StockLedger::query()
            ->where('type', 'sale')
            ->where('created_at', '>=', $start->startOfDay())
            ->selectRaw("product_id, DATE_FORMAT(created_at, '%Y-%m') as ym, SUM(quantity_out) as qty")
            ->groupBy('product_id', 'ym')
            ->get();

        $byProduct = [];
        foreach ($rows as $r) {
            $byProduct[$r->product_id][$r->ym] = (float) $r->qty;
        }

        if ($byProduct === []) {
            return [];
        }

        $products = Product::query()
            ->whereIn('id', array_keys($byProduct))
            ->get(['id', 'sku', 'name'])
            ->keyBy('id');

        $out = [];
        foreach ($byProduct as $productId => $monthMap) {
            $product = $products->get($productId);
            if (! $product) {
                continue;
            }

            $series = $this->buildMonthlySeries($start, $historyMonths, fn (string $ym) => (float) ($monthMap[$ym] ?? 0));
            $values = array_map(fn ($p) => $p['value'], $series);
            $projection = $this->project($series, $periods);

            $forecastNext = array_sum(array_map(fn ($p) => $p['value'], $projection['forecast']));
            $avgMonthly = count($values) > 0 ? array_sum($values) / count($values) : 0.0;

            $out[] = [
                'product' => ['id' => $product->id, 'sku' => $product->sku, 'name' => $product->name],
                'history' => array_map(fn ($v) => round($v, 3), $values),
                'forecast_next' => round($forecastNext, 3),
                'avg_monthly' => round($avgMonthly, 3),
                'trend' => $projection['trend'],
                'confidence' => $projection['confidence'],
            ];
        }

        usort($out, fn ($a, $b) => $b['forecast_next'] <=> $a['forecast_next']);

        return array_slice($out, 0, $limit);
    }

    /**
     * Build a dense, zero-filled monthly series for $count months from $start.
     *
     * @return list<array{period:string,label:string,value:float}>
     */
    private function buildMonthlySeries(CarbonImmutable $start, int $count, callable $valueFor): array
    {
        $series = [];
        for ($i = 0; $i < $count; $i++) {
            $month = $start->addMonths($i);
            $ym = $month->format('Y-m');
            $series[] = [
                'period' => $ym,
                'label' => $month->format('M Y'),
                'value' => (float) $valueFor($ym),
            ];
        }

        return $series;
    }

    /**
     * Core projection: trend + seasonality + level, with a volatility band.
     *
     * @param  list<array{period:string,label:string,value:float}>  $history
     * @return array{history:list<array{period:string,label:string,value:float}>, forecast:list<array{period:string,label:string,value:float,lower:float,upper:float}>, trend:string, growth_rate:float, confidence:float, seasonality:array<int,float>}
     */
    private function project(array $history, int $periods): array
    {
        $values = array_map(fn ($p) => $p['value'], $history);
        $n = count($values);

        $fit = TimeSeries::linearFit($values);
        $level = TimeSeries::movingAverage($values, 3);
        $std = TimeSeries::stdDev($values);
        $confidence = TimeSeries::confidence($values);

        // Seasonal indices by calendar month. Only months that actually had
        // activity contribute — a month with zero sales carries no seasonal
        // signal (e.g. a product that only started selling recently), otherwise
        // it would be read as a seasonal low and wrongly zero out the forecast.
        $byMonthTotals = [];
        $byMonthCounts = [];
        foreach ($history as $p) {
            if ($p['value'] <= 0) {
                continue;
            }
            $m = (int) CarbonImmutable::createFromFormat('Y-m', $p['period'])->format('n');
            $byMonthTotals[$m] = ($byMonthTotals[$m] ?? 0) + $p['value'];
            $byMonthCounts[$m] = ($byMonthCounts[$m] ?? 0) + 1;
        }
        $seasonality = TimeSeries::seasonalIndices($byMonthTotals, $byMonthCounts);

        $lastPeriod = $n > 0
            ? CarbonImmutable::createFromFormat('Y-m', $history[$n - 1]['period'])->startOfMonth()
            : CarbonImmutable::now()->startOfMonth();

        $forecast = [];
        for ($k = 1; $k <= $periods; $k++) {
            $month = $lastPeriod->addMonths($k);
            $idx = $n - 1 + $k;

            // Blend the regression trend value with the recent level for stability.
            $trendValue = $fit['slope'] * $idx + $fit['intercept'];
            $base = 0.6 * $trendValue + 0.4 * $level;

            // Clamp so a single spiky/sparse month can never dominate or zero
            // the projection — seasonality nudges, it doesn't override the trend.
            $seasonalFactor = max(0.5, min(1.5, $seasonality[(int) $month->format('n')] ?? 1.0));
            $point = max(0.0, $base * $seasonalFactor);

            // Band widens with horizon (sqrt of steps) and historical volatility.
            $band = $std * sqrt($k);

            $forecast[] = [
                'period' => $month->format('Y-m'),
                'label' => $month->format('M Y'),
                'value' => round($point, 2),
                'lower' => round(max(0.0, $point - $band), 2),
                'upper' => round($point + $band, 2),
            ];
        }

        $avg = $n > 0 ? array_sum($values) / $n : 0.0;
        $growthRate = $avg > 0 ? round(($fit['slope'] / $avg) * 100, 1) : 0.0;
        $trend = $this->trendLabel($fit['slope'], $avg);

        return [
            'history' => array_map(fn ($p) => ['period' => $p['period'], 'label' => $p['label'], 'value' => round($p['value'], 2)], $history),
            'forecast' => $forecast,
            'trend' => $trend,
            'growth_rate' => $growthRate,
            'confidence' => $confidence,
            'seasonality' => $seasonality,
        ];
    }

    private function trendLabel(float $slope, float $avg): string
    {
        if ($avg <= 0) {
            return 'flat';
        }
        $relative = $slope / $avg;
        if ($relative > 0.03) {
            return 'rising';
        }
        if ($relative < -0.03) {
            return 'falling';
        }

        return 'flat';
    }
}
