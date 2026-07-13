<?php

namespace App\Notifications;

use App\Models\PurchaseOrder;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PoApprovalPendingNotification extends Notification
{
    public function __construct(private readonly PurchaseOrder $purchaseOrder) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => 'Purchase order approval pending',
            'message' => "PO {$this->purchaseOrder->po_number} (₹{$this->purchaseOrder->total}) has been first-approved and needs a final approval.",
            'severity' => 'warning',
            'url' => '/purchase-orders',
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject("SIMS: PO {$this->purchaseOrder->po_number} needs final approval")
            ->line("Purchase order {$this->purchaseOrder->po_number} totalling ₹{$this->purchaseOrder->total} has cleared its first approval and exceeds the approval threshold.")
            ->line('A final approval from a different admin is required before it can proceed.')
            ->action('Review Purchase Order', url('/purchase-orders'));
    }
}
