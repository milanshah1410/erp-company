<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\Tenant;
use Illuminate\Console\Command;

/**
 * Dev-only helper: put a tenant's billing into a recognisable state so the
 * frontend flows can actually be SEEN locally, where Razorpay is not
 * configured and the webhook (which normally creates invoices and drives
 * status changes) never fires.
 *
 *   php artisan billing:demo paid       — invoice history + PDF download
 *   php artisan billing:demo past-due   — "Payment overdue" banner + Pay Now
 *   php artisan billing:demo suspended  — read-only workspace + Pay Now recovery
 *   php artisan billing:demo reset      — back to a clean active subscription
 */
class BillingDemoScenario extends Command
{
    protected $signature = 'billing:demo
        {scenario : paid | past-due | suspended | reset}
        {--tenant= : Tenant id (defaults to the first tenant)}';

    protected $description = 'Put a tenant into a demo billing state (paid invoices, past_due, suspended) to preview the frontend flows locally';

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('Refusing to run demo scenarios in production.');

            return self::FAILURE;
        }

        $tenant = $this->option('tenant')
            ? Tenant::find((int) $this->option('tenant'))
            : Tenant::orderBy('id')->first();

        if (! $tenant) {
            $this->error('No tenant found.');

            return self::FAILURE;
        }

        $sub = Subscription::with('plan')->where('tenant_id', $tenant->id)->latest('id')->first();
        if (! $sub) {
            $this->error("Tenant \"{$tenant->company_name}\" has no subscription row.");

            return self::FAILURE;
        }

        match ($this->argument('scenario')) {
            'paid' => $this->paid($tenant, $sub),
            'past-due' => $this->pastDue($tenant, $sub),
            'suspended' => $this->suspended($tenant, $sub),
            'reset' => $this->reset($tenant, $sub),
            default => $this->invalid(),
        };

        return self::SUCCESS;
    }

    /**
     * Case 1 — payment happened: an active subscription with a few months of
     * paid invoices (gateway + offline + one refund) so the Invoice History
     * table, status badges, and the PDF download all have something to show.
     */
    private function paid(Tenant $tenant, Subscription $sub): void
    {
        $this->reset($tenant, $sub, quiet: true);

        // Custom/enterprise plans carry price 0 — use a believable demo amount.
        $price = (float) ($sub->plan?->price ?? 0) ?: 4999.0;
        $currency = $sub->plan?->currency ?? 'INR';

        $months = [
            ['ref' => 'pay_DEMO_Rzp001', 'method' => null, 'ago' => 3, 'status' => 'paid'],
            ['ref' => 'UTR-DEMO-556677', 'method' => 'bank_transfer', 'ago' => 2, 'status' => 'paid', 'notes' => 'Offline renewal via HDFC'],
            ['ref' => 'pay_DEMO_Rzp002', 'method' => null, 'ago' => 1, 'status' => 'refunded'],
            ['ref' => 'pay_DEMO_Rzp003', 'method' => null, 'ago' => 0, 'status' => 'paid'],
        ];

        foreach ($months as $m) {
            $invoice = SubscriptionInvoice::updateOrCreate(
                ['payment_gateway_ref' => $m['ref']],
                [
                    'subscription_id' => $sub->id,
                    'amount' => $price,
                    'currency' => $currency,
                    'status' => $m['status'],
                    'payment_method' => $m['method'],
                    'notes' => $m['notes'] ?? null,
                    'paid_at' => now()->subMonths($m['ago']),
                    'refund_amount' => $m['status'] === 'refunded' ? $price : null,
                    'refund_reason' => $m['status'] === 'refunded' ? 'Demo: charged twice by mistake' : null,
                    'refunded_at' => $m['status'] === 'refunded' ? now()->subMonths($m['ago'])->addDays(2) : null,
                ],
            );

            // created_at is not mass-assignable — backdate it directly so the
            // history reads as a few months of billing.
            $invoice->created_at = now()->subMonths($m['ago']);
            $invoice->save();
        }

        $this->info("✔ \"{$tenant->company_name}\" is active with 4 demo invoices.");
        $this->line('  Open the tenant app → /billing → Invoice History: view/download the PDFs.');
        $this->line('  Platform panel → Billing → Invoices tab shows them too (Method/Ref column).');
    }

    /**
     * Case 2a — a renewal charge just failed: subscription past_due, workspace
     * still fully usable (grace). Shows the red "Payment overdue" banner with
     * the Pay Now button on /billing.
     */
    private function pastDue(Tenant $tenant, Subscription $sub): void
    {
        $sub->update([
            'status' => 'past_due',
            'trial_ends_at' => null,
            'current_period_start' => now()->subMonth(),
            'current_period_end' => now()->subDay(), // lapsed yesterday, inside grace
            'cancelled_at' => null,
        ]);
        $tenant->update(['status' => 'active', 'suspended_by' => null]);

        $this->info("✔ \"{$tenant->company_name}\" is past_due (grace period, workspace still writable).");
        $this->line('  Tenant app → /billing: red "Payment overdue" banner + Pay Now.');
        $this->line('  Pay Now (Razorpay unconfigured) reactivates instantly — the dev shortcut.');
    }

    /**
     * Case 2b — grace ran out: billing-suspended. Every write in the workspace
     * returns 402 (read-only), /billing shows "Subscription suspended" + Pay
     * Now, and paying restores everything.
     */
    private function suspended(Tenant $tenant, Subscription $sub): void
    {
        $sub->update([
            'status' => 'suspended',
            'trial_ends_at' => null,
            'current_period_start' => now()->subMonths(2),
            'current_period_end' => now()->subDays(5), // past the 3-day grace
            'cancelled_at' => null,
        ]);
        $tenant->update(['status' => 'suspended', 'suspended_by' => 'billing']);

        $this->info("✔ \"{$tenant->company_name}\" is billing-suspended (read-only).");
        $this->line('  Try saving anything in the tenant app → 402 "workspace is read-only".');
        $this->line('  /billing → "Subscription suspended" banner → Pay Now brings it back.');
    }

    private function reset(Tenant $tenant, Subscription $sub, bool $quiet = false): void
    {
        $sub->update([
            'status' => 'active',
            'pending_plan_id' => null,
            'trial_ends_at' => null,
            'current_period_start' => now(),
            'current_period_end' => $sub->plan?->billing_cycle === 'yearly' ? now()->addYear() : now()->addMonth(),
            'cancelled_at' => null,
        ]);
        $tenant->update(['status' => 'active', 'suspended_by' => null]);

        if (! $quiet) {
            $this->info("✔ \"{$tenant->company_name}\" reset to a clean active subscription (demo invoices kept).");
        }
    }

    private function invalid(): void
    {
        $this->error('Unknown scenario. Use: paid | past-due | suspended | reset');
    }
}
