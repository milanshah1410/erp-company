<?php

namespace App\Notifications;

use App\Models\Customer;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CustomerOccasionNotification extends Notification
{
    public function __construct(
        private readonly Customer $customer,
        private readonly string $occasion, // birthday | anniversary
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    private function summary(): string
    {
        $label = $this->occasion === 'anniversary' ? 'anniversary' : 'birthday';

        return "Today is {$this->customer->name}'s {$label}. Send them a wish!";
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => ucfirst($this->occasion).' today',
            'message' => $this->summary(),
            'severity' => 'info',
            'url' => '/crm/occasions',
            'customer_id' => $this->customer->id,
            'occasion' => $this->occasion,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Kosha: Customer '.$this->occasion.' today')
            ->line($this->summary())
            ->action('Open Occasions', url('/crm/occasions'));
    }
}
