<?php

namespace App\Notifications;

use App\Models\Customer;
use App\Models\Supplier;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A party (customer receivable or supplier payable) whose outstanding balance
 * has crossed their credit limit. One class, two kinds, mirroring the shape of
 * PaymentDueNotification.
 */
class OutstandingBalanceNotification extends Notification
{
    public function __construct(
        private readonly Customer|Supplier $party,
        private readonly string $kind, // 'customer' | 'supplier'
        private readonly float $outstanding,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    private function summary(): string
    {
        $amount = number_format($this->outstanding, 2);
        $limit = number_format((float) $this->party->credit_limit, 2);

        return $this->kind === 'customer'
            ? "{$this->party->name} owes ₹{$amount}, over the ₹{$limit} credit limit."
            : "Payable to {$this->party->name} is ₹{$amount}, over the ₹{$limit} credit limit.";
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => $this->kind === 'customer' ? 'Customer over credit limit' : 'Supplier over credit limit',
            'message' => $this->summary(),
            'severity' => 'critical',
            'url' => $this->kind === 'customer' ? '/customers' : '/suppliers',
            'party_kind' => $this->kind,
            'party_id' => $this->party->id,
            'outstanding' => round($this->outstanding, 2),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $title = $this->kind === 'customer' ? 'Customer over credit limit' : 'Supplier over credit limit';

        return (new MailMessage)
            ->subject("SIMS: {$title}")
            ->line($this->summary())
            ->action($this->kind === 'customer' ? 'View Customers' : 'View Suppliers',
                url($this->kind === 'customer' ? '/customers' : '/suppliers'));
    }
}
