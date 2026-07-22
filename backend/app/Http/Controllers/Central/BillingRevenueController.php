<?php

declare(strict_types=1);

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

/**
 * Revenue analytics for the platform panel's billing overview. Read-only,
 * guarded by `revenue.view`. All amounts are plan-currency sums (single-
 * currency deployment, INR by default) computed off subscription_invoices —
 * gross = everything that was paid (including later-refunded invoices),
 * net = gross minus recorded refunds.
 */
class BillingRevenueController extends Controller
{
    public function summary(): JsonResponse
    {
        $now = Carbon::now();

        // ---- Lifetime totals -------------------------------------------------
        $paidStatuses = ['paid', 'refunded']; // refunded invoices were paid first
        $gross = (float) SubscriptionInvoice::whereIn('status', $paidStatuses)->sum('amount');
        $refunds = (float) SubscriptionInvoice::where('status', 'refunded')->sum('refund_amount');

        // ---- MRR: monthly-equivalent price of every live paid subscription ---
        $mrr = Subscription::query()
            ->whereIn('status', ['active', 'past_due'])
            ->with('plan')
            ->get()
            ->sum(function (Subscription $s): float {
                $price = (float) ($s->plan?->price ?? 0);

                return $s->plan?->billing_cycle === 'yearly' ? $price / 12 : $price;
            });

        // ---- This month vs last month (by payment date) ----------------------
        $monthRevenue = fn (Carbon $start, Carbon $end): float => (float) SubscriptionInvoice::query()
            ->whereIn('status', $paidStatuses)
            ->whereBetween('paid_at', [$start, $end])
            ->sum('amount');

        $thisMonth = $monthRevenue($now->copy()->startOfMonth(), $now->copy()->endOfMonth());
        $lastMonth = $monthRevenue(
            $now->copy()->subMonthNoOverflow()->startOfMonth(),
            $now->copy()->subMonthNoOverflow()->endOfMonth(),
        );

        // ---- 12-month trend ---------------------------------------------------
        $windowStart = $now->copy()->subMonthsNoOverflow(11)->startOfMonth();
        $byMonth = SubscriptionInvoice::query()
            ->selectRaw("DATE_FORMAT(paid_at, '%Y-%m') as ym, SUM(amount) as total")
            ->whereIn('status', $paidStatuses)
            ->where('paid_at', '>=', $windowStart)
            ->groupBy('ym')
            ->pluck('total', 'ym');

        $trend = [];
        for ($i = 0; $i < 12; $i++) {
            $month = $windowStart->copy()->addMonthsNoOverflow($i);
            $trend[] = [
                'month' => $month->format('Y-m'),
                'label' => $month->format('M y'),
                'revenue' => (float) ($byMonth[$month->format('Y-m')] ?? 0),
            ];
        }

        // ---- Subscription base ------------------------------------------------
        $statusCounts = Subscription::query()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $planBreakdown = Plan::query()
            ->withCount(['subscriptions as live_count' => fn ($q) => $q->whereIn('status', ['trialing', 'active', 'past_due'])])
            ->orderBy('sort_order')
            ->get()
            ->map(fn (Plan $p) => [
                'plan' => $p->name,
                'price' => (float) $p->price,
                'billing_cycle' => $p->billing_cycle,
                'live_subscriptions' => (int) $p->live_count,
            ])
            ->values();

        return response()->json([
            'data' => [
                'mrr' => round($mrr, 2),
                'gross_revenue' => round($gross, 2),
                'refunds_total' => round($refunds, 2),
                'net_revenue' => round($gross - $refunds, 2),
                'revenue_this_month' => round($thisMonth, 2),
                'revenue_last_month' => round($lastMonth, 2),
                'currency' => 'INR',
                'subscriptions_by_status' => [
                    'trialing' => (int) ($statusCounts['trialing'] ?? 0),
                    'active' => (int) ($statusCounts['active'] ?? 0),
                    'past_due' => (int) ($statusCounts['past_due'] ?? 0),
                    'suspended' => (int) ($statusCounts['suspended'] ?? 0),
                    'cancelled' => (int) ($statusCounts['cancelled'] ?? 0),
                ],
                'monthly_trend' => $trend,
                'plan_breakdown' => $planBreakdown,
            ],
        ]);
    }
}
