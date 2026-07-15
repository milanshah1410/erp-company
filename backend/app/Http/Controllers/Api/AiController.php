<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Ai\AiProviderManager;
use App\Services\Ai\AiReportService;
use App\Services\Ai\ErpSearchService;
use App\Services\Ai\ForecastService;
use App\Services\Ai\InventoryIntelligenceService;
use Illuminate\Http\Request;

/**
 * AI Features (SRS §22): sales forecast, demand prediction, auto purchase
 * suggestion, low-stock prediction, AI reports, and the chat / Gujarati-voice
 * ERP assistant. The heavy lifting lives in the App\Services\Ai\* services; this
 * controller only validates input and shapes the JSON envelope.
 */
class AiController extends Controller
{
    public function __construct(
        private readonly ForecastService $forecast,
        private readonly InventoryIntelligenceService $inventory,
        private readonly AiReportService $reports,
        private readonly ErpSearchService $search,
        private readonly AiProviderManager $providers,
    ) {}

    /** Which narration provider is active + capability flags for the UI. */
    public function status()
    {
        $provider = $this->providers->provider();

        return response()->json([
            'data' => [
                'configured_driver' => $this->providers->configuredDriver(),
                'active_provider' => $provider->name(),
                'using_hosted_model' => $this->providers->usingHostedModel(),
                'capabilities' => [
                    'sales_forecast', 'demand_prediction', 'purchase_suggestion',
                    'low_stock_prediction', 'ai_reports', 'chat_search', 'voice_assistant',
                ],
            ],
        ]);
    }

    /** Monthly revenue forecast with trend, seasonality and a confidence band. */
    public function salesForecast(Request $request)
    {
        $validated = $request->validate([
            'history_months' => ['sometimes', 'integer', 'min:3', 'max:36'],
            'periods' => ['sometimes', 'integer', 'min:1', 'max:24'],
        ]);

        return response()->json([
            'data' => $this->forecast->salesForecast(
                (int) ($validated['history_months'] ?? config('ai.history_months', 12)),
                (int) ($validated['periods'] ?? config('ai.forecast_periods', 6)),
            ),
        ]);
    }

    /** Per-product unit demand forecast for the coming period(s). */
    public function demandPrediction(Request $request)
    {
        $validated = $request->validate([
            'limit' => ['sometimes', 'integer', 'min:1', 'max:200'],
            'history_months' => ['sometimes', 'integer', 'min:3', 'max:24'],
            'periods' => ['sometimes', 'integer', 'min:1', 'max:12'],
        ]);

        return response()->json([
            'data' => $this->forecast->demandPrediction(
                (int) ($validated['limit'] ?? 20),
                (int) ($validated['history_months'] ?? config('ai.history_months', 12)),
                (int) ($validated['periods'] ?? 1),
            ),
        ]);
    }

    /** Forecast-driven reorder recommendations (with preferred supplier). */
    public function purchaseSuggestions(Request $request)
    {
        $validated = $request->validate([
            'lead_time_days' => ['sometimes', 'integer', 'min:0', 'max:180'],
            'safety_days' => ['sometimes', 'integer', 'min:0', 'max:180'],
            'window_days' => ['sometimes', 'integer', 'min:7', 'max:365'],
        ]);

        return response()->json([
            'data' => $this->inventory->purchaseSuggestions(
                isset($validated['lead_time_days']) ? (int) $validated['lead_time_days'] : null,
                isset($validated['safety_days']) ? (int) $validated['safety_days'] : null,
                isset($validated['window_days']) ? (int) $validated['window_days'] : null,
            ),
        ]);
    }

    /** Days-to-stockout prediction from recent sales velocity. */
    public function lowStockPrediction(Request $request)
    {
        $validated = $request->validate([
            'window_days' => ['sometimes', 'integer', 'min:7', 'max:365'],
        ]);

        return response()->json([
            'data' => $this->inventory->lowStockPrediction(
                isset($validated['window_days']) ? (int) $validated['window_days'] : null,
            ),
        ]);
    }

    /** Narrated cross-module business report with derived insights. */
    public function report(Request $request)
    {
        $validated = $request->validate([
            'history_months' => ['sometimes', 'integer', 'min:3', 'max:24'],
        ]);

        return response()->json([
            'data' => $this->reports->generate((int) ($validated['history_months'] ?? 6)),
        ]);
    }

    /** Chat + Gujarati voice: a natural-language question → structured answer. */
    public function search(Request $request)
    {
        $validated = $request->validate([
            'query' => ['required', 'string', 'max:500'],
        ]);

        return response()->json([
            'data' => $this->search->answer($validated['query']),
        ]);
    }
}
