<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Models\PurchaseInvoice;
use App\Models\User;
use App\Notifications\PaymentDueNotification;
use Illuminate\Console\Command;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Notification;

class NotifyDueInvoices extends Command
{
    protected $signature = 'notifications:check-due-invoices {--warning-days=3}';

    protected $description = 'Notify relevant roles about purchase bills and sales invoices that are due soon or overdue';

    public function handle(): int
    {
        $warningDays = (int) $this->option('warning-days');
        $horizon = now()->addDays($warningDays)->toDateString();

        $purchaseCount = $this->notifyPurchaseInvoices($horizon);
        $salesCount = $this->notifySalesInvoices($horizon);

        $this->info("Notified for {$purchaseCount} purchase bill(s) and {$salesCount} sales invoice(s) due.");

        return self::SUCCESS;
    }

    private function notifyPurchaseInvoices(string $horizon): int
    {
        $invoices = PurchaseInvoice::query()
            ->with('supplier')
            ->where('status', 'approved')
            ->whereNotNull('due_date')
            ->where('due_date', '<=', $horizon)
            ->get();

        if ($invoices->isEmpty()) {
            return 0;
        }

        $recipients = User::role(['admin', 'super-admin', 'purchase-manager', 'accountant'])->get();
        if ($recipients->isEmpty()) {
            return 0;
        }

        $notified = 0;
        foreach ($invoices as $invoice) {
            if ($this->alreadyNotified('purchase', $invoice->id)) {
                continue;
            }
            Notification::send($recipients, new PaymentDueNotification($invoice, 'purchase'));
            $notified++;
        }

        return $notified;
    }

    private function notifySalesInvoices(string $horizon): int
    {
        $invoices = Invoice::query()
            ->with('customer')
            ->whereIn('status', ['unpaid', 'partial'])
            ->whereNotNull('due_date')
            ->where('due_date', '<=', $horizon)
            ->get();

        if ($invoices->isEmpty()) {
            return 0;
        }

        $recipients = User::role(['admin', 'super-admin', 'sales-manager', 'accountant'])->get();
        if ($recipients->isEmpty()) {
            return 0;
        }

        $notified = 0;
        foreach ($invoices as $invoice) {
            if ($this->alreadyNotified('sales', $invoice->id)) {
                continue;
            }
            Notification::send($recipients, new PaymentDueNotification($invoice, 'sales'));
            $notified++;
        }

        return $notified;
    }

    /** Avoid re-notifying every day for the same still-due invoice. */
    private function alreadyNotified(string $kind, int $invoiceId): bool
    {
        return DatabaseNotification::query()
            ->where('type', PaymentDueNotification::class)
            ->where('data->invoice_kind', $kind)
            ->where('data->invoice_id', $invoiceId)
            ->exists();
    }
}
