<?php

declare(strict_types=1);

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\Tenant;
use App\Notifications\SubscriptionInvoiceNotification;
use App\Services\Billing\SubscriptionBillingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;

/**
 * The renewals & dunning desk for the platform panel (Volume 11 §Billing →
 * Renewals). Guarded by `renewals.view` / `renewals.manage`.
 *
 * Three buckets, which are the three questions an operator has about money that
 * has not arrived yet:
 *
 *   upcoming — a period ends soon and no invoice has been raised for it
 *   due      — an invoice is out and not yet past its due date
 *   overdue  — an invoice is past due; the workspace is heading for suspension
 *
 * `upcoming` is derived from subscriptions, the other two from invoices, because
 * that is where the truth for each lives — an unraised invoice does not exist yet,
 * and a raised one is no longer a property of the subscription's dates.
 *
 * The daily `subscriptions:renew` sweep does all of this unattended; these
 * endpoints are the manual override, for chasing one account ahead of schedule.
 */
class BillingRenewalController extends Controller
{
    public function __construct(private SubscriptionBillingService $billing) {}

    public function index(Request $request): JsonResponse
    {
        $bucket = $request->query('bucket', 'due');
        if (! in_array($bucket, ['upcoming', 'due', 'overdue'], true)) {
            $bucket = 'due';
        }

        $horizon = min(90, max(1, (int) $request->query('days', (int) config('saas.billing.renewal.lead_days', 5) + 25)));
        $limit = min(200, max(10, (int) $request->query('limit', 50)));

        return response()->json([
            'bucket' => $bucket,
            'horizon_days' => $horizon,
            'summary' => $this->summary($horizon),
            'data' => match ($bucket) {
                'upcoming' => $this->upcomingRows($horizon, $limit),
                'overdue' => $this->invoiceRows(SubscriptionInvoice::query()->overdue(), $limit, 'due_date', 'asc'),
                default => $this->invoiceRows(
                    SubscriptionInvoice::query()->outstanding()
                        ->where(fn ($q) => $q->whereNull('due_date')->orWhere('due_date', '>=', now()->toDateString())),
                    $limit,
                    'due_date',
                    'asc',
                ),
            },
        ]);
    }

    /**
     * Raise the renewal invoice for one subscription now, rather than waiting for
     * the sweep. Refuses if the period already has an invoice — re-billing the same
     * period is the one mistake this screen must not make easy.
     */
    public function issue(Request $request, Subscription $subscription): JsonResponse
    {
        $data = $request->validate([
            'coupon_code' => ['sometimes', 'nullable', 'string', 'max:40'],
            'due_date' => ['sometimes', 'nullable', 'date'],
            'notify' => ['sometimes', 'boolean'],
        ]);

        if ($subscription->status === 'cancelled') {
            return response()->json(['message' => 'This subscription is cancelled — reactivate it before billing.'], 422);
        }

        $plan = $subscription->pendingPlan ?: $subscription->plan;
        if (! $plan) {
            return response()->json(['message' => 'This subscription has no plan to bill.'], 422);
        }
        if ($plan->is_custom && (float) $plan->price <= 0) {
            return response()->json([
                'message' => 'This is a quote-based Custom plan with no price set — record the agreed amount as an offline payment instead.',
            ], 422);
        }

        $periodEnd = $subscription->nextPeriodEnd($plan);
        if ($existing = $this->billing->openInvoiceForPeriod($subscription, $periodEnd)) {
            return response()->json([
                'message' => "Invoice {$existing->displayNumber()} already covers the period ending {$periodEnd->toDateString()}.",
            ], 422);
        }

        $invoice = $this->billing->issue($subscription, [
            'plan' => $plan,
            'coupon_code' => $data['coupon_code'] ?? null,
            'due_date' => $data['due_date'] ?? null,
        ]);

        $notified = ($data['notify'] ?? true) && $this->notify($invoice, 'issued');

        activity('central-billing')->causedBy($request->user())
            ->withProperties([
                'ip' => $request->ip(),
                'subscription_id' => $subscription->id,
                'invoice_id' => $invoice->id,
                'amount' => $invoice->amount,
                'notified' => $notified,
            ])
            ->event('renewal-invoice-issued')
            ->log('Operator issued renewal invoice '.$invoice->displayNumber().' for "'.($subscription->tenant?->company_name ?? '?').'"');

        return response()->json([
            'message' => 'Invoice '.$invoice->displayNumber().' raised'.($notified ? ' and emailed to the tenant.' : '.'),
            'data' => $this->invoicePayload($invoice->fresh(['subscription.tenant', 'subscription.plan'])),
        ]);
    }

    /** Email the tenant about an outstanding invoice right now. */
    public function remind(Request $request, SubscriptionInvoice $invoice): JsonResponse
    {
        if (! $invoice->isOutstanding()) {
            return response()->json(['message' => 'This invoice is not outstanding.'], 422);
        }

        $stage = $invoice->isOverdue() ? 'reminder' : 'issued';

        if (! $this->notify($invoice, $stage)) {
            return response()->json(['message' => 'This tenant has no billing email on record.'], 422);
        }

        $invoice->update([
            'reminder_stage' => $invoice->reminder_stage + 1,
            'last_reminder_at' => now(),
        ]);

        activity('central-billing')->causedBy($request->user())
            ->withProperties(['ip' => $request->ip(), 'invoice_id' => $invoice->id, 'stage' => $stage])
            ->event('renewal-reminder-sent')
            ->log('Operator sent a payment reminder for invoice '.$invoice->displayNumber());

        return response()->json(['message' => 'Reminder sent.']);
    }

    /**
     * Cancel an unpaid invoice. The number stays in the series (a GST series may
     * not have holes) and any coupon it consumed is released.
     */
    public function void(Request $request, SubscriptionInvoice $invoice): JsonResponse
    {
        if ($invoice->status === 'paid') {
            return response()->json(['message' => 'A paid invoice cannot be voided — record a refund instead.'], 422);
        }

        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        $this->billing->void($invoice, $data['reason']);

        activity('central-billing')->causedBy($request->user())
            ->withProperties(['ip' => $request->ip(), 'invoice_id' => $invoice->id, 'reason' => $data['reason']])
            ->event('invoice-voided')
            ->log('Operator voided invoice '.$invoice->displayNumber());

        return response()->json(['message' => 'Invoice '.$invoice->displayNumber().' voided.']);
    }

    /**
     * Settle an outstanding invoice against money received outside the gateway,
     * and roll the subscription onto the period it paid for.
     */
    public function markPaid(Request $request, SubscriptionInvoice $invoice): JsonResponse
    {
        if ($invoice->status === 'paid') {
            return response()->json(['message' => 'This invoice is already paid.'], 422);
        }
        if ($invoice->status === 'void') {
            return response()->json(['message' => 'This invoice was voided.'], 422);
        }

        $data = $request->validate([
            'method' => ['required', Rule::in(['bank_transfer', 'upi', 'cheque', 'cash', 'other'])],
            'reference' => ['nullable', 'string', 'max:255'],
            'paid_at' => ['nullable', 'date', 'before_or_equal:now'],
            'extend' => ['sometimes', 'boolean'],
        ]);

        $this->billing->markPaid($invoice, [
            'method' => $data['method'],
            'reference' => $data['reference'] ?? null,
            'paid_at' => $data['paid_at'] ?? null,
            'extend' => $data['extend'] ?? true,
        ]);

        activity('central-billing')->causedBy($request->user())
            ->withProperties([
                'ip' => $request->ip(),
                'invoice_id' => $invoice->id,
                'amount' => $invoice->amount,
                'method' => $data['method'],
            ])
            ->event('invoice-marked-paid')
            ->log('Operator settled invoice '.$invoice->displayNumber().' ('.$data['method'].')');

        return response()->json([
            'message' => 'Invoice '.$invoice->displayNumber().' marked paid.',
            'data' => $this->invoicePayload($invoice->fresh(['subscription.tenant', 'subscription.plan'])),
        ]);
    }

    /**
     * Counts and value per bucket. Values are gross (what the tenant owes), which
     * is the figure a collections conversation is about.
     *
     * @return array<string, mixed>
     */
    private function summary(int $horizon): array
    {
        $due = SubscriptionInvoice::query()->outstanding()
            ->where(fn ($q) => $q->whereNull('due_date')->orWhere('due_date', '>=', now()->toDateString()));
        $overdue = SubscriptionInvoice::query()->overdue();

        $upcoming = $this->upcomingSubscriptions($horizon);

        return [
            'upcoming_count' => $upcoming->count(),
            // Gross list price, before the coupon/GST arithmetic an invoice would
            // apply — an estimate, and labelled as one in the panel.
            'upcoming_value' => round((float) $upcoming->sum(fn (Subscription $s) => (float) ($s->plan?->price ?? 0)), 2),
            'due_count' => (clone $due)->count(),
            'due_value' => round((float) (clone $due)->sum('amount'), 2),
            'overdue_count' => (clone $overdue)->count(),
            'overdue_value' => round((float) (clone $overdue)->sum('amount'), 2),
        ];
    }

    /**
     * Subscriptions whose period ends inside the horizon with nothing raised for
     * it yet.
     *
     * The "nothing raised yet" half cannot be a SQL join — whether an invoice
     * covers the *next* period depends on nextPeriodEnd(), which reads the plan's
     * billing cycle — so the horizon window is filtered in the database and the
     * already-billed ones are rejected in PHP. The window keeps that set small.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Subscription>
     */
    private function upcomingSubscriptions(int $horizon)
    {
        return Subscription::query()
            ->with(['tenant', 'plan'])
            ->whereIn('status', ['active', 'past_due'])
            ->whereNotNull('current_period_end')
            ->whereBetween('current_period_end', [now()->startOfDay(), now()->addDays($horizon)->endOfDay()])
            ->get()
            ->reject(fn (Subscription $s) => $this->billing->openInvoiceForPeriod($s, $s->nextPeriodEnd()) !== null)
            ->values();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function upcomingRows(int $horizon, int $limit): array
    {
        return $this->upcomingSubscriptions($horizon)
            ->sortBy(fn (Subscription $s) => $s->current_period_end?->timestamp)
            ->take($limit)
            ->map(fn (Subscription $s) => [
                'kind' => 'subscription',
                'subscription_id' => $s->id,
                'tenant_id' => $s->tenant_id,
                'tenant_name' => $s->tenant?->company_name,
                'tenant_email' => $s->tenant?->email,
                'plan_name' => $s->plan?->name,
                'billing_cycle' => $s->plan?->billing_cycle,
                'currency' => $s->plan?->currency ?? 'INR',
                'status' => $s->status,
                'period_end' => $s->current_period_end?->toDateString(),
                'days_to_renewal' => (int) ceil(now()->diffInDays($s->current_period_end, false)),
                'estimated_amount' => (float) ($s->plan?->price ?? 0),
                'coupon_code' => $s->coupon_code,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function invoiceRows($query, int $limit, string $orderBy, string $direction): array
    {
        return $query->with(['subscription.tenant', 'subscription.plan'])
            ->orderBy($orderBy, $direction)
            ->limit($limit)
            ->get()
            ->map(fn (SubscriptionInvoice $i) => $this->invoicePayload($i))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function invoicePayload(SubscriptionInvoice $i): array
    {
        return [
            'kind' => 'invoice',
            'id' => $i->id,
            'number' => $i->displayNumber(),
            'type' => $i->type,
            'subscription_id' => $i->subscription_id,
            'tenant_id' => $i->subscription?->tenant_id,
            'tenant_name' => $i->subscription?->tenant?->company_name,
            'tenant_email' => $i->subscription?->tenant?->email,
            'plan_name' => $i->subscription?->plan?->name,
            'amount' => $i->amount,
            'currency' => $i->currency,
            'status' => $i->status,
            'discount_amount' => $i->discount_amount,
            'coupon_code' => $i->coupon_code,
            'tax_amount' => $i->tax_amount,
            'period_start' => $i->period_start?->toDateString(),
            'period_end' => $i->period_end?->toDateString(),
            'due_date' => $i->due_date?->toDateString(),
            'days_overdue' => $i->daysOverdue(),
            'reminder_stage' => $i->reminder_stage,
            'last_reminder_at' => $i->last_reminder_at?->toDateTimeString(),
            'created_at' => $i->created_at?->toDateTimeString(),
        ];
    }

    /** Mail one invoice notification. False when there is no address to send to. */
    private function notify(SubscriptionInvoice $invoice, string $stage): bool
    {
        $tenant = $invoice->subscription?->tenant ?? Tenant::find($invoice->subscription?->tenant_id);

        if (! $tenant?->email) {
            return false;
        }

        Notification::route('mail', $tenant->email)
            ->notify(new SubscriptionInvoiceNotification($invoice, $tenant, $stage));

        return true;
    }
}
