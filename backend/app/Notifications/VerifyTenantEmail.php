<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\PendingRegistration;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class VerifyTenantEmail extends Notification
{
    use Queueable;

    public function __construct(
        public PendingRegistration $registration,
        public string $code,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $verifyUrl = rtrim((string) config('app.frontend_url', config('app.url')), '/')
            .'/verify?registration='.$this->registration->id
            .'&token='.$this->registration->verification_token;

        return (new MailMessage)
            ->subject('Verify your email · '.config('app.name'))
            ->greeting('Welcome, '.$this->registration->admin_name.'!')
            ->line('Your verification code is:')
            ->line('**'.$this->code.'**')
            ->line('Enter it on the signup screen to finish creating **'.$this->registration->company_name.'**.')
            ->action('Or click to verify', $verifyUrl)
            ->line('This code confirms '.$this->registration->email.'. If you didn’t sign up, you can ignore this email.');
    }
}
