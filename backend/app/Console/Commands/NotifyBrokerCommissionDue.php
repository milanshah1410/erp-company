<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\IteratesTenants;
use App\Models\Broker;
use App\Models\User;
use App\Notifications\BrokerCommissionDueNotification;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Notification;

class NotifyBrokerCommissionDue extends Command
{
    use IteratesTenants;

    protected $signature = 'brokers:notify-commission-due {--min-amount=1} {--cooldown-days=7}';

    protected $description = 'Notify sales/accounts about active brokers carrying an unsettled commission balance';

    public function handle(): int
    {
        $minAmount = (float) $this->option('min-amount');
        $cooldown = (int) $this->option('cooldown-days');
        $total = 0;

        $this->eachTenant(function () use ($minAmount, $cooldown, &$total): void {
            $brokers = Broker::query()->where('is_active', true)->get();
            if ($brokers->isEmpty()) {
                return;
            }

            $recipients = User::role(['admin', 'super-admin', 'sales-manager', 'accountant'])
                ->where('is_active', true)->get();
            if ($recipients->isEmpty()) {
                return;
            }

            foreach ($brokers as $broker) {
                $outstanding = $broker->outstandingCommission();
                if ($outstanding < $minAmount) {
                    continue;
                }
                if ($this->recentlyNotified($recipients, $broker->id, $cooldown)) {
                    continue;
                }
                Notification::send($recipients, new BrokerCommissionDueNotification($broker, $outstanding));
                $total++;
            }
        });

        $this->info("Sent {$total} broker-commission notification(s).");

        return self::SUCCESS;
    }

    /** Skip a broker already reminded within the cooldown window (per tenant). */
    private function recentlyNotified(Collection $recipients, int $brokerId, int $cooldownDays): bool
    {
        return DatabaseNotification::query()
            ->where('type', BrokerCommissionDueNotification::class)
            ->whereIn('notifiable_id', $recipients->modelKeys())
            ->where('data->broker_id', $brokerId)
            ->where('created_at', '>=', now()->subDays($cooldownDays))
            ->exists();
    }
}
