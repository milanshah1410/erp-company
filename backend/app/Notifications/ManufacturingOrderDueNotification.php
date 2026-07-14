<?php

namespace App\Notifications;

use App\Models\ManufacturingOrder;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ManufacturingOrderDueNotification extends Notification
{
    public function __construct(private readonly ManufacturingOrder $order) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    private function isOverdue(): bool
    {
        return $this->order->expected_completion_date?->isPast() ?? false;
    }

    private function summary(): string
    {
        $number = $this->order->mo_number;
        $product = $this->order->product->name ?? 'product';
        $qty = rtrim(rtrim((string) $this->order->quantity_to_produce, '0'), '.');
        $date = $this->order->expected_completion_date?->toDateString();
        $status = str_replace('_', ' ', (string) $this->order->status);
        $when = $this->isOverdue() ? "was due {$date}" : "is due {$date}";

        return "MO {$number} ({$qty} × {$product}) {$when} and is still {$status}.";
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => $this->isOverdue() ? 'Manufacturing order overdue' : 'Manufacturing order due soon',
            'message' => $this->summary(),
            'severity' => $this->isOverdue() ? 'critical' : 'warning',
            'url' => '/manufacturing-orders',
            'mo_id' => $this->order->id,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('SIMS: Manufacturing order '.($this->isOverdue() ? 'overdue' : 'due soon'))
            ->line($this->summary())
            ->action('View Manufacturing Orders', url('/manufacturing-orders'));
    }
}
