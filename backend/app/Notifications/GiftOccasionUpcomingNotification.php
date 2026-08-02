<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Modules\Industry\Gift\Models\GiftOccasion;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "Mr Shah's wife's birthday is in seven days" — the counter's cue to ring a
 * customer before someone else's shop does.
 *
 * Distinct from CustomerOccasionNotification, which fires on the *day* of a
 * customer's own birthday and is right for every other trade. This one fires at
 * the occasion's own lead time, names the person the gift is for, and carries the
 * two facts that make the call worth making: last year's gift and this year's
 * budget.
 */
class GiftOccasionUpcomingNotification extends Notification
{
    public function __construct(
        private readonly GiftOccasion $occasion,
        private readonly int $daysUntil,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    private function summary(): string
    {
        $when = match (true) {
            $this->daysUntil <= 0 => 'is today',
            $this->daysUntil === 1 => 'is tomorrow',
            default => "is in {$this->daysUntil} days",
        };

        $line = $this->occasion->personLabel().'’s '.strtolower($this->occasion->occasionLabel())
            .' '.$when.' — customer: '.($this->occasion->customer?->name ?? 'unknown').'.';

        if ($this->occasion->last_gift) {
            $line .= ' Last time: '.$this->occasion->last_gift.'.';
        }

        if ($this->occasion->budget !== null) {
            $line .= ' Budget around '.number_format((float) $this->occasion->budget, 2).'.';
        }

        return $line;
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => $this->occasion->occasionLabel().' coming up',
            'message' => $this->summary(),
            'severity' => $this->daysUntil <= 1 ? 'warning' : 'info',
            'url' => '/gift/occasions',
            'occasion_id' => $this->occasion->id,
            'customer_id' => $this->occasion->customer_id,
            'days_until' => $this->daysUntil,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Gift occasion coming up: '.$this->occasion->occasionLabel())
            ->line($this->summary())
            ->action('Open Occasions', url('/gift/occasions'));
    }
}
