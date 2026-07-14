<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\IteratesTenants;
use App\Models\Customer;
use App\Models\User;
use App\Notifications\CustomerOccasionNotification;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Notification;

class NotifyCustomerOccasions extends Command
{
    use IteratesTenants;

    protected $signature = 'crm:notify-customer-occasions';

    protected $description = 'Notify sales/CRM staff of customer birthdays and anniversaries falling today';

    public function handle(): int
    {
        $total = 0;

        $this->eachTenant(function () use (&$total): void {
            $recipients = User::role(['admin', 'super-admin', 'sales-manager'])
                ->where('is_active', true)->get();
            if ($recipients->isEmpty()) {
                return;
            }

            $today = now();
            $month = (int) $today->month;
            $day = (int) $today->day;

            $customers = Customer::query()
                ->where('is_active', true)
                ->where(fn ($q) => $q->whereNotNull('date_of_birth')->orWhereNotNull('anniversary_date'))
                ->get(['id', 'name', 'date_of_birth', 'anniversary_date']);

            foreach ($customers as $customer) {
                foreach (['birthday' => $customer->date_of_birth, 'anniversary' => $customer->anniversary_date] as $occasion => $date) {
                    if (! $date || (int) $date->month !== $month || (int) $date->day !== $day) {
                        continue;
                    }
                    // Once per occasion per year (cooldown ~360 days).
                    if ($this->recentlyNotified($recipients, $customer->id, $occasion)) {
                        continue;
                    }
                    Notification::send($recipients, new CustomerOccasionNotification($customer, $occasion));
                    $total++;
                }
            }
        });

        $this->info("Sent {$total} occasion notification(s).");

        return self::SUCCESS;
    }

    private function recentlyNotified(Collection $recipients, int $customerId, string $occasion): bool
    {
        return DatabaseNotification::query()
            ->where('type', CustomerOccasionNotification::class)
            ->whereIn('notifiable_id', $recipients->modelKeys())
            ->where('data->customer_id', $customerId)
            ->where('data->occasion', $occasion)
            ->where('created_at', '>=', now()->subDays(360))
            ->exists();
    }
}
