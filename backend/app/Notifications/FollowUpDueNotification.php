<?php

namespace App\Notifications;

use App\Models\FollowUp;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class FollowUpDueNotification extends Notification
{
    public function __construct(private readonly FollowUp $followUp) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    private function partyName(): string
    {
        return $this->followUp->followupable?->name ?? 'a contact';
    }

    private function summary(): string
    {
        $due = $this->followUp->due_at?->format('d M Y H:i');
        $what = $this->followUp->title ?: ucfirst($this->followUp->type);

        return "{$what} follow-up with {$this->partyName()} is due ({$due}).";
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => 'Follow-up due',
            'message' => $this->summary(),
            'severity' => $this->followUp->due_at?->isPast() ? 'warning' : 'info',
            'url' => '/follow-ups',
            'follow_up_id' => $this->followUp->id,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('SIMS: Follow-up due')
            ->line($this->summary())
            ->action('View Follow-ups', url('/follow-ups'));
    }
}
