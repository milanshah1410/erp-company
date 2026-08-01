<?php

namespace App\Notifications;

use App\Models\Expense;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ExpenseApprovalPendingNotification extends Notification
{
    public function __construct(private readonly Expense $expense) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    private function summary(): string
    {
        $number = $this->expense->expense_number;
        $amount = number_format((float) $this->expense->amount, 2);
        $category = $this->expense->category->name ?? 'uncategorised';

        return "Expense {$number} for ₹{$amount} ({$category}) is awaiting approval.";
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => 'Expense approval pending',
            'message' => $this->summary(),
            'severity' => 'warning',
            'url' => '/expenses',
            'expense_id' => $this->expense->id,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Kosha: Expense approval pending')
            ->line($this->summary())
            ->action('Review Expenses', url('/expenses'));
    }
}
