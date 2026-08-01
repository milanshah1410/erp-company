<?php

declare(strict_types=1);

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\SupportTicket;
use App\Models\Tenant;
use App\Support\IndustryTemplates;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Platform Analytics (Volume 11 §"Analytics") — the four cuts the SaaS owner
 * runs the business on: Revenue, Industry, Plans and Server Usage. Read-only,
 * guarded by `analytics.view`.
 *
 * Deliberately separate from Billing → Revenue, which answers "what did we
 * collect and who owes us". This surface answers "where is the business coming
 * from and where is it going": cohort growth, vertical mix, plan economics,
 * infrastructure headroom.
 *
 * Two structural constraints shape every query here:
 *
 *  1. `tenants` sits on the default connection while `plans` / `subscriptions` /
 *     `subscription_invoices` sit on the central one — a split-DB deployment
 *     would break any join across that line. Everything below therefore pulls
 *     each side separately and grafts them together in PHP. Tenant counts are
 *     in the hundreds, so the cost is trivial and the code survives the split.
 *  2. The heavy cuts (industry, plans, server usage) are cached briefly. An
 *     operator refreshing a dashboard must not fan out a table scan per click.
 */
class AnalyticsController extends Controller
{
    /** Short cache on the aggregate cuts — long enough to absorb a refresh storm. */
    private const TTL = 300;

    /** Months of history every trend line covers. */
    private const WINDOW = 12;

    /** Invoice statuses that represent money that was actually collected. */
    private const PAID = ['paid', 'refunded'];

    /**
     * Revenue analytics: the growth picture — MRR/ARR, ARPU, the 12-month
     * gross/net trend, month-over-month movement and the tenants carrying the
     * book.
     */
    public function revenue(): JsonResponse
    {
        $data = Cache::remember('central_analytics_revenue', self::TTL, function (): array {
            $now = Carbon::now();
            $windowStart = $now->copy()->subMonthsNoOverflow(self::WINDOW - 1)->startOfMonth();

            $live = Subscription::query()
                ->whereIn('status', ['active', 'past_due'])
                ->with('plan')->get();

            $mrr = $live->sum(fn (Subscription $s) => $this->monthlyValue($s));
            $payingTenants = $live->pluck('tenant_id')->unique()->count();

            // Gross / refunds per month, in one pass over the paid invoices.
            $byMonth = SubscriptionInvoice::query()
                ->selectRaw("DATE_FORMAT(paid_at, '%Y-%m') as ym")
                ->selectRaw('SUM(amount) as gross')
                ->selectRaw('SUM(COALESCE(refund_amount, 0)) as refunds')
                ->selectRaw('COUNT(*) as invoices')
                ->whereIn('status', self::PAID)
                ->where('paid_at', '>=', $windowStart)
                ->groupBy('ym')->get()->keyBy('ym');

            // New workspaces and cancellations per month, for the same axis.
            $signupsByMonth = Tenant::query()
                ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') as ym, COUNT(*) as aggregate")
                ->where('created_at', '>=', $windowStart)
                ->groupBy('ym')->pluck('aggregate', 'ym');

            $churnByMonth = Subscription::query()
                ->selectRaw("DATE_FORMAT(cancelled_at, '%Y-%m') as ym, COUNT(*) as aggregate")
                ->whereNotNull('cancelled_at')
                ->where('cancelled_at', '>=', $windowStart)
                ->groupBy('ym')->pluck('aggregate', 'ym');

            $trend = [];
            for ($i = 0; $i < self::WINDOW; $i++) {
                $month = $windowStart->copy()->addMonthsNoOverflow($i);
                $key = $month->format('Y-m');
                $row = $byMonth[$key] ?? null;
                $gross = (float) ($row->gross ?? 0);
                $refunds = (float) ($row->refunds ?? 0);

                $trend[] = [
                    'month' => $key,
                    'label' => $month->format('M y'),
                    'gross' => round($gross, 2),
                    'refunds' => round($refunds, 2),
                    'net' => round($gross - $refunds, 2),
                    'invoices' => (int) ($row->invoices ?? 0),
                    'new_tenants' => (int) ($signupsByMonth[$key] ?? 0),
                    'cancellations' => (int) ($churnByMonth[$key] ?? 0),
                ];
            }

            $thisMonth = end($trend) ?: null;
            $lastMonth = count($trend) > 1 ? $trend[count($trend) - 2] : null;

            // Lifetime revenue per tenant, via subscription → tenant.
            $tenantOfSubscription = Subscription::query()->pluck('tenant_id', 'id');
            $perSubscription = SubscriptionInvoice::query()
                ->whereIn('status', self::PAID)
                ->selectRaw('subscription_id, SUM(amount - COALESCE(refund_amount, 0)) as net')
                ->groupBy('subscription_id')->pluck('net', 'subscription_id');

            $perTenant = [];
            foreach ($perSubscription as $subscriptionId => $net) {
                $tenantId = $tenantOfSubscription[$subscriptionId] ?? null;
                if ($tenantId) {
                    $perTenant[$tenantId] = ($perTenant[$tenantId] ?? 0) + (float) $net;
                }
            }
            arsort($perTenant);
            $topIds = array_slice(array_keys($perTenant), 0, 10);
            $topNames = Tenant::query()->whereIn('id', $topIds)->pluck('company_name', 'id');

            return [
                'currency' => 'INR',
                'mrr' => round($mrr, 2),
                'arr' => round($mrr * 12, 2),
                'arpu' => $payingTenants > 0 ? round($mrr / $payingTenants, 2) : 0.0,
                'paying_tenants' => $payingTenants,
                'lifetime_net' => round(array_sum($perTenant), 2),
                'this_month' => $thisMonth,
                'last_month' => $lastMonth,
                'growth_pct' => $lastMonth && $lastMonth['net'] > 0
                    ? round((($thisMonth['net'] - $lastMonth['net']) / $lastMonth['net']) * 100, 1)
                    : null,
                'monthly_trend' => $trend,
                'top_tenants' => collect($topIds)->map(fn ($id) => [
                    'tenant_id' => (int) $id,
                    'tenant_name' => $topNames[$id] ?? "Tenant #{$id}",
                    'net_revenue' => round($perTenant[$id], 2),
                ])->values(),
            ];
        });

        return response()->json(['data' => $data]);
    }

    /**
     * Industry analytics: which verticals the customer base actually sits in,
     * what each one is worth, and how well it retains. Tenants whose industry
     * was never recorded are reported as their own row rather than dropped —
     * a blank column is a signal about the signup funnel, not noise.
     */
    public function industry(): JsonResponse
    {
        $data = Cache::remember('central_analytics_industry', self::TTL, function (): array {
            $catalogue = IndustryTemplates::all();
            $tenants = Tenant::query()->get(['id', 'industry', 'status', 'created_at']);
            $subscriptions = $this->subscriptionsByTenant();

            $groups = $tenants->groupBy(fn (Tenant $t) => $t->industry ?: '__unset');

            $rows = $groups->map(function (Collection $group, string $key) use ($catalogue, $subscriptions) {
                $mrr = 0.0;
                $paying = 0;
                $trialing = 0;
                $churned = 0;

                foreach ($group as $tenant) {
                    $sub = $subscriptions[$tenant->id] ?? null;
                    if (! $sub) {
                        continue;
                    }
                    if (in_array($sub->status, ['active', 'past_due'], true)) {
                        $mrr += $this->monthlyValue($sub);
                        $paying++;
                    } elseif ($sub->status === 'trialing') {
                        $trialing++;
                    } elseif ($sub->status === 'cancelled') {
                        $churned++;
                    }
                }

                $total = $group->count();

                return [
                    'key' => $key === '__unset' ? null : $key,
                    'label' => $key === '__unset' ? 'Not recorded' : ($catalogue[$key]['label'] ?? ucfirst($key)),
                    'tenants' => $total,
                    'active' => $group->where('status', 'active')->count(),
                    'suspended' => $group->where('status', 'suspended')->count(),
                    'paying' => $paying,
                    'trialing' => $trialing,
                    'churned' => $churned,
                    'mrr' => round($mrr, 2),
                    'arpu' => $paying > 0 ? round($mrr / $paying, 2) : 0.0,
                    // Share of this vertical's tenants that ever cancelled.
                    'churn_pct' => $total > 0 ? round(($churned / $total) * 100, 1) : 0.0,
                ];
            })->values()->sortByDesc('mrr')->values();

            $totalMrr = $rows->sum('mrr');

            return [
                'currency' => 'INR',
                'total_tenants' => $tenants->count(),
                'total_mrr' => round($totalMrr, 2),
                // Verticals we sell but nobody has bought — the gap worth acting on.
                'unsold' => collect($catalogue)
                    ->filter(fn ($t) => $t['is_active'] && ! $groups->has($t['key']))
                    ->map(fn ($t) => ['key' => $t['key'], 'label' => $t['label']])
                    ->values(),
                'rows' => $rows->map(fn (array $r) => $r + [
                    'mrr_share_pct' => $totalMrr > 0 ? round(($r['mrr'] / $totalMrr) * 100, 1) : 0.0,
                ]),
            ];
        });

        return response()->json(['data' => $data]);
    }

    /**
     * Plan analytics: the economics of the catalogue — who sits where, what
     * each tier contributes, how well trials convert on it, and how long
     * subscribers last.
     */
    public function plans(): JsonResponse
    {
        $data = Cache::remember('central_analytics_plans', self::TTL, function (): array {
            $plans = Plan::query()->orderBy('sort_order')->orderBy('id')->get();
            $subscriptions = Subscription::query()->get(['id', 'plan_id', 'tenant_id', 'status', 'trial_ends_at', 'cancelled_at', 'created_at']);

            $netPerSubscription = SubscriptionInvoice::query()
                ->whereIn('status', self::PAID)
                ->selectRaw('subscription_id, SUM(amount - COALESCE(refund_amount, 0)) as net')
                ->groupBy('subscription_id')->pluck('net', 'subscription_id');

            $byPlan = $subscriptions->groupBy('plan_id');

            $rows = $plans->map(function (Plan $plan) use ($byPlan, $netPerSubscription): array {
                $group = $byPlan[$plan->id] ?? collect();
                $monthly = $plan->billing_cycle === 'yearly' ? (float) $plan->price / 12 : (float) $plan->price;

                $liveStatuses = ['active', 'past_due'];
                $live = $group->whereIn('status', $liveStatuses);
                $cancelled = $group->where('status', 'cancelled');

                // Conversion: of the subscriptions that ever left trial on this
                // plan, how many became paying rather than lapsing. Still-
                // trialing rows are excluded — their outcome isn't known yet.
                $everTrialed = $group->filter(fn ($s) => $s->trial_ends_at !== null);
                $decided = $everTrialed->where('status', '!=', 'trialing');
                $converted = $decided->whereIn('status', array_merge($liveStatuses, ['suspended']));

                // Tenure in days: cancellation date (or today) minus signup.
                $tenures = $group->map(function ($s): float {
                    $start = $s->created_at ? Carbon::parse($s->created_at) : null;
                    if (! $start) {
                        return 0.0;
                    }
                    $end = $s->cancelled_at ? Carbon::parse($s->cancelled_at) : Carbon::now();

                    return (float) $start->diffInDays($end);
                })->filter(fn ($d) => $d > 0);

                $lifetime = $group->sum(fn ($s) => (float) ($netPerSubscription[$s->id] ?? 0));

                return [
                    'plan_id' => $plan->id,
                    'slug' => $plan->slug,
                    'name' => $plan->name,
                    'price' => (float) $plan->price,
                    'billing_cycle' => $plan->billing_cycle,
                    'is_custom' => (bool) $plan->is_custom,
                    'subscriptions' => $group->count(),
                    'live' => $live->count(),
                    'trialing' => $group->where('status', 'trialing')->count(),
                    'cancelled' => $cancelled->count(),
                    'mrr' => round($live->count() * $monthly, 2),
                    'lifetime_net' => round($lifetime, 2),
                    'conversion_pct' => $decided->count() > 0
                        ? round(($converted->count() / $decided->count()) * 100, 1)
                        : null,
                    'churn_pct' => $group->count() > 0
                        ? round(($cancelled->count() / $group->count()) * 100, 1)
                        : 0.0,
                    'avg_tenure_days' => $tenures->count() > 0 ? (int) round($tenures->avg()) : 0,
                ];
            });

            $totalMrr = $rows->sum('mrr');
            $totalLive = $rows->sum('live');

            return [
                'currency' => 'INR',
                'total_mrr' => round($totalMrr, 2),
                'total_live' => $totalLive,
                // Subscriptions pointing at a plan that no longer exists in the
                // catalogue (a deleted tier). Surfaced so it can be cleaned up.
                'orphaned' => $subscriptions->whereNotIn('plan_id', $plans->pluck('id'))->count(),
                'rows' => $rows->map(fn (array $r) => $r + [
                    'mrr_share_pct' => $totalMrr > 0 ? round(($r['mrr'] / $totalMrr) * 100, 1) : 0.0,
                    'tenant_share_pct' => $totalLive > 0 ? round(($r['live'] / $totalLive) * 100, 1) : 0.0,
                ])->values(),
            ];
        });

        return response()->json(['data' => $data]);
    }

    /**
     * Server usage: what the platform is actually consuming, and which tenants
     * are consuming it. Row counts stand in for "footprint" — a shared-schema
     * multi-tenant DB has no per-tenant byte figure, and counting rows in the
     * three biggest tenant-stamped tables is a faithful proxy that costs three
     * grouped queries instead of a table scan per tenant.
     */
    public function serverUsage(): JsonResponse
    {
        $data = Cache::remember('central_analytics_server', self::TTL, function (): array {
            return [
                'database' => $this->databaseUsage(),
                'storage' => $this->storageUsage(),
                'queue' => [
                    'pending' => $this->tableCount('jobs'),
                    'failed' => $this->tableCount('failed_jobs'),
                    'driver' => config('queue.default'),
                ],
                'runtime' => [
                    'cache_driver' => config('cache.default'),
                    'search_driver' => config('scout.driver'),
                    'php_version' => PHP_VERSION,
                    'disk_free_bytes' => @disk_free_space(base_path()) ?: null,
                    'disk_total_bytes' => @disk_total_space(base_path()) ?: null,
                ],
                'tenants' => $this->tenantFootprint(),
                'activity_30d' => $this->activityTrend(),
                'measured_at' => Carbon::now()->toDateTimeString(),
            ];
        });

        return response()->json(['data' => $data]);
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
     * The latest subscription per tenant, plan eager-loaded. Keyed by tenant id.
     *
     * @return Collection<int, Subscription>
     */
    private function subscriptionsByTenant(): Collection
    {
        return Subscription::query()
            ->with('plan')
            ->orderBy('id')
            ->get()
            // Later rows overwrite earlier ones, so the newest subscription wins.
            ->keyBy('tenant_id');
    }

    /**
     * Physical size of the database plus its heaviest tables. MySQL-specific
     * (information_schema); other drivers report nulls rather than failing the
     * whole panel.
     *
     * @return array<string, mixed>
     */
    private function databaseUsage(): array
    {
        $connection = DB::connection();
        $name = $connection->getDatabaseName();

        if ($connection->getDriverName() !== 'mysql') {
            return ['name' => $name, 'driver' => $connection->getDriverName(), 'size_bytes' => null, 'tables' => []];
        }

        try {
            $tables = $connection->select(
                'SELECT table_name AS name, table_rows AS rows_est,
                        (data_length + index_length) AS size_bytes
                   FROM information_schema.tables
                  WHERE table_schema = ?
                  ORDER BY size_bytes DESC
                  LIMIT 15',
                [$name],
            );

            $total = $connection->selectOne(
                'SELECT SUM(data_length + index_length) AS size_bytes,
                        COUNT(*) AS table_count
                   FROM information_schema.tables
                  WHERE table_schema = ?',
                [$name],
            );

            return [
                'name' => $name,
                'driver' => 'mysql',
                'size_bytes' => (int) ($total->size_bytes ?? 0),
                'table_count' => (int) ($total->table_count ?? 0),
                'tables' => collect($tables)->map(fn ($t) => [
                    'name' => $t->name,
                    'rows_est' => (int) $t->rows_est,
                    'size_bytes' => (int) $t->size_bytes,
                ])->values(),
            ];
        } catch (\Throwable $e) {
            // information_schema can be denied on managed MySQL. Report the
            // gap instead of 500-ing the whole Analytics screen.
            return ['name' => $name, 'driver' => 'mysql', 'size_bytes' => null, 'tables' => [], 'error' => $e->getMessage()];
        }
    }

    /**
     * Bytes under each tenant's storage prefix. Walks the tenant disk once —
     * see App\Support\TenantStorage for the `tenant-{id}/` folder convention.
     *
     * @return array<string, mixed>
     */
    private function storageUsage(): array
    {
        $diskName = (string) config('filesystems.tenant_disk', 'local');

        try {
            $disk = Storage::disk($diskName);
            $byTenant = [];
            $total = 0;

            foreach ($disk->directories() as $dir) {
                if (! str_starts_with($dir, 'tenant-')) {
                    continue;
                }
                $bytes = collect($disk->allFiles($dir))->sum(fn ($f) => $disk->size($f));
                $byTenant[(int) substr($dir, 7)] = $bytes;
                $total += $bytes;
            }

            arsort($byTenant);

            return [
                'disk' => $diskName,
                'total_bytes' => $total,
                'tenant_folders' => count($byTenant),
                'top' => collect($byTenant)->take(10)
                    ->map(fn ($bytes, $id) => ['tenant_id' => $id, 'bytes' => $bytes])
                    ->values(),
            ];
        } catch (\Throwable $e) {
            // A remote disk that is down must not take the panel with it.
            return ['disk' => $diskName, 'total_bytes' => null, 'error' => $e->getMessage()];
        }
    }

    /**
     * Per-tenant data footprint: users, products and invoices, from three
     * grouped queries over the tenant-stamped tables.
     *
     * @return array<string, mixed>
     */
    private function tenantFootprint(): array
    {
        $counts = fn (string $table) => DB::table($table)
            ->whereNotNull('tenant_id')
            ->selectRaw('tenant_id, COUNT(*) as aggregate')
            ->groupBy('tenant_id')->pluck('aggregate', 'tenant_id');

        $users = $counts('users');
        $products = $counts('products');
        $invoices = $counts('invoices');

        $tickets = SupportTicket::query()
            ->selectRaw('tenant_id, COUNT(*) as aggregate')
            ->groupBy('tenant_id')->pluck('aggregate', 'tenant_id');

        $rows = Tenant::query()->orderBy('company_name')->get(['id', 'company_name', 'status'])
            ->map(fn (Tenant $t) => [
                'tenant_id' => $t->id,
                'tenant_name' => $t->company_name,
                'status' => $t->status,
                'users' => (int) ($users[$t->id] ?? 0),
                'products' => (int) ($products[$t->id] ?? 0),
                'invoices' => (int) ($invoices[$t->id] ?? 0),
                'tickets' => (int) ($tickets[$t->id] ?? 0),
            ])
            ->sortByDesc(fn (array $r) => $r['products'] + $r['invoices'])
            ->values();

        return [
            'rows' => $rows,
            'totals' => [
                'tenants' => $rows->count(),
                'users' => $rows->sum('users'),
                'products' => $rows->sum('products'),
                'invoices' => $rows->sum('invoices'),
            ],
        ];
    }

    /**
     * Daily platform activity for the last 30 days — the closest stand-in for
     * "API calls" this deployment records (every write is logged by
     * spatie/activitylog; there is no separate request meter).
     *
     * @return array<int, array<string, mixed>>
     */
    private function activityTrend(): array
    {
        $start = Carbon::now()->subDays(29)->startOfDay();

        $byDay = DB::table('activity_log')
            ->where('created_at', '>=', $start)
            ->selectRaw('DATE(created_at) as day, COUNT(*) as aggregate')
            ->groupBy('day')->pluck('aggregate', 'day');

        $out = [];
        for ($i = 0; $i < 30; $i++) {
            $day = $start->copy()->addDays($i)->toDateString();
            $out[] = ['day' => $day, 'events' => (int) ($byDay[$day] ?? 0)];
        }

        return $out;
    }

    /** COUNT(*) on a table that may not exist in every deployment. */
    private function tableCount(string $table): ?int
    {
        try {
            return DB::table($table)->count();
        } catch (\Throwable) {
            return null;
        }
    }
}
