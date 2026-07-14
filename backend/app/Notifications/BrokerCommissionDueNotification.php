<?php

namespace App\Notifications;

use App\Models\Broker;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BrokerCommissionDueNotification extends Notification
{
    public function __construct(
        private readonly Broker $broker,
        private readonly float $outstanding,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    private function summary(): string
    {
        $amount = number_format($this->outstanding, 2);

        return "₹{$amount} in commission is unsettled for broker {$this->broker->name}.";
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => 'Broker commission due',
            'message' => $this->summary(),
            'severity' => 'warning',
            'url' => '/brokers/ledger',
            'broker_id' => $this->broker->id,
            'outstanding' => round($this->outstanding, 2),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('SIMS: Broker commission due')
            ->line($this->summary())
            ->action('View Broker Ledger', url('/brokers/ledger'));
    }
}
