<?php

namespace App\Notifications;

use App\Models\StockTransfer;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TransferReceivedNotification extends Notification
{
    public function __construct(private readonly StockTransfer $transfer) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => 'Transfer received',
            'message' => "Transfer {$this->transfer->transfer_number} has been received at the destination warehouse.",
            'severity' => 'info',
            'url' => '/stock-transfers',
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject("Kosha: Transfer {$this->transfer->transfer_number} received")
            ->line("Stock transfer {$this->transfer->transfer_number} has arrived and been received at the destination warehouse.")
            ->action('View Transfer', url('/stock-transfers'));
    }
}
