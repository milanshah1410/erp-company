<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\Tenant;
use App\Notifications\SubscriptionInvoiceNotification;
use App\Services\Billing\SubscriptionBillingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * The renewal & dunning cycle (Volume 11 §Billing → Renewals). Scheduled daily,
 * ahead of `subscriptions:process` — this is what gives a tenant an invoice and a
 * chain of reminders *before* that command's grace window suspends them.
 *
 * Three passes, in the order the money moves:
 *
 *  1. **Issue** — a subscription whose period ends within the lead window gets a
 *     numbered GST invoice for the next period, with its pinned coupon applied.
 *  2. **Lapse** — a period that has ended with an invoice still unpaid flips the
 *     subscription to `past_due`, which is the read-only grace state the workspace
 *     stays usable in.
 *  3. **Chase** — overdue invoices get reminders at the configured intervals, the
 *     last one warning about suspension.
 *
 * Every pass is idempotent: issuing checks for an invoice already covering the
 * period, and each reminder stage is recorded on the invoice, so running twice in
 * a day cannot double-bill or double-email. That matters because this is exactly
 * the command an operator re-runs by hand after fixing something.
 */
class ProcessRenewals extends Command
{
    protected $signature = 'subscriptions:renew
                            {--dry-run : Report what would happen without writing or emailing}';

    protected $description = 'Raise renewal invoices, mark lapsed subscriptions past due, and chase overdue invoices';

    private bool $dry = false;

    public function __construct(private SubscriptionBillingService $billing)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->dry = (bool) $this->option('dry-run');

        if ($this->dry) {
            $this->warn('Dry run — nothing will be written or emailed.');
        }

        $this->issueRenewals();
        $this->markLapsed();
        $this->chaseOverdue();

        return self::SUCCESS;
    }

    /**
     * Raise the next invoice for every subscription renewing inside the lead
     * window.
     */
    private function issueRenewals(): void
    {
        $lead = (int) config('saas.billing.renewal.lead_days', 5);

        $due = Subscription::with(['tenant', 'plan'])
            ->whereIn('status', ['active', 'past_due'])
            ->whereNotNull('current_period_end')
            ->whereBetween('current_period_end', [now()->startOfDay(), now()->addDays($lead)->endOfDay()])
            // A subscription cancelled at cycle end is on its way out; billing it
            // for another period is the opposite of what the customer asked for.
            ->whereNull('cancelled_at')
            ->get();

        foreach ($due as $subscription) {
            $plan = $subscription->pendingPlan ?: $subscription->plan;

            // Nothing to bill: a free tier, or a Custom plan whose price is agreed
            // off-platform and recorded as an offline payment.
            if (! $plan || (float) $plan->price <= 0) {
                continue;
            }

            $periodEnd = $subscription->nextPeriodEnd($plan);

            if ($this->billing->openInvoiceForPeriod($subscription, $periodEnd)) {
                continue;
            }

            $name = $subscription->tenant?->company_name ?? "tenant #{$subscription->tenant_id}";

            if ($this->dry) {
                $this->line("Would invoice {$name}: {$plan->name} → period ending {$periodEnd->toDateString()}");

                continue;
            }

            try {
                $invoice = $this->billing->issue($subscription, ['plan' => $plan]);
            } catch (Throwable $e) {
                // One bad subscription (an expired coupon, a pricing edge case)
                // must not stop the rest of the run.
                report($e);
                $this->error("Could not invoice {$name}: {$e->getMessage()}");

                continue;
            }

            $this->notify($invoice, 'issued');
            $this->info("Invoiced {$name}: {$invoice->displayNumber()} for {$invoice->currency} {$invoice->amount}");
        }
    }

    /**
     * Flip subscriptions whose paid period has ended, with an invoice still
     * outstanding, into `past_due` — the grace state.
     *
     * Deliberately does not suspend: `subscriptions:process` owns that, at the end
     * of the grace window, and keeping the two decisions in one place each is what
     * stops a tenant being locked out on the day their period ends.
     */
    private function markLapsed(): void
    {
        $lapsed = Subscription::with('tenant')
            ->where('status', 'active')
            ->whereNotNull('current_period_end')
            ->where('current_period_end', '<', now()->startOfDay())
            ->get()
            ->filter(fn (Subscription $s) => SubscriptionInvoice::query()
                ->where('subscription_id', $s->id)
                ->outstanding()
                ->exists());

        foreach ($lapsed as $subscription) {
            $name = $subscription->tenant?->company_name ?? "tenant #{$subscription->tenant_id}";

            if ($this->dry) {
                $this->line("Would mark {$name} past due");

                continue;
            }

            $subscription->update(['status' => 'past_due']);
            $this->warn("Past due: {$name}");
        }
    }

    /**
     * Send the next reminder for each overdue invoice.
     *
     * `reminder_stage` is the count already sent, so a tenant that has gone 9 days
     * overdue after the sweep was down for a week receives the *current* stage
     * once, not one email per missed threshold.
     */
    private function chaseOverdue(): void
    {
        /** @var list<int> $thresholds */
        $thresholds = array_values(array_filter(
            (array) config('saas.billing.renewal.reminder_days', [1, 3, 7]),
            static fn ($d) => is_numeric($d) && (int) $d > 0,
        ));
        sort($thresholds);

        if ($thresholds === []) {
            return;
        }

        $invoices = SubscriptionInvoice::with(['subscription.tenant'])->overdue()->get();

        foreach ($invoices as $invoice) {
            $days = $invoice->daysOverdue();

            // How many thresholds this invoice has passed. Sending is due when that
            // is more than the number of reminders already out.
            $passed = count(array_filter($thresholds, static fn (int $t) => $days >= $t));

            if ($passed === 0 || $invoice->reminder_stage >= $passed) {
                continue;
            }

            $stage = $passed >= count($thresholds) ? 'final' : 'reminder';
            $name = $invoice->subscription?->tenant?->company_name ?? '?';

            if ($this->dry) {
                $this->line("Would send {$stage} reminder for {$invoice->displayNumber()} ({$name}, {$days}d overdue)");

                continue;
            }

            if (! $this->notify($invoice, $stage)) {
                continue;
            }

            $invoice->update([
                'reminder_stage' => $passed,
                'last_reminder_at' => now(),
            ]);

            $this->info("Reminder ({$stage}, {$days}d) → {$name} for {$invoice->displayNumber()}");
        }
    }

    /** Mail one invoice notification. False when the tenant has no address. */
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
