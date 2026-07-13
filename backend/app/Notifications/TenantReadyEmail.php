<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TenantReadyEmail extends Notification
{
    use Queueable;

    public function __construct(
        public string $companyName,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = (string) config('app.frontend_url', config('app.url'));

        return (new MailMessage)
            ->subject('Your '.config('app.name').' account is ready 🎉')
            ->greeting('You’re all set!')
            ->line($this->companyName.' is provisioned and ready to use.')
            ->action('Log in', $url)
            ->line('You’re on a '.config('saas.trial_days', 14).'-day free trial. No card required.');
    }
}
