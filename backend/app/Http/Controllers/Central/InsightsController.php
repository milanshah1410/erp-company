<?php

declare(strict_types=1);

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Services\Platform\PlatformInsightsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * AI Insights for the platform panel (Volume 11 §"AI Insights") — churn
 * prediction, revenue forecast and upsell. Read-only, guarded by
 * `insights.view`.
 *
 * The maths lives in App\Services\Platform\PlatformInsightsService; this
 * controller only decides what is cached and for how long. Churn and upsell
 * each sweep every tenant, so they are cached briefly — an operator working the
 * list must not re-run the sweep on every sort click. `?fresh=1` bypasses the
 * cache for the case that actually matters: re-checking a tenant right after
 * acting on it.
 */
class InsightsController extends Controller
{
    private const TTL = 300;

    public function churn(Request $request, PlatformInsightsService $insights): JsonResponse
    {
        return response()->json([
            'data' => $this->remember('central_insights_churn', $request, fn () => $insights->churn()),
        ]);
    }

    public function forecast(Request $request, PlatformInsightsService $insights): JsonResponse
    {
        $months = (int) $request->query('months', 6);

        return response()->json([
            'data' => $this->remember(
                "central_insights_forecast_{$months}",
                $request,
                fn () => $insights->revenueForecast($months),
            ),
        ]);
    }

    public function upsell(Request $request, PlatformInsightsService $insights): JsonResponse
    {
        // The utilisation threshold is a judgement call, not a constant: a
        // packed catalogue wants 90%, a young one wants 70%.
        $threshold = max(0.5, min(1.0, (float) $request->query('threshold', 0.8)));

        return response()->json([
            'data' => $this->remember(
                'central_insights_upsell_'.round($threshold * 100),
                $request,
                fn () => $insights->upsell($threshold),
            ),
        ]);
    }

    /**
     * @param  \Closure(): array<string, mixed>  $callback
     * @return array<string, mixed>
     */
    private function remember(string $key, Request $request, \Closure $callback): array
    {
        if ($request->boolean('fresh')) {
            Cache::forget($key);
        }

        return Cache::remember($key, self::TTL, $callback);
    }
}
