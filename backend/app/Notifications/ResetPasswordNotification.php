<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The password-reset message (mobile app §4).
 *
 * Carries both a link and the raw token. The link is for the web SPA; the token
 * is for the phone, which has no browser session to land in and simply asks the
 * user to type or paste it into the reset screen. One notification serving both
 * clients keeps a single expiry and a single audit trail — two separate reset
 * flows would be two ways to be wrong about which token is live.
 */
class ResetPasswordNotification extends Notification
{
    use Queueable;

    public function __construct(
        public string $token,
        public string $email,
        public int $expiresInMinutes,
        /** The mailer this workspace sends through — see MailService::mailerName(). */
        public string $mailerName,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $resetUrl = rtrim((string) config('app.frontend_url', config('app.url')), '/')
            .'/reset-password?token='.$this->token
            .'&email='.urlencode($this->email);

        return (new MailMessage)
            // Send on the workspace's own SMTP account, so the message arrives
            // from the brand the user recognises rather than the platform's.
            ->mailer($this->mailerName)
            ->subject('Reset your password · '.config('app.name'))
            ->greeting('Hello '.($notifiable->name ?? '').',')
            ->line('We received a request to reset the password for this account.')
            ->action('Reset password', $resetUrl)
            ->line('Using the mobile app? Enter this code on the reset screen instead:')
            ->line('**'.$this->token.'**')
            ->line('This link and code expire in '.$this->expiresInMinutes.' minutes.')
            ->line('If you did not request a password reset, no action is needed — your password has not changed.');
    }
}
