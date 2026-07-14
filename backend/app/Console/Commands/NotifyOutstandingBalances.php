<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\IteratesTenants;
use App\Models\Customer;
use App\Models\Supplier;
use App\Models\User;
use App\Notifications\OutstandingBalanceNotification;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Notification;

class NotifyOutstandingBalances extends Command
{
    use IteratesTenants;

    protected $signature = 'notifications:outstanding-balances {--cooldown-days=7}';

    protected $description = 'Notify about customers and suppliers whose outstanding balance exceeds their credit limit';

    public function handle(): int
    {
        $cooldown = (int) $this->option('cooldown-days');
        $customerCount = 0;
        $supplierCount = 0;

        $this->eachTenant(function () use ($cooldown, &$customerCount, &$supplierCount): void {
            $customerCount += $this->notifyCustomers($cooldown);
            $supplierCount += $this->notifySuppliers($cooldown);
        });

        $this->info("Sent {$customerCount} customer and {$supplierCount} supplier over-limit notification(s).");

        return self::SUCCESS;
    }

    private function notifyCustomers(int $cooldown): int
    {
        // Only customers with a positive credit limit can breach it.
        $customers = Customer::query()->where('is_active', true)->where('credit_limit', '>', 0)->get();
        if ($customers->isEmpty()) {
            return 0;
        }

        $recipients = User::role(['admin', 'super-admin', 'sales-manager', 'accountant'])
            ->where('is_active', true)->get();
        if ($recipients->isEmpty()) {
            return 0;
        }

        $count = 0;
        foreach ($customers as $customer) {
            $outstanding = $customer->outstandingBalance();
            if ($outstanding <= (float) $customer->credit_limit) {
                continue;
            }
            if ($this->recentlyNotified($recipients, 'customer', $customer->id, $cooldown)) {
                continue;
            }
            Notification::send($recipients, new OutstandingBalanceNotification($customer, 'customer', $outstanding));
            $count++;
        }

        return $count;
    }

    private function notifySuppliers(int $cooldown): int
    {
        $suppliers = Supplier::query()->where('is_active', true)->where('credit_limit', '>', 0)->get();
        if ($suppliers->isEmpty()) {
            return 0;
        }

        $recipients = User::role(['admin', 'super-admin', 'purchase-manager', 'accountant'])
            ->where('is_active', true)->get();
        if ($recipients->isEmpty()) {
            return 0;
        }

        $count = 0;
        foreach ($suppliers as $supplier) {
            $outstanding = $supplier->outstandingBalance();
            if ($outstanding <= (float) $supplier->credit_limit) {
                continue;
            }
            if ($this->recentlyNotified($recipients, 'supplier', $supplier->id, $cooldown)) {
                continue;
            }
            Notification::send($recipients, new OutstandingBalanceNotification($supplier, 'supplier', $outstanding));
            $count++;
        }

        return $count;
    }

    /** Skip a party already reminded within the cooldown window (per tenant). */
    private function recentlyNotified(Collection $recipients, string $kind, int $partyId, int $cooldownDays): bool
    {
        return DatabaseNotification::query()
            ->where('type', OutstandingBalanceNotification::class)
            ->whereIn('notifiable_id', $recipients->modelKeys())
            ->where('data->party_kind', $kind)
            ->where('data->party_id', $partyId)
            ->where('created_at', '>=', now()->subDays($cooldownDays))
            ->exists();
    }
}
