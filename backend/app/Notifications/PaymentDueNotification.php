<?php

namespace App\Notifications;

use App\Models\Invoice;
use App\Models\PurchaseInvoice;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PaymentDueNotification extends Notification
{
    /** @param PurchaseInvoice|Invoice $invoice */
    public function __construct(
        private readonly PurchaseInvoice|Invoice $invoice,
        private readonly string $kind, // 'purchase' | 'sales'
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    private function isOverdue(): bool
    {
        return $this->invoice->due_date->isPast();
    }

    private function summary(): string
    {
        $number = $this->invoice->invoice_number;
        $dueDate = $this->invoice->due_date->toDateString();
        $amount = $this->kind === 'purchase' ? $this->invoice->total : $this->invoice->balanceDue();
        $verb = $this->kind === 'purchase' ? 'Bill from' : 'Payment due from';
        $who = $this->kind === 'purchase'
            ? ($this->invoice->supplier->name ?? 'supplier')
            : ($this->invoice->customer->name ?? 'customer');

        $status = $this->isOverdue() ? 'overdue' : 'due soon';

        return "{$verb} {$who}: invoice {$number} for ₹{$amount} is {$status} ({$dueDate}).";
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => $this->kind === 'purchase' ? 'Purchase bill due' : 'Payment due',
            'message' => $this->summary(),
            'severity' => $this->isOverdue() ? 'critical' : 'warning',
            'url' => $this->kind === 'purchase' ? '/purchase-invoices' : '/sales-orders',
            'invoice_kind' => $this->kind,
            'invoice_id' => $this->invoice->id,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject('Kosha: '.($this->kind === 'purchase' ? 'Purchase bill due' : 'Payment due'))
            ->line($this->summary())
            ->action('View Invoice', url($this->kind === 'purchase' ? '/purchase-invoices' : '/sales-orders'));
    }
}
