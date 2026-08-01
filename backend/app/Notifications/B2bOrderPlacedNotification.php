<?php

namespace App\Notifications;

use App\Models\SalesOrder;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Alerts staff that a customer has placed a B2B portal order awaiting approval.
 */
class B2bOrderPlacedNotification extends Notification
{
    public function __construct(private readonly SalesOrder $salesOrder) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toDatabase(object $notifiable): array
    {
        $customer = $this->salesOrder->customer;

        return [
            'title' => 'New B2B order awaiting approval',
            'message' => "Order {$this->salesOrder->so_number} (₹{$this->salesOrder->total}) from {$customer?->name} needs approval.",
            'severity' => 'warning',
            'url' => '/b2b-orders',
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $customer = $this->salesOrder->customer;

        return (new MailMessage)
            ->subject("Kosha: New B2B order {$this->salesOrder->so_number} awaiting approval")
            ->line("{$customer?->name} placed order {$this->salesOrder->so_number} totalling ₹{$this->salesOrder->total} through the B2B portal.")
            ->action('Review B2B Orders', url('/b2b-orders'));
    }
}
