<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\IteratesTenants;
use App\Models\Expense;
use App\Models\User;
use App\Notifications\ExpenseApprovalPendingNotification;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Notification;

class NotifyPendingExpenses extends Command
{
    use IteratesTenants;

    protected $signature = 'expenses:notify-pending-approval';

    protected $description = 'Notify approvers about expenses awaiting approval';

    public function handle(): int
    {
        $total = 0;

        $this->eachTenant(function () use (&$total): void {
            $expenses = Expense::query()
                ->with('category:id,name')
                ->where('status', 'pending')
                ->get();

            if ($expenses->isEmpty()) {
                return;
            }

            $recipients = User::role(['admin', 'super-admin', 'accountant'])
                ->where('is_active', true)->get();
            if ($recipients->isEmpty()) {
                return;
            }

            foreach ($expenses as $expense) {
                if ($this->alreadyNotified($recipients, $expense->id)) {
                    continue;
                }
                Notification::send($recipients, new ExpenseApprovalPendingNotification($expense));
                $total++;
            }
        });

        $this->info("Sent {$total} expense-approval notification(s).");

        return self::SUCCESS;
    }

    /** Notify a given expense once (scoped to this tenant's recipients). */
    private function alreadyNotified(Collection $recipients, int $expenseId): bool
    {
        return DatabaseNotification::query()
            ->where('type', ExpenseApprovalPendingNotification::class)
            ->whereIn('notifiable_id', $recipients->modelKeys())
            ->where('data->expense_id', $expenseId)
            ->exists();
    }
}
