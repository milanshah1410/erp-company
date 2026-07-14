<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\IteratesTenants;
use App\Models\ManufacturingOrder;
use App\Models\User;
use App\Notifications\ManufacturingOrderDueNotification;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Notification;

class NotifyPendingManufacturing extends Command
{
    use IteratesTenants;

    protected $signature = 'manufacturing:notify-pending {--warning-days=2}';

    protected $description = 'Notify production staff about manufacturing orders that are overdue or due soon and still open';

    public function handle(): int
    {
        $horizon = now()->addDays((int) $this->option('warning-days'))->endOfDay();
        $total = 0;

        $this->eachTenant(function () use ($horizon, &$total): void {
            $orders = ManufacturingOrder::query()
                ->with('product:id,name')
                ->whereIn('status', ['draft', 'in_progress'])
                ->whereNotNull('expected_completion_date')
                ->where('expected_completion_date', '<=', $horizon)
                ->get();

            if ($orders->isEmpty()) {
                return;
            }

            $recipients = User::role(['admin', 'super-admin', 'warehouse-manager'])
                ->where('is_active', true)->get();
            if ($recipients->isEmpty()) {
                return;
            }

            foreach ($orders as $order) {
                if ($this->alreadyNotified($recipients, $order->id)) {
                    continue;
                }
                Notification::send($recipients, new ManufacturingOrderDueNotification($order));
                $total++;
            }
        });

        $this->info("Sent {$total} manufacturing-due notification(s).");

        return self::SUCCESS;
    }

    /** Notify a given order once (scoped to this tenant's recipients). */
    private function alreadyNotified(Collection $recipients, int $orderId): bool
    {
        return DatabaseNotification::query()
            ->where('type', ManufacturingOrderDueNotification::class)
            ->whereIn('notifiable_id', $recipients->modelKeys())
            ->where('data->mo_id', $orderId)
            ->exists();
    }
}
