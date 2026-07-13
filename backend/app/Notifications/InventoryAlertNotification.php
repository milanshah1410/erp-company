<?php

namespace App\Notifications;

use App\Models\InventoryAlert;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class InventoryAlertNotification extends Notification
{
    public function __construct(private readonly InventoryAlert $alert) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => $this->alert->type === 'expiry' ? 'Expiry alert' : 'Low stock alert',
            'message' => $this->alert->message,
            'severity' => $this->alert->severity,
            'url' => '/inventory-alerts',
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $title = $this->alert->type === 'expiry' ? 'Expiry Alert' : 'Low Stock Alert';

        return (new MailMessage())
            ->subject("SIMS: {$title}")
            ->line($this->alert->message)
            ->action('View Inventory Alerts', url('/inventory-alerts'));
    }
}
