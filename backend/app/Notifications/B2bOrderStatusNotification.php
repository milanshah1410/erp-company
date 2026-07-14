<?php

namespace App\Notifications;

use App\Models\SalesOrder;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Notifies a B2B customer that their portal order was approved or rejected.
 */
class B2bOrderStatusNotification extends Notification
{
    /** @param  string  $decision  'approved' | 'rejected' */
    public function __construct(
        private readonly SalesOrder $salesOrder,
        private readonly string $decision,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => "Order {$this->decision}",
            'message' => $this->summary(),
            'severity' => $this->decision === 'approved' ? 'success' : 'error',
            'url' => '/portal/orders',
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage())
            ->subject("Your order {$this->salesOrder->so_number} was {$this->decision}")
            ->line($this->summary());

        if ($this->decision === 'rejected' && $this->salesOrder->rejected_reason) {
            $mail->line("Reason: {$this->salesOrder->rejected_reason}");
        }

        return $mail;
    }

    private function summary(): string
    {
        return $this->decision === 'approved'
            ? "Good news — order {$this->salesOrder->so_number} (₹{$this->salesOrder->total}) has been approved and is being processed."
            : "Order {$this->salesOrder->so_number} could not be approved.";
    }
}
