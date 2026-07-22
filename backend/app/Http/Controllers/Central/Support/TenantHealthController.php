<?php

declare(strict_types=1);

namespace App\Http\Controllers\Central\Support;

use App\Http\Controllers\Controller;
use App\Models\SupportTicket;
use App\Models\SubscriptionInvoice;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Tenant health for the Support & Customer Success panel: a per-tenant
 * scorecard (healthy / warning / at-risk) computed from workspace status,
 * subscription state, user activity, failed payments and open tickets — plus
 * a troubleshooting drill-down for one tenant. Read-only; guarded by
 * `support.view` (routes/api.php).
 *
 * All signals come from grouped queries over the shared tables (users,
 * activity_log carry tenant_id) so the listing is a fixed handful of queries
 * regardless of tenant count.
 */
class TenantHealthController extends Controller
{
    /** Days without any activity_log entry before a tenant counts as inactive. */
    private const STALE_DAYS = 14;

    /** Days before trial/period end that we start flagging renewals. */
    private const EXPIRY_SOON_DAYS = 3;

    public function index(): JsonResponse
    {
        $tenants = Tenant::query()->with('subscription.plan')->orderBy('company_name')->get();

        // One grouped query per signal (users/activity live on the default
        // connection, tickets/invoices on the central one — hence no joins
        // into tenants).
        $userCounts = DB::table('users')
            ->selectRaw('tenant_id, count(*) as aggregate')
            ->groupBy('tenant_id')->pluck('aggregate', 'tenant_id');

        $lastActivity = DB::table('activity_log')
            ->whereNotNull('tenant_id')
            ->selectRaw('tenant_id, max(created_at) as latest')
            ->groupBy('tenant_id')->pluck('latest', 'tenant_id');

        $openTickets = SupportTicket::query()
            ->whereIn('status', ['open', 'pending'])
            ->selectRaw('tenant_id, count(*) as aggregate, max(priority = \'urgent\') as has_urgent')
            ->groupBy('tenant_id')->get()->keyBy('tenant_id');

        $failedInvoices = SubscriptionInvoice::query()
            ->where('subscription_invoices.status', 'failed')
            ->where('subscription_invoices.created_at', '>=', now()->subDays(30))
            ->join('subscriptions', 'subscriptions.id', '=', 'subscription_invoices.subscription_id')
            ->selectRaw('subscriptions.tenant_id, count(*) as aggregate')
            ->groupBy('subscriptions.tenant_id')->pluck('aggregate', 'tenant_id');

        $rows = $tenants->map(function (Tenant $t) use ($userCounts, $lastActivity, $openTickets, $failedInvoices) {
            $tickets = $openTickets[$t->id] ?? null;
            $signals = [
                'users' => (int) ($userCounts[$t->id] ?? 0),
                'last_activity_at' => $lastActivity[$t->id] ?? null,
                'open_tickets' => (int) ($tickets->aggregate ?? 0),
                'has_urgent_ticket' => (bool) ($tickets->has_urgent ?? false),
                'failed_invoices_30d' => (int) ($failedInvoices[$t->id] ?? 0),
            ];

            [$level, $reasons] = $this->score($t, $signals);

            return [
                'id' => $t->id,
                'company_name' => $t->company_name,
                'email' => $t->email,
                'status' => $t->status,
                'plan' => $t->subscription?->plan?->name,
                'subscription_status' => $t->subscription?->status,
                'trial_ends_at' => $t->subscription?->trial_ends_at?->toDateString(),
                'current_period_end' => $t->subscription?->current_period_end?->toDateString(),
                'users_count' => $signals['users'],
                'last_activity_at' => $signals['last_activity_at'],
                'open_tickets' => $signals['open_tickets'],
                'failed_invoices_30d' => $signals['failed_invoices_30d'],
                'health' => $level,
                'reasons' => $reasons,
                'created_at' => $t->created_at?->toDateTimeString(),
            ];
        })->values();

        return response()->json([
            'data' => $rows,
            'summary' => [
                'total' => $rows->count(),
                'healthy' => $rows->where('health', 'healthy')->count(),
                'warning' => $rows->where('health', 'warning')->count(),
                'at_risk' => $rows->where('health', 'at_risk')->count(),
                'open_tickets' => $rows->sum('open_tickets'),
            ],
        ]);
    }

    /**
     * Troubleshooting drill-down: everything support needs about one tenant on
     * a single screen — subscription + recent invoices, latest tickets, most
     * recent workspace activity and the health verdict itself.
     */
    public function show(Tenant $tenant): JsonResponse
    {
        $tenant->load('subscription.plan');

        $users = DB::table('users')
            ->where('tenant_id', $tenant->id)
            ->selectRaw('count(*) as total, sum(created_at >= ?) as recent', [now()->subDays(30)])
            ->first();

        $lastActivity = DB::table('activity_log')
            ->where('tenant_id', $tenant->id)
            ->max('created_at');

        $ticketQuery = SupportTicket::query()->where('tenant_id', $tenant->id);
        $openTickets = (clone $ticketQuery)->whereIn('status', ['open', 'pending'])->get();

        [$level, $reasons] = $this->score($tenant, [
            'users' => (int) ($users->total ?? 0),
            'last_activity_at' => $lastActivity,
            'open_tickets' => $openTickets->count(),
            'has_urgent_ticket' => $openTickets->contains('priority', 'urgent'),
            'failed_invoices_30d' => $tenant->subscription
                ? $tenant->subscription->invoices()
                    ->where('status', 'failed')->where('created_at', '>=', now()->subDays(30))->count()
                : 0,
        ]);

        return response()->json(['data' => [
            'tenant' => [
                'id' => $tenant->id,
                'company_name' => $tenant->company_name,
                'email' => $tenant->email,
                'phone' => $tenant->phone,
                'status' => $tenant->status,
                'created_at' => $tenant->created_at?->toDateTimeString(),
            ],
            'health' => $level,
            'reasons' => $reasons,
            'usage' => [
                'users_total' => (int) ($users->total ?? 0),
                'users_added_30d' => (int) ($users->recent ?? 0),
                'last_activity_at' => $lastActivity,
            ],
            'subscription' => $tenant->subscription ? [
                'plan' => $tenant->subscription->plan?->name,
                'status' => $tenant->subscription->status,
                'trial_ends_at' => $tenant->subscription->trial_ends_at?->toDateTimeString(),
                'current_period_end' => $tenant->subscription->current_period_end?->toDateTimeString(),
                'cancelled_at' => $tenant->subscription->cancelled_at?->toDateTimeString(),
                'invoices' => $tenant->subscription->invoices()
                    ->orderByDesc('created_at')->limit(5)->get()
                    ->map(fn ($i) => [
                        'id' => $i->id,
                        'amount' => $i->amount,
                        'currency' => $i->currency,
                        'status' => $i->status,
                        'paid_at' => $i->paid_at?->toDateTimeString(),
                        'created_at' => $i->created_at?->toDateTimeString(),
                    ])->values(),
            ] : null,
            'tickets' => (clone $ticketQuery)
                ->orderByDesc('last_activity_at')->limit(5)->get()
                ->map(fn (SupportTicket $t) => [
                    'id' => $t->id,
                    'subject' => $t->subject,
                    'status' => $t->status,
                    'priority' => $t->priority,
                    'last_activity_at' => $t->last_activity_at?->toDateTimeString(),
                ])->values(),
            'recent_activity' => DB::table('activity_log')
                ->where('tenant_id', $tenant->id)
                ->orderByDesc('id')->limit(10)
                ->get(['log_name', 'description', 'event', 'created_at'])
                ->map(fn ($a) => [
                    'log' => $a->log_name,
                    'description' => $a->description,
                    'event' => $a->event,
                    'created_at' => (string) $a->created_at,
                ])->values(),
        ]]);
    }

    /**
     * Health verdict. At-risk signals (workspace unusable / money broken) win
     * over warnings (engagement or renewal drifting).
     *
     * @param  array{users: int, last_activity_at: ?string, open_tickets: int, has_urgent_ticket: bool, failed_invoices_30d: int}  $signals
     * @return array{0: 'healthy'|'warning'|'at_risk', 1: array<int, string>}
     */
    private function score(Tenant $tenant, array $signals): array
    {
        $atRisk = [];
        $warnings = [];
        $sub = $tenant->subscription;

        if ($tenant->status === 'suspended') {
            $atRisk[] = 'Workspace is suspended';
        }
        if ($sub?->status === 'past_due') {
            $atRisk[] = 'Subscription payment past due';
        }
        if ($sub?->status === 'cancelled') {
            $atRisk[] = 'Subscription cancelled';
        }
        if ($sub && $sub->status === 'trialing' && $sub->trial_ends_at?->isPast()) {
            $atRisk[] = 'Trial expired without conversion';
        }
        if ($signals['failed_invoices_30d'] > 0) {
            $atRisk[] = "{$signals['failed_invoices_30d']} failed payment(s) in the last 30 days";
        }
        if ($signals['has_urgent_ticket']) {
            $atRisk[] = 'Has an urgent open ticket';
        }

        if ($sub && $sub->status === 'trialing' && $sub->trial_ends_at?->isFuture()
            && now()->diffInDays($sub->trial_ends_at) <= self::EXPIRY_SOON_DAYS) {
            $warnings[] = 'Trial ends within '.self::EXPIRY_SOON_DAYS.' days';
        }
        if ($sub?->cancelled_at && $sub->status !== 'cancelled') {
            $warnings[] = 'Cancellation scheduled at period end';
        }
        if ($signals['users'] === 0) {
            $warnings[] = 'No users in the workspace';
        }

        $last = $signals['last_activity_at'] ? Carbon::parse($signals['last_activity_at']) : null;
        $old = ($tenant->created_at?->diffInDays(now()) ?? 0) >= self::STALE_DAYS;
        if ($old && (! $last || $last->diffInDays(now()) >= self::STALE_DAYS)) {
            $warnings[] = $last
                ? 'No activity for '.(int) $last->diffInDays(now()).' days'
                : 'No recorded activity yet';
        }
        if ($signals['open_tickets'] >= 3 && ! $signals['has_urgent_ticket']) {
            $warnings[] = "{$signals['open_tickets']} open tickets";
        }

        if ($atRisk !== []) {
            return ['at_risk', array_merge($atRisk, $warnings)];
        }
        if ($warnings !== []) {
            return ['warning', $warnings];
        }

        return ['healthy', []];
    }
}
