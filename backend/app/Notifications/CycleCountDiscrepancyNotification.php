<?php

namespace App\Notifications;

use App\Models\CycleCount;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CycleCountDiscrepancyNotification extends Notification
{
    public function __construct(
        private readonly CycleCount $cycleCount,
        private readonly int $itemsWithVariance,
        private readonly float $varianceValue,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => 'Stock discrepancy after cycle count',
            'message' => "Cycle count {$this->cycleCount->count_number} found {$this->itemsWithVariance} item(s) with a variance (₹{$this->varianceValue}).",
            'severity' => 'warning',
            'url' => '/cycle-counts',
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject("SIMS: Discrepancy in cycle count {$this->cycleCount->count_number}")
            ->line("Cycle count {$this->cycleCount->count_number} was completed with {$this->itemsWithVariance} item(s) showing a variance, valued at ₹{$this->varianceValue}.")
            ->action('Review Cycle Count', url('/cycle-counts'));
    }
}
