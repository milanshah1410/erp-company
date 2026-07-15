<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Setting;
use App\Models\StockLedger;
use App\Models\WarehouseStock;
use Carbon\CarbonImmutable;

/**
 * AI Reports (SRS §22): compiles a cross-module business snapshot, derives
 * plain-language insights from it, and (optionally) runs the narrative through a
 * hosted model for polish. The metrics and the deterministic insight list are
 * always returned so the report is meaningful with zero AI configuration.
 */
class AiReportService
{
    public function __construct(
        private readonly ForecastService $forecast = new ForecastService,
        private readonly InventoryIntelligenceService $inventory = new InventoryIntelligenceService,
        private readonly AiProviderManager $providers = new AiProviderManager,
    ) {}

    /**
     * @return array{period:string, generated_at:string, provider:string, metrics:array<string,mixed>, insights:list<string>, narrative:string}
     */
    public function generate(int $historyMonths = 6): array
    {
        $metrics = $this->collectMetrics($historyMonths);
        $insights = $this->deriveInsights($metrics);
        $fallbackNarrative = $this->composeNarrative($metrics, $insights);

        // Optionally polish via a hosted model; falls back to the local narrative.
        $narrative = $this->providers->narrate(
            system: 'You are an ERP business analyst for a textile/garment wholesaler in Gujarat, India. '
                .'Write a concise, professional business summary (120-180 words) from the metrics and insights provided. '
                .'Use Indian Rupee (₹) formatting. Do not invent numbers beyond what is given.',
            prompt: $this->promptFrom($metrics, $insights),
            fallback: $fallbackNarrative,
        );

        return [
            'period' => "Last {$historyMonths} months",
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
            'provider' => $this->providers->provider()->name(),
            'metrics' => $metrics,
            'insights' => $insights,
            'narrative' => $narrative,
        ];
    }

    /** @return array<string,mixed> */
    private function collectMetrics(int $historyMonths): array
    {
        $monthStart = CarbonImmutable::now()->startOfMonth();
        $prevMonthStart = $monthStart->subMonth();

        $thisMonthRevenue = (float) Invoice::query()
            ->where('invoice_date', '>=', $monthStart->toDateString())
            ->sum('total');

        $prevMonthRevenue = (float) Invoice::query()
            ->whereBetween('invoice_date', [$prevMonthStart->toDateString(), $monthStart->subDay()->toDateString()])
            ->sum('total');

        $momChange = $prevMonthRevenue > 0
            ? round((($thisMonthRevenue - $prevMonthRevenue) / $prevMonthRevenue) * 100, 1)
            : null;

        // Receivables (outstanding on unpaid/partial invoices).
        $outstanding = (float) Invoice::query()
            ->whereIn('status', ['unpaid', 'partial'])
            ->get(['total', 'paid_amount', 'credited_amount'])
            ->sum(fn ($i) => max(0, (float) $i->total - (float) $i->paid_amount - (float) $i->credited_amount));

        $overdue = (float) Invoice::query()
            ->whereIn('status', ['unpaid', 'partial'])
            ->whereDate('due_date', '<', now()->toDateString())
            ->get(['total', 'paid_amount', 'credited_amount'])
            ->sum(fn ($i) => max(0, (float) $i->total - (float) $i->paid_amount - (float) $i->credited_amount));

        // Top products (units, last 30 days).
        $topProducts = StockLedger::query()
            ->join('products', 'products.id', '=', 'stock_ledgers.product_id')
            ->where('stock_ledgers.type', 'sale')
            ->where('stock_ledgers.created_at', '>=', now()->subDays(30))
            ->selectRaw('products.sku, products.name, SUM(stock_ledgers.quantity_out) as qty')
            ->groupBy('products.id', 'products.sku', 'products.name')
            ->orderByDesc('qty')
            ->limit(5)
            ->get()
            ->map(fn ($r) => ['sku' => $r->sku, 'name' => $r->name, 'qty' => round((float) $r->qty, 2)])
            ->all();

        $lowStock = $this->inventory->lowStockPrediction();
        $criticalStock = array_values(array_filter($lowStock['items'], fn ($i) => in_array($i['risk'], ['critical', 'high'], true)));

        $purchase = $this->inventory->purchaseSuggestions();
        $purchaseValue = array_sum(array_map(fn ($i) => $i['estimated_cost'], $purchase['items']));

        $salesForecast = $this->forecast->salesForecast($historyMonths, 3);
        $nextMonthForecast = $salesForecast['forecast'][0]['value'] ?? 0.0;

        $deadStockCount = WarehouseStock::query()
            ->where('quantity', '>', 0)
            ->whereDoesntHave('product.stockLedgers', fn ($q) => $q
                ->where('type', 'sale')
                ->where('created_at', '>=', now()->subDays(90)))
            ->distinct('product_id')
            ->count('product_id');

        return [
            'currency_symbol' => Setting::currencySymbol(),
            'this_month_revenue' => round($thisMonthRevenue, 2),
            'prev_month_revenue' => round($prevMonthRevenue, 2),
            'mom_change_pct' => $momChange,
            'outstanding_receivables' => round($outstanding, 2),
            'overdue_receivables' => round($overdue, 2),
            'sales_trend' => $salesForecast['trend'],
            'sales_growth_rate' => $salesForecast['growth_rate'],
            'next_month_forecast' => round($nextMonthForecast, 2),
            'forecast_confidence' => $salesForecast['confidence'],
            'top_products' => $topProducts,
            'critical_stock_count' => count($criticalStock),
            'critical_stock' => array_slice($criticalStock, 0, 5),
            'purchase_suggestion_count' => count($purchase['items']),
            'purchase_suggestion_value' => round($purchaseValue, 2),
            'dead_stock_count' => $deadStockCount,
            'total_products' => Product::query()->count(),
            'active_customers' => Customer::query()->count(),
        ];
    }

    /** @return list<string> */
    private function deriveInsights(array $m): array
    {
        $cur = $m['currency_symbol'];
        $insights = [];

        if ($m['mom_change_pct'] !== null) {
            $dir = $m['mom_change_pct'] >= 0 ? 'up' : 'down';
            $insights[] = sprintf(
                'Revenue this month is %s%.1f%% vs last month (%s%s vs %s%s).',
                $dir === 'up' ? '+' : '',
                $m['mom_change_pct'],
                $cur, number_format($m['this_month_revenue']),
                $cur, number_format($m['prev_month_revenue'])
            );
        }

        $insights[] = sprintf(
            'Sales trend is %s (%s%.1f%%/month). Next month is forecast at %s%s (%.0f%% confidence).',
            $m['sales_trend'],
            $m['sales_growth_rate'] >= 0 ? '+' : '',
            $m['sales_growth_rate'],
            $cur, number_format($m['next_month_forecast']),
            $m['forecast_confidence'] * 100
        );

        if ($m['overdue_receivables'] > 0) {
            $insights[] = sprintf(
                'Collections need attention: %s%s overdue out of %s%s total receivables.',
                $cur, number_format($m['overdue_receivables']),
                $cur, number_format($m['outstanding_receivables'])
            );
        } elseif ($m['outstanding_receivables'] > 0) {
            $insights[] = sprintf('Receivables are current: %s%s outstanding, none overdue.', $cur, number_format($m['outstanding_receivables']));
        }

        if ($m['critical_stock_count'] > 0) {
            $names = implode(', ', array_map(fn ($i) => $i['product']['name'], $m['critical_stock']));
            $insights[] = sprintf('%d fast-moving item(s) risk stockout within 3 weeks: %s.', $m['critical_stock_count'], $names);
        }

        if ($m['purchase_suggestion_count'] > 0) {
            $insights[] = sprintf(
                '%d product(s) recommended for reorder, est. %s%s of purchasing.',
                $m['purchase_suggestion_count'], $cur, number_format($m['purchase_suggestion_value'])
            );
        }

        if ($m['dead_stock_count'] > 0) {
            $insights[] = sprintf('%d product(s) have had no sales in 90 days — review for clearance to free up working capital.', $m['dead_stock_count']);
        }

        if (! empty($m['top_products'])) {
            $insights[] = 'Best sellers (30d): '.implode(', ', array_map(fn ($p) => $p['name'], array_slice($m['top_products'], 0, 3))).'.';
        }

        return $insights;
    }

    private function composeNarrative(array $m, array $insights): string
    {
        $lines = ['Business summary — '.CarbonImmutable::now()->format('d M Y').':', ''];
        foreach ($insights as $insight) {
            $lines[] = '• '.$insight;
        }

        return implode("\n", $lines);
    }

    private function promptFrom(array $metrics, array $insights): string
    {
        return "Metrics (JSON):\n".json_encode($metrics, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
            ."\n\nKey insights:\n- ".implode("\n- ", $insights);
    }
}
