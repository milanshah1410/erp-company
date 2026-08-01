<?php

declare(strict_types=1);

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Support\TenantLimits;
use App\Support\UsageMeter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What every workspace is consuming (Volume 11 §"Tenant Usage").
 *
 * Five metrics, deliberately mixed: two the platform pays for directly (API
 * calls, storage), two that are the plan's unit of sale (products, customers),
 * and AI usage, which is both. Together they answer the only two questions an
 * owner asks of this screen — who is about to outgrow their plan, and who is
 * costing more than they pay.
 *
 * Counters are flushed before every read. The five-minute scheduled flush writes
 * history; this makes sure an operator is never shown a number that lags a
 * customer conversation.
 */
class TenantUsageController extends Controller
{
    /** Default window for counter metrics, in days. */
    private const WINDOW = 30;

    public function index(Request $request): JsonResponse
    {
        $days = $this->window($request);
        UsageMeter::flush();

        $usage = UsageMeter::forAllTenants($days);

        $tenants = Tenant::query()
            ->with('subscription.plan')
            ->orderBy('company_name')
            ->get();

        $rows = $tenants->map(function (Tenant $tenant) use ($usage) {
            $metrics = $usage[$tenant->id] ?? array_fill_keys(array_keys(UsageMeter::METRICS), 0);
            $limits = TenantLimits::effective($tenant->id);

            return [
                'tenant_id' => $tenant->id,
                'company_name' => $tenant->company_name,
                'subdomain' => $tenant->subdomain,
                'status' => $tenant->status,
                'plan' => $tenant->subscription?->plan?->name,
                'plan_slug' => $tenant->subscription?->plan?->slug,
                'usage' => $metrics,
                // Only the two metrics a plan actually caps carry a limit; the
                // rest are reported, not enforced, and the panel must not imply
                // otherwise by rendering an empty progress bar against them.
                'limits' => [
                    UsageMeter::PRODUCTS => $this->numericLimit($limits, 'max_products'),
                    UsageMeter::CUSTOMERS => $this->numericLimit($limits, 'max_customers'),
                ],
            ];
        })->values();

        return response()->json([
            'data' => $rows,
            'metrics' => UsageMeter::METRICS,
            'totals' => UsageMeter::platformTotals($days),
            'window_days' => $days,
        ]);
    }

    /**
     * One workspace in detail: where it stands now, how it got there, and how
     * close it is to the caps it is actually sold.
     */
    public function show(Request $request, Tenant $tenant): JsonResponse
    {
        $days = $this->window($request);
        UsageMeter::flush();

        return response()->json([
            'data' => [
                'tenant' => [
                    'id' => $tenant->id,
                    'company_name' => $tenant->company_name,
                    'subdomain' => $tenant->subdomain,
                    'status' => $tenant->status,
                ],
                'current' => UsageMeter::current($tenant->id),
                'series' => UsageMeter::series($tenant->id, $days),
                // Reuses the Limits dialog's payload rather than recomputing the
                // plan/override merge, so the two screens can never quote a
                // different cap at the same customer.
                'limits' => TenantLimits::summary($tenant->id),
            ],
            'metrics' => UsageMeter::METRICS,
            'window_days' => $days,
        ]);
    }

    /**
     * Recompute this tenant's storage instead of serving the hourly cached walk.
     * Cheap for one tenant, unacceptable for a list — hence its own endpoint.
     */
    public function refreshStorage(Tenant $tenant): JsonResponse
    {
        return response()->json([
            'data' => ['storage_bytes' => UsageMeter::storageBytes($tenant->id, fresh: true)],
        ]);
    }

    /** Platform-wide totals — the Dashboard's API/Storage/AI usage tiles. */
    public function summary(Request $request): JsonResponse
    {
        $days = $this->window($request);
        UsageMeter::flush();

        return response()->json([
            'data' => UsageMeter::platformTotals($days),
            'metrics' => UsageMeter::METRICS,
            'window_days' => $days,
        ]);
    }

    private function window(Request $request): int
    {
        $days = (int) $request->query('days', (string) self::WINDOW);

        return max(1, min(365, $days ?: self::WINDOW));
    }

    private function numericLimit(array $limits, string $key): ?int
    {
        $value = $limits[$key] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }
}
