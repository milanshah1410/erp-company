<?php

declare(strict_types=1);

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\Tenant;
use App\Support\UsageMeter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The platform dashboard (Volume 11 §"Dashboard") — the one screen an owner
 * opens first: how many workspaces exist and what state they are in, what the
 * book is worth, what landed this month, and what the platform is consuming.
 *
 * Deliberately a *composition* of numbers the deeper panels already own rather
 * than a new source of truth: MRR is computed the same way Billing → Revenue
 * computes it, usage totals come from UsageMeter exactly as Tenant Usage reads
 * them. If the dashboard ever disagrees with the panel it links to, that is a
 * bug in this file, not a second opinion.
 *
 * Two things shape the implementation:
 *
 *  1. `tenants` lives on the default connection while subscriptions/invoices
 *     live on the central one, so nothing here joins across that line — each
 *     side is pulled separately and grafted together in PHP (tenant counts are
 *     in the hundreds; the cost is noise).
 *  2. Money and usage are separately permissioned. An operator holding only
 *     `platform.dashboard.view` gets the tenant band and nothing else, and the
 *     response says which blocks were withheld so the panel can lay itself out
 *     honestly instead of rendering zeroes.
 */
class DashboardController extends Controller
{
    /**
     * Short cache per block. Long enough to absorb a refresh storm, short
     * enough that the Refresh button still means something; `?fresh=1` bypasses
     * it outright for an operator who needs the live figure mid-conversation.
     */
    private const TTL = 120;

    /** Months of history the revenue sparkline covers. */
    private const WINDOW = 12;

    /** Invoice statuses that represent money that was actually collected. */
    private const PAID = ['paid', 'refunded'];

    /** Subscription statuses that carry live revenue. */
    private const LIVE = ['active', 'past_due'];

    public function summary(Request $request): JsonResponse
    {
        $days = $this->window($request);
        $operator = $request->user();

        if ($request->boolean('fresh')) {
            $this->forget($days);
        }

        // Money is gated on the billing vocabulary rather than the dashboard's
        // own permission: a support operator can see the tenant base without
        // also seeing what the business is worth.
        $showRevenue = $operator->can('revenue.view');
        $showPayments = $operator->canAny(['payments.view', 'invoices.view', 'renewals.view']);
        $showUsage = $operator->can('usage.view');

        return response()->json([
            'data' => [
                'currency' => 'INR',
                'window_days' => $days,
                'generated_at' => Carbon::now()->toDateTimeString(),
                'tenants' => Cache::remember('central_dashboard_tenants', self::TTL, fn () => $this->tenants()),
                'revenue' => $showRevenue
                    ? Cache::remember('central_dashboard_revenue', self::TTL, fn () => $this->revenue())
                    : null,
                'payments' => $showPayments
                    ? Cache::remember('central_dashboard_payments', self::TTL, fn () => $this->payments())
                    : null,
                'usage' => $showUsage
                    ? Cache::remember("central_dashboard_usage_{$days}", self::TTL, fn () => $this->usage($days))
                    : null,
                'metrics' => $showUsage ? UsageMeter::METRICS : null,
                // What this operator is allowed to see, so the panel can say
                // "no access" instead of drawing an empty tile.
                'sections' => [
                    'revenue' => $showRevenue,
                    'payments' => $showPayments,
                    'usage' => $showUsage,
                ],
            ],
        ]);
    }

    // -----------------------------------------------------------------------
    // Blocks
    // -----------------------------------------------------------------------

    /**
     * The tenant base, split by lifecycle.
     *
     * active / trial / expired / no_subscription partition the whole base and
     * sum to `total` — that is the contract of this block, and it is why the
     * lifecycle is read off the subscription rather than off `tenants.status`.
     * `suspended` is an overlay on top of it, not a fifth bucket: an operator
     * lock and a lapsed subscription are independent states and a workspace can
     * be in both.
     *
     * @return array<string, mixed>
     */
    private function tenants(): array
    {
        $tenants = Tenant::query()->get(['id', 'status', 'created_at']);
        $subscriptions = $this->subscriptionsByTenant();

        $buckets = ['active' => 0, 'trial' => 0, 'expired' => 0, 'no_subscription' => 0];
        $pastDue = 0;

        foreach ($tenants as $tenant) {
            $subscription = $subscriptions[$tenant->id] ?? null;

            if (! $subscription) {
                $buckets['no_subscription']++;
                continue;
            }

            if ($subscription->hasExpired()) {
                $buckets['expired']++;
            } elseif ($subscription->status === 'trialing') {
                $buckets['trial']++;
            } else {
                $buckets['active']++;
                $pastDue += $subscription->status === 'past_due' ? 1 : 0;
            }
        }

        $now = Carbon::now();
        $thisMonth = $tenants->filter(fn (Tenant $t) => $t->created_at?->greaterThanOrEqualTo($now->copy()->startOfMonth()))->count();
        $lastMonthStart = $now->copy()->subMonthNoOverflow()->startOfMonth();
        $lastMonth = $tenants->filter(fn (Tenant $t) => $t->created_at
            && $t->created_at->betweenIncluded($lastMonthStart, $lastMonthStart->copy()->endOfMonth()))->count();

        return $buckets + [
            'total' => $tenants->count(),
            'past_due' => $pastDue,
            'suspended' => $tenants->where('status', 'suspended')->count(),
            'new_this_month' => $thisMonth,
            'new_last_month' => $lastMonth,
            'signup_growth_pct' => $lastMonth > 0
                ? round((($thisMonth - $lastMonth) / $lastMonth) * 100, 1)
                : null,
            'monthly_movement' => $this->movement(),
        ];
    }

    /**
     * Signups against cancellations, month by month — the movement behind every
     * other figure on the screen. Two grouped queries on opposite sides of the
     * connection line, zipped onto one axis in PHP.
     *
     * @return list<array<string, mixed>>
     */
    private function movement(): array
    {
        $windowStart = Carbon::now()->subMonthsNoOverflow(self::WINDOW - 1)->startOfMonth();

        $signups = Tenant::query()
            ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') as ym, COUNT(*) as aggregate")
            ->where('created_at', '>=', $windowStart)
            ->groupBy('ym')->pluck('aggregate', 'ym');

        $cancellations = Subscription::query()
            ->selectRaw("DATE_FORMAT(cancelled_at, '%Y-%m') as ym, COUNT(*) as aggregate")
            ->whereNotNull('cancelled_at')
            ->where('cancelled_at', '>=', $windowStart)
            ->groupBy('ym')->pluck('aggregate', 'ym');

        $out = [];
        for ($i = 0; $i < self::WINDOW; $i++) {
            $month = $windowStart->copy()->addMonthsNoOverflow($i);
            $key = $month->format('Y-m');

            $out[] = [
                'month' => $key,
                'label' => $month->format('M y'),
                'new_tenants' => (int) ($signups[$key] ?? 0),
                'cancellations' => (int) ($cancellations[$key] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * What the book is worth and what it has collected. MRR is the monthly-
     * equivalent price of every live subscription (contracted), revenue is what
     * invoices actually settled (collected) — the two are different questions
     * and the dashboard shows both rather than picking one.
     *
     * @return array<string, mixed>
     */
    private function revenue(): array
    {
        $live = Subscription::query()->whereIn('status', self::LIVE)->with('plan')->get();

        $mrr = $live->sum(fn (Subscription $s) => $this->monthlyValue($s));
        $payingTenants = $live->pluck('tenant_id')->unique()->count();

        $now = Carbon::now();
        $windowStart = $now->copy()->subMonthsNoOverflow(self::WINDOW - 1)->startOfMonth();

        $byMonth = SubscriptionInvoice::query()
            ->selectRaw("DATE_FORMAT(paid_at, '%Y-%m') as ym")
            ->selectRaw('SUM(amount) as gross')
            ->selectRaw('SUM(COALESCE(refund_amount, 0)) as refunds')
            ->whereIn('status', self::PAID)
            ->where('paid_at', '>=', $windowStart)
            ->groupBy('ym')->get()->keyBy('ym');

        $trend = [];
        for ($i = 0; $i < self::WINDOW; $i++) {
            $month = $windowStart->copy()->addMonthsNoOverflow($i);
            $row = $byMonth[$month->format('Y-m')] ?? null;
            $gross = (float) ($row->gross ?? 0);
            $refunds = (float) ($row->refunds ?? 0);

            $trend[] = [
                'month' => $month->format('Y-m'),
                'label' => $month->format('M y'),
                'gross' => round($gross, 2),
                'net' => round($gross - $refunds, 2),
            ];
        }

        $thisMonth = end($trend) ?: null;
        $lastMonth = count($trend) > 1 ? $trend[count($trend) - 2] : null;

        $lifetimeGross = (float) SubscriptionInvoice::query()->whereIn('status', self::PAID)->sum('amount');
        $lifetimeRefunds = (float) SubscriptionInvoice::query()->sum('refund_amount');

        return [
            'mrr' => round($mrr, 2),
            'arr' => round($mrr * 12, 2),
            'arpu' => $payingTenants > 0 ? round($mrr / $payingTenants, 2) : 0.0,
            'paying_tenants' => $payingTenants,
            'this_month_net' => $thisMonth['net'] ?? 0.0,
            'last_month_net' => $lastMonth['net'] ?? 0.0,
            'growth_pct' => $lastMonth && $lastMonth['net'] > 0
                ? round((($thisMonth['net'] - $lastMonth['net']) / $lastMonth['net']) * 100, 1)
                : null,
            'lifetime_gross' => round($lifetimeGross, 2),
            'lifetime_refunds' => round($lifetimeRefunds, 2),
            'lifetime_net' => round($lifetimeGross - $lifetimeRefunds, 2),
            'monthly_trend' => $trend,
        ];
    }

    /**
     * The collection picture: what settled this month, what is still owed, and
     * the last few payments to land. Outstanding/overdue come off the same
     * scopes the dunning sweep uses, so this tile and Billing → Renewals can
     * never quote different arrears.
     *
     * @return array<string, mixed>
     */
    private function payments(): array
    {
        $now = Carbon::now();
        $monthStart = $now->copy()->startOfMonth();
        $monthEnd = $now->copy()->endOfMonth();

        $settled = SubscriptionInvoice::query()
            ->whereIn('status', self::PAID)
            ->whereBetween('paid_at', [$monthStart, $monthEnd]);

        $outstanding = SubscriptionInvoice::query()->outstanding();
        $overdue = SubscriptionInvoice::query()->overdue();

        $recent = SubscriptionInvoice::query()
            ->whereIn('status', self::PAID)
            ->whereNotNull('paid_at')
            ->orderByDesc('paid_at')->limit(8)
            ->get(['id', 'number', 'subscription_id', 'amount', 'status', 'payment_method', 'paid_at']);

        $tenantNames = $this->tenantNamesFor($recent->pluck('subscription_id'));

        return [
            'collected_this_month' => round((float) $settled->clone()->sum('amount'), 2),
            'count_this_month' => $settled->clone()->count(),
            'refunded_this_month' => round((float) SubscriptionInvoice::query()
                ->whereBetween('refunded_at', [$monthStart, $monthEnd])
                ->sum('refund_amount'), 2),
            'outstanding_amount' => round((float) $outstanding->clone()->sum('amount'), 2),
            'outstanding_count' => $outstanding->clone()->count(),
            'overdue_amount' => round((float) $overdue->clone()->sum('amount'), 2),
            'overdue_count' => $overdue->clone()->count(),
            'recent' => $recent->map(fn (SubscriptionInvoice $invoice) => [
                'id' => $invoice->id,
                'number' => $invoice->displayNumber(),
                'tenant_name' => $tenantNames[$invoice->subscription_id] ?? '—',
                'amount' => (float) $invoice->amount,
                'status' => $invoice->status,
                'method' => $invoice->payment_method,
                'paid_at' => $invoice->paid_at?->toDateTimeString(),
            ])->values(),
        ];
    }

    /**
     * Platform-wide API / storage / AI consumption. Reuses UsageMeter so the
     * tiles here and /platform/usage are the same reading, buffered counters
     * included.
     *
     * @return array<string, mixed>
     */
    private function usage(int $days): array
    {
        // Buffered counters land in the table first, so the tiles and the daily
        // series below are the same reading rather than the chart trailing the
        // tile by a flush interval.
        UsageMeter::flush();

        return UsageMeter::platformTotals($days) + ['series' => $this->usageSeries($days)];
    }

    /**
     * Daily platform-wide counter totals over the window. One grouped query
     * across every tenant; missing days are zero, because a counter that was
     * never hit genuinely is zero (unlike a snapshot, which would be a gap).
     *
     * @return list<array<string, mixed>>
     */
    private function usageSeries(int $days): array
    {
        $counters = UsageMeter::counters();
        $since = Carbon::today()->subDays(max(0, $days - 1));

        $rows = DB::table('tenant_usage_daily')
            ->whereIn('metric', $counters)
            ->where('date', '>=', $since->toDateString())
            ->selectRaw('date, metric, SUM(value) as aggregate')
            ->groupBy('date', 'metric')->get();

        $byDate = [];
        foreach ($rows as $row) {
            $byDate[(string) $row->date][$row->metric] = (int) $row->aggregate;
        }

        $out = [];
        for ($day = $since->copy(); $day->lte(Carbon::today()); $day->addDay()) {
            $date = $day->toDateString();
            $point = ['date' => $date, 'label' => $day->format('d M')];

            foreach ($counters as $metric) {
                $point[$metric] = (int) ($byDate[$date][$metric] ?? 0);
            }

            $out[] = $point;
        }

        return $out;
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /** A subscription's monthly-equivalent price (yearly plans divided out). */
    private function monthlyValue(Subscription $subscription): float
    {
        $price = (float) ($subscription->plan?->price ?? 0);

        return $subscription->plan?->billing_cycle === 'yearly' ? $price / 12 : $price;
    }

    /**
     * The newest subscription per tenant, keyed by tenant id.
     *
     * @return Collection<int, Subscription>
     */
    private function subscriptionsByTenant(): Collection
    {
        return Subscription::query()
            ->orderBy('id')
            ->get(['id', 'tenant_id', 'status', 'trial_ends_at', 'current_period_end'])
            // Later rows overwrite earlier ones, so the newest subscription wins.
            ->keyBy('tenant_id');
    }

    /**
     * subscription id => tenant company name, resolved in two queries across
     * the central/default connection line rather than one join over it.
     *
     * @param  Collection<int, int>  $subscriptionIds
     * @return array<int, string>
     */
    private function tenantNamesFor(Collection $subscriptionIds): array
    {
        if ($subscriptionIds->isEmpty()) {
            return [];
        }

        $tenantOfSubscription = Subscription::query()
            ->whereIn('id', $subscriptionIds->all())
            ->pluck('tenant_id', 'id');

        $names = Tenant::query()
            ->whereIn('id', $tenantOfSubscription->values()->unique()->all())
            ->pluck('company_name', 'id');

        $out = [];
        foreach ($tenantOfSubscription as $subscriptionId => $tenantId) {
            $out[(int) $subscriptionId] = $names[$tenantId] ?? "Tenant #{$tenantId}";
        }

        return $out;
    }

    private function window(Request $request): int
    {
        $days = (int) $request->query('days', '30');

        return max(1, min(365, $days ?: 30));
    }

    private function forget(int $days): void
    {
        foreach (['tenants', 'revenue', 'payments', "usage_{$days}"] as $block) {
            Cache::forget("central_dashboard_{$block}");
        }
    }
}
