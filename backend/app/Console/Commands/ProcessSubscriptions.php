<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Subscription;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Notification;

/**
 * Daily housekeeping for subscriptions:
 *  - trial-ending reminder emails (3 days out, 1 day out)
 *  - grace-period expiry: once a trial/subscription has lapsed past the grace
 *    window with no active paid plan, suspend the tenant (read-only).
 *
 * Scheduled daily in routes/console.php.
 */
class ProcessSubscriptions extends Command
{
    protected $signature = 'subscriptions:process';

    protected $description = 'Send trial reminders and suspend lapsed tenants past the grace period';

    public function handle(): int
    {
        $this->sendTrialReminders();
        $this->suspendLapsed();

        return self::SUCCESS;
    }

    private function sendTrialReminders(): void
    {
        foreach ([3, 1] as $daysOut) {
            $windowStart = now()->addDays($daysOut)->startOfDay();
            $windowEnd = now()->addDays($daysOut)->endOfDay();

            $subs = Subscription::with('tenant')
                ->where('status', 'trialing')
                ->whereBetween('trial_ends_at', [$windowStart, $windowEnd])
                ->get();

            foreach ($subs as $sub) {
                $tenant = $sub->tenant;
                if (! $tenant?->email) {
                    continue;
                }

                Notification::route('mail', $tenant->email)->notifyNow(
                    new class ($tenant, $daysOut) extends \Illuminate\Notifications\Notification {
                        public function __construct(public Tenant $tenant, public int $days) {}

                        public function via($n): array
                        {
                            return ['mail'];
                        }

                        public function toMail($n): MailMessage
                        {
                            $host = $this->tenant->primaryDomain().'.'.config('saas.tenant_domain');

                            return (new MailMessage)
                                ->subject('Your trial ends in '.$this->days.' day'.($this->days === 1 ? '' : 's'))
                                ->greeting('Heads up!')
                                ->line('Your '.config('app.name').' free trial for '.$this->tenant->company_name.' ends in '.$this->days.' day'.($this->days === 1 ? '' : 's').'.')
                                ->action('Choose a plan', 'http://'.$host.'/billing')
                                ->line('Add a plan now to keep uninterrupted access to your inventory.');
                        }
                    }
                );

                $this->info("Reminder ({$daysOut}d) → {$tenant->email}");
            }
        }
    }

    private function suspendLapsed(): void
    {
        $graceDays = (int) config('saas.grace_days', 3);
        $cutoff = now()->subDays($graceDays);

        // Trials that ended more than grace-days ago and never converted.
        $lapsedTrials = Subscription::with('tenant')
            ->where('status', 'trialing')
            ->whereNotNull('trial_ends_at')
            ->where('trial_ends_at', '<', $cutoff)
            ->get();

        // Active paid subs whose paid period ended past the grace window.
        $lapsedPaid = Subscription::with('tenant')
            ->whereIn('status', ['active', 'past_due'])
            ->whereNotNull('current_period_end')
            ->where('current_period_end', '<', $cutoff)
            ->get();

        foreach ($lapsedTrials->concat($lapsedPaid) as $sub) {
            $sub->update(['status' => 'suspended']);
            if ($sub->tenant) {
                $sub->tenant->update(['status' => 'suspended']);
                $this->warn("Suspended tenant {$sub->tenant->company_name} ({$sub->tenant_id})");
            }
        }
    }
}
