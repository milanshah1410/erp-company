<?php

declare(strict_types=1);

namespace App\Services\Platform;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\SupportTicket;
use App\Models\Tenant;
use App\Services\Ai\Support\TimeSeries;
use App\Support\TenantLimits;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * AI Insights for the SaaS-owner panel (Volume 11 §"AI Insights"): churn
 * prediction, revenue forecast and upsell recommendations.
 *
 * Built the same offline-first way as the tenant-side AI module
 * (App\Services\Ai): everything here is deterministic statistics over data the
 * platform already has — no model call, no key, no network. A hosted model
 * could later narrate these numbers, but it must never be what produces them:
 * an operator suspending an account or pricing a renewal needs a reason they
 * can audit, not a sentence they have to trust.
 *
 * Signal weights live in one table (`WEIGHTS`) so the scoring stays legible and
 * tunable rather than being scattered through the conditionals that apply it.
 */
class PlatformInsightsService
{
    /**
     * Churn signals and what each contributes to the 0–100 risk score.
     * Chosen so that any single "money is broken" signal lands a tenant in the
     * high band on its own, while engagement signals have to stack.
     */
    private const WEIGHTS = [
        'suspended' => 45,
        'past_due' => 35,
        'trial_expired' => 35,
        'cancel_scheduled' => 30,
        'failed_payments' => 20,
        'no_activity' => 25,   // scaled by how stale
        'activity_decline' => 15,
        'no_users' => 15,
        'urgent_ticket' => 10,
        'ticket_pressure' => 8,
        'poor_csat' => 12,
        'renewal_imminent' => 8,
    ];

    /** Days of silence at which the inactivity signal reaches full weight. */
    private const STALE_CEILING_DAYS = 45;

    /** Months of history the revenue forecast fits against. */
    private const HISTORY_MONTHS = 12;

    /**
     * Per-tenant churn risk, worst first.
     *
     * Already-cancelled subscriptions are reported separately as `churned`
     * rather than scored — they are the outcome the model is trying to predict,
     * and leaving them in the ranking would fill the top of the list with
     * customers who are already gone.
     *
     * @return array<string, mixed>
     */
    public function churn(): array
    {
        $now = Carbon::now();
        $tenants = Tenant::query()->get(['id', 'company_name', 'email', 'industry', 'status', 'created_at']);
        $subscriptions = Subscription::query()->with('plan')->orderBy('id')->get()->keyBy('tenant_id');

        $signals = $this->churnSignals($now);

        $rows = [];
        $churned = 0;

        foreach ($tenants as $tenant) {
            $sub = $subscriptions[$tenant->id] ?? null;

            if ($sub?->status === 'cancelled') {
                $churned++;

                continue;
            }

            [$score, $reasons] = $this->scoreTenant($tenant, $sub, $signals, $now);
            $mrr = $sub && in_array($sub->status, ['active', 'past_due'], true)
                ? $this->monthlyValue($sub)
                : 0.0;

            $rows[] = [
                'tenant_id' => $tenant->id,
                'tenant_name' => $tenant->company_name,
                'email' => $tenant->email,
                'plan' => $sub?->plan?->name,
                'subscription_status' => $sub?->status,
                'mrr_at_risk' => round($mrr, 2),
                'risk_score' => $score,
                'risk_band' => $this->band($score),
                'reasons' => $reasons,
                'recommended_action' => $this->recommendation($score, $reasons),
                'last_activity_at' => $signals['last_activity'][$tenant->id] ?? null,
            ];
        }

        usort($rows, fn ($a, $b) => $b['risk_score'] <=> $a['risk_score']);

        $collection = collect($rows);

        return [
            'currency' => 'INR',
            'evaluated' => $collection->count(),
            'already_churned' => $churned,
            'bands' => [
                'critical' => $collection->where('risk_band', 'critical')->count(),
                'high' => $collection->where('risk_band', 'high')->count(),
                'medium' => $collection->where('risk_band', 'medium')->count(),
                'low' => $collection->where('risk_band', 'low')->count(),
            ],
            // What walks out of the door if every high-risk tenant leaves.
            'mrr_at_risk' => round(
                $collection->whereIn('risk_band', ['critical', 'high'])->sum('mrr_at_risk'),
                2,
            ),
            'rows' => $collection->values()->all(),
        ];
    }

    /**
     * Platform revenue forecast: an OLS trend over the last 12 months of
     * collected (net) revenue, projected forward, floored at zero and sanity-
     * checked against contracted MRR.
     *
     * The MRR baseline matters — a straight trend line off three months of a
     * young SaaS extrapolates to nonsense, while the sum of live subscriptions
     * is money already committed. The forecast blends toward it as confidence
     * in the fitted trend drops.
     *
     * @return array<string, mixed>
     */
    public function revenueForecast(int $months = 6): array
    {
        $months = max(1, min($months, 12));
        $now = Carbon::now();
        $start = $now->copy()->subMonthsNoOverflow(self::HISTORY_MONTHS - 1)->startOfMonth();

        $byMonth = SubscriptionInvoice::query()
            ->selectRaw("DATE_FORMAT(paid_at, '%Y-%m') as ym, SUM(amount - COALESCE(refund_amount, 0)) as net")
            ->whereIn('status', ['paid', 'refunded'])
            ->where('paid_at', '>=', $start)
            ->groupBy('ym')->pluck('net', 'ym');

        $history = [];
        $series = [];
        for ($i = 0; $i < self::HISTORY_MONTHS; $i++) {
            $month = $start->copy()->addMonthsNoOverflow($i);
            // The current month is still accruing; including it as a completed
            // data point would bend the trend down every single time.
            $partial = $month->isSameMonth($now);
            $value = (float) ($byMonth[$month->format('Y-m')] ?? 0);

            $history[] = [
                'month' => $month->format('Y-m'),
                'label' => $month->format('M y'),
                'net' => round($value, 2),
                'partial' => $partial,
            ];
            if (! $partial) {
                $series[] = $value;
            }
        }

        $mrr = Subscription::query()
            ->whereIn('status', ['active', 'past_due'])
            ->with('plan')->get()
            ->sum(fn (Subscription $s) => $this->monthlyValue($s));

        $fit = TimeSeries::linearFit($series);
        $confidence = $series === [] ? 0.25 : TimeSeries::confidence($series);
        $deviation = TimeSeries::stdDev($series);
        $n = count($series);

        $projection = [];
        for ($i = 1; $i <= $months; $i++) {
            $month = $now->copy()->addMonthsNoOverflow($i)->startOfMonth();
            $trend = $fit['intercept'] + $fit['slope'] * ($n - 1 + $i);

            // Blend trend with contracted MRR by confidence: a well-behaved
            // history is trusted, a noisy one leans on what is under contract.
            $value = max(0.0, ($confidence * $trend) + ((1 - $confidence) * $mrr));
            $band = $deviation * (1 + ($i - 1) * 0.25); // uncertainty widens out

            $projection[] = [
                'month' => $month->format('Y-m'),
                'label' => $month->format('M y'),
                'forecast' => round($value, 2),
                'low' => round(max(0.0, $value - $band), 2),
                'high' => round($value + $band, 2),
            ];
        }

        $recent = $n > 0 ? array_slice($series, -3) : [];

        return [
            'currency' => 'INR',
            'mrr' => round($mrr, 2),
            'arr' => round($mrr * 12, 2),
            'confidence' => $confidence,
            'trend_per_month' => round($fit['slope'], 2),
            'direction' => $fit['slope'] > 0 ? 'growing' : ($fit['slope'] < 0 ? 'declining' : 'flat'),
            'avg_recent' => $recent ? round(array_sum($recent) / count($recent), 2) : 0.0,
            'months_of_history' => $n,
            'history' => $history,
            'projection' => $projection,
            'forecast_total' => round(array_sum(array_column($projection, 'forecast')), 2),
            'notes' => $this->forecastNotes($n, $confidence, $fit['slope']),
        ];
    }

    /**
     * Upsell candidates: tenants whose usage is pressing against the plan they
     * are on, matched to the cheapest plan in the catalogue that would actually
     * clear the pressure.
     *
     * Usage is read with three grouped queries rather than TenantLimits::summary
     * per tenant — that helper runs a COUNT per metered resource, which is fine
     * for one tenant's dialog and quadratic here.
     *
     * @return array<string, mixed>
     */
    public function upsell(float $threshold = 0.8): array
    {
        $plans = Plan::query()->orderBy('sort_order')->orderBy('price')->get();
        $tenants = Tenant::query()->get(['id', 'company_name', 'email', 'status', 'limit_overrides']);
        $subscriptions = Subscription::query()->with('plan')->orderBy('id')->get()->keyBy('tenant_id');

        $usage = [];
        foreach (TenantLimits::METERED as $resource => [$model, $limitKey]) {
            $usage[$resource] = DB::table((new $model)->getTable())
                ->whereNotNull('tenant_id')
                ->selectRaw('tenant_id, COUNT(*) as aggregate')
                ->groupBy('tenant_id')->pluck('aggregate', 'tenant_id');
        }

        $rows = [];
        foreach ($tenants as $tenant) {
            $sub = $subscriptions[$tenant->id] ?? null;
            $plan = $sub?->plan;

            // Only live, paying workspaces are upsell candidates. Trials belong
            // to conversion, cancelled accounts to win-back — different motions.
            if (! $plan || ! in_array($sub->status, ['active', 'past_due'], true)) {
                continue;
            }

            $limits = TenantLimits::effective($tenant->id, $plan, $tenant);
            $pressure = [];
            $worst = 0.0;

            foreach (TenantLimits::METERED as $resource => [, $limitKey, $label]) {
                $limit = $limits[$limitKey] ?? null;
                if (! is_numeric($limit) || (int) $limit <= 0) {
                    continue; // unlimited (or comped open) — nothing to press against
                }
                $used = (int) ($usage[$resource][$tenant->id] ?? 0);
                $ratio = $used / (int) $limit;
                if ($ratio >= $threshold) {
                    $pressure[] = [
                        'resource' => $resource,
                        'label' => $label,
                        'used' => $used,
                        'limit' => (int) $limit,
                        'pct' => round($ratio * 100, 1),
                        'exceeded' => $used > (int) $limit,
                    ];
                }
                $worst = max($worst, $ratio);
            }

            if ($pressure === []) {
                continue;
            }

            $target = $this->nextPlanFor($plans, $plan, $pressure);
            $currentMonthly = $this->monthlyValue($sub);
            $targetMonthly = $target
                ? ($target->billing_cycle === 'yearly' ? (float) $target->price / 12 : (float) $target->price)
                : null;

            $rows[] = [
                'tenant_id' => $tenant->id,
                'tenant_name' => $tenant->company_name,
                'email' => $tenant->email,
                'current_plan' => $plan->name,
                'current_mrr' => round($currentMonthly, 2),
                'pressure' => $pressure,
                'max_utilisation_pct' => round($worst * 100, 1),
                'recommended_plan' => $target?->name,
                'recommended_plan_id' => $target?->id,
                'uplift_mrr' => $targetMonthly !== null ? round(max(0, $targetMonthly - $currentMonthly), 2) : null,
                // No higher tier clears it — the honest answer is a bespoke
                // quote or a limit override, not a plan we do not sell.
                'action' => $target
                    ? "Offer {$target->name}"
                    : 'No standard tier clears this — quote a custom plan or lift the cap',
            ];
        }

        usort($rows, fn ($a, $b) => ($b['uplift_mrr'] ?? 0) <=> ($a['uplift_mrr'] ?? 0));

        return [
            'currency' => 'INR',
            'threshold_pct' => round($threshold * 100),
            'candidates' => count($rows),
            'potential_uplift_mrr' => round(array_sum(array_map(fn ($r) => $r['uplift_mrr'] ?? 0, $rows)), 2),
            'rows' => $rows,
        ];
    }

    // -----------------------------------------------------------------------
    // Churn internals
    // -----------------------------------------------------------------------

    /**
     * Every cross-tenant churn signal, one grouped query each.
     *
     * @return array<string, mixed>
     */
    private function churnSignals(Carbon $now): array
    {
        return [
            'users' => DB::table('users')->whereNotNull('tenant_id')
                ->selectRaw('tenant_id, COUNT(*) as aggregate')
                ->groupBy('tenant_id')->pluck('aggregate', 'tenant_id'),

            'last_activity' => DB::table('activity_log')->whereNotNull('tenant_id')
                ->selectRaw('tenant_id, MAX(created_at) as latest')
                ->groupBy('tenant_id')->pluck('latest', 'tenant_id'),

            // Two 30-day windows, so a fading workspace shows up before it
            // goes fully silent.
            'activity_recent' => DB::table('activity_log')->whereNotNull('tenant_id')
                ->where('created_at', '>=', $now->copy()->subDays(30))
                ->selectRaw('tenant_id, COUNT(*) as aggregate')
                ->groupBy('tenant_id')->pluck('aggregate', 'tenant_id'),

            'activity_prior' => DB::table('activity_log')->whereNotNull('tenant_id')
                ->whereBetween('created_at', [$now->copy()->subDays(60), $now->copy()->subDays(30)])
                ->selectRaw('tenant_id, COUNT(*) as aggregate')
                ->groupBy('tenant_id')->pluck('aggregate', 'tenant_id'),

            'failed_invoices' => SubscriptionInvoice::query()
                ->where('subscription_invoices.status', 'failed')
                ->where('subscription_invoices.created_at', '>=', $now->copy()->subDays(60))
                ->join('subscriptions', 'subscriptions.id', '=', 'subscription_invoices.subscription_id')
                ->selectRaw('subscriptions.tenant_id, COUNT(*) as aggregate')
                ->groupBy('subscriptions.tenant_id')->pluck('aggregate', 'tenant_id'),

            'tickets' => SupportTicket::query()
                ->whereIn('status', ['open', 'pending'])
                ->selectRaw("tenant_id, COUNT(*) as aggregate, MAX(priority = 'urgent') as has_urgent")
                ->groupBy('tenant_id')->get()->keyBy('tenant_id'),

            'csat' => SupportTicket::query()
                ->whereNotNull('rating')
                ->where('rated_at', '>=', $now->copy()->subDays(180))
                ->selectRaw('tenant_id, AVG(rating) as average, COUNT(*) as aggregate')
                ->groupBy('tenant_id')->get()->keyBy('tenant_id'),
        ];
    }

    /**
     * Score one tenant. Returns the clamped 0–100 risk and the human reasons
     * behind it — the reasons are the deliverable, the number just sorts them.
     *
     * @param  array<string, mixed>  $signals
     * @return array{0: int, 1: array<int, string>}
     */
    private function scoreTenant(Tenant $tenant, ?Subscription $sub, array $signals, Carbon $now): array
    {
        $score = 0;
        $reasons = [];
        $add = function (string $key, string $reason, float $factor = 1.0) use (&$score, &$reasons): void {
            $score += (int) round(self::WEIGHTS[$key] * $factor);
            $reasons[] = $reason;
        };

        if ($tenant->status === 'suspended') {
            $add('suspended', 'Workspace is suspended');
        }
        if ($sub?->status === 'past_due') {
            $add('past_due', 'Subscription payment is past due');
        }
        if ($sub && $sub->status === 'trialing' && $sub->trial_ends_at?->isPast()) {
            $add('trial_expired', 'Trial expired without converting');
        }
        if ($sub?->cancelled_at && $sub->status !== 'cancelled') {
            $add('cancel_scheduled', 'Cancellation scheduled at period end');
        }

        $failed = (int) ($signals['failed_invoices'][$tenant->id] ?? 0);
        if ($failed > 0) {
            $add('failed_payments', "{$failed} failed payment(s) in 60 days", min(1.0, $failed / 2));
        }

        // Inactivity, scaled from the first silent week to the ceiling. Young
        // workspaces are exempt — a tenant created three days ago is not
        // disengaging, it is onboarding.
        $lastRaw = $signals['last_activity'][$tenant->id] ?? null;
        $last = $lastRaw ? Carbon::parse($lastRaw) : null;
        $ageDays = $tenant->created_at ? (int) $tenant->created_at->diffInDays($now) : 0;
        if ($ageDays >= 7) {
            $silentDays = $last ? (int) $last->diffInDays($now) : $ageDays;
            if ($silentDays >= 7) {
                $factor = min(1.0, $silentDays / self::STALE_CEILING_DAYS);
                $add('no_activity', $last
                    ? "No activity for {$silentDays} days"
                    : 'Never used since signup', $factor);
            }
        }

        $recent = (int) ($signals['activity_recent'][$tenant->id] ?? 0);
        $prior = (int) ($signals['activity_prior'][$tenant->id] ?? 0);
        if ($prior >= 10 && $recent < $prior * 0.5) {
            $drop = (int) round((1 - ($recent / max(1, $prior))) * 100);
            $add('activity_decline', "Usage down {$drop}% versus the previous 30 days");
        }

        if ((int) ($signals['users'][$tenant->id] ?? 0) === 0) {
            $add('no_users', 'No users in the workspace');
        }

        $tickets = $signals['tickets'][$tenant->id] ?? null;
        if ($tickets && (bool) $tickets->has_urgent) {
            $add('urgent_ticket', 'Has an urgent open ticket');
        }
        if ($tickets && (int) $tickets->aggregate >= 3) {
            $add('ticket_pressure', "{$tickets->aggregate} open tickets");
        }

        $csat = $signals['csat'][$tenant->id] ?? null;
        if ($csat && (float) $csat->average <= 2.5) {
            $avg = round((float) $csat->average, 1);
            $add('poor_csat', "Support satisfaction {$avg}/5 over {$csat->aggregate} rating(s)");
        }

        // A renewal about to land on an account already showing strain is the
        // moment the customer decides — worth flagging while it can be saved.
        if ($score > 0 && $sub?->current_period_end
            && $sub->current_period_end->isFuture()
            && $now->diffInDays($sub->current_period_end) <= 14) {
            $add('renewal_imminent', 'Renewal due within 14 days');
        }

        return [min(100, $score), $reasons];
    }

    private function band(int $score): string
    {
        return match (true) {
            $score >= 70 => 'critical',
            $score >= 45 => 'high',
            $score >= 20 => 'medium',
            default => 'low',
        };
    }

    /**
     * The next best action for a scored tenant, picked off its strongest signal
     * rather than the score alone — "call them" is useless advice when the real
     * problem is a card that keeps declining.
     *
     * @param  array<int, string>  $reasons
     */
    private function recommendation(int $score, array $reasons): string
    {
        $text = strtolower(implode(' | ', $reasons));

        return match (true) {
            $score === 0 => 'Healthy — no action needed',
            str_contains($text, 'past due') || str_contains($text, 'failed payment') => 'Chase payment: send a fresh pay link before the grace period ends',
            str_contains($text, 'suspended') => 'Resolve the suspension with the account owner',
            str_contains($text, 'trial expired') => 'Trial conversion call — offer onboarding help or a discount',
            str_contains($text, 'cancellation scheduled') => 'Win-back call before the period ends',
            str_contains($text, 'never used') || str_contains($text, 'no users') => 'Onboarding rescue: walk them through first setup',
            str_contains($text, 'no activity') || str_contains($text, 'usage down') => 'Re-engagement: check what stalled and offer a training session',
            str_contains($text, 'satisfaction') || str_contains($text, 'urgent') => 'Escalate support — clear the open issues first',
            default => 'Monitor and check in at the next renewal',
        };
    }

    // -----------------------------------------------------------------------
    // Shared helpers
    // -----------------------------------------------------------------------

    /** Monthly-equivalent price of a subscription (yearly plans divided out). */
    private function monthlyValue(Subscription $subscription): float
    {
        $price = (float) ($subscription->plan?->price ?? 0);

        return $subscription->plan?->billing_cycle === 'yearly' ? $price / 12 : $price;
    }

    /**
     * Cheapest plan above the current one whose caps clear every pressured
     * resource. Null when nothing in the catalogue does.
     *
     * @param  Collection<int, Plan>  $plans
     * @param  array<int, array<string, mixed>>  $pressure
     */
    private function nextPlanFor(Collection $plans, Plan $current, array $pressure): ?Plan
    {
        $needed = [];
        foreach ($pressure as $p) {
            $key = TenantLimits::METERED[$p['resource']][1];
            // Aim for real headroom, not a tier they outgrow next quarter.
            $needed[$key] = (int) ceil($p['used'] * 1.25);
        }

        return $plans
            ->filter(fn (Plan $plan) => (float) $plan->price > (float) $current->price)
            ->sortBy('price')
            ->first(function (Plan $plan) use ($needed): bool {
                foreach ($needed as $key => $required) {
                    $cap = $plan->feature_limits[$key] ?? null;
                    if (is_numeric($cap) && (int) $cap < $required) {
                        return false;
                    }
                }

                return true;
            });
    }

    /**
     * Plain-language caveats on the forecast, so an operator reading a number
     * knows how much weight it can carry.
     *
     * @return array<int, string>
     */
    private function forecastNotes(int $months, float $confidence, float $slope): array
    {
        $notes = [];

        if ($months < 3) {
            $notes[] = 'Fewer than three complete months of billing history — the projection leans almost entirely on contracted MRR.';
        } elseif ($months < 6) {
            $notes[] = 'Limited history: the trend is fitted on '.$months.' complete months.';
        }
        if ($confidence < 0.5) {
            $notes[] = 'Revenue has been volatile month to month, so the band is wide.';
        }
        if ($slope < 0) {
            $notes[] = 'The fitted trend is downward — check the churn list for what is coming off the book.';
        }
        $notes[] = 'The current month is excluded from the fit because it is still accruing.';

        return $notes;
    }
}
