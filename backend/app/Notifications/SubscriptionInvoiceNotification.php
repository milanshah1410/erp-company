<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\SubscriptionInvoice;
use App\Models\Tenant;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The renewal/dunning emails a tenant gets about a platform invoice
 * (Volume 11 §Billing → Renewals).
 *
 * One class, three tones, because the content is the same invoice and only the
 * urgency changes:
 *
 *   issued   — "your renewal invoice is ready, due on X"
 *   reminder — "this is now N days past due"
 *   final    — "pay within the grace period or the workspace goes read-only"
 *
 * Sent to the tenant's account email via an on-demand mail route (there is no
 * central User model to notify — the recipient is a workspace, not a person).
 */
class SubscriptionInvoiceNotification extends Notification
{
    public function __construct(
        public SubscriptionInvoice $invoice,
        public Tenant $tenant,
        public string $stage = 'issued',
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $currency = $this->invoice->currency === 'INR' ? '₹' : $this->invoice->currency.' ';
        $amount = $currency.number_format((float) $this->invoice->amount, 2);
        $due = $this->invoice->due_date?->format('d M Y');
        $billingUrl = rtrim((string) config('app.frontend_url', config('app.url')), '/').'/billing';
        $days = $this->invoice->daysOverdue();

        $mail = (new MailMessage)
            ->subject($this->subject($amount))
            ->greeting('Hello '.$this->tenant->company_name.',');

        $mail = match ($this->stage) {
            'final' => $mail
                ->line("Invoice {$this->invoice->displayNumber()} for {$amount} is now {$days} days past due.")
                ->line('To avoid your workspace being switched to read-only, please settle it as soon as possible.'),
            'reminder' => $mail
                ->line("Invoice {$this->invoice->displayNumber()} for {$amount} was due on {$due} and is still outstanding.")
                ->line('If you have already paid, you can ignore this message — it can take a little while for a bank transfer to reach us.'),
            default => $mail
                ->line("Your renewal invoice {$this->invoice->displayNumber()} for {$amount} is ready.")
                ->line($due ? "It is due on {$due}." : 'It is payable now.'),
        };

        if ($this->invoice->period_start && $this->invoice->period_end) {
            $mail->line('Billing period: '.$this->invoice->period_start->format('d M Y').' – '.$this->invoice->period_end->format('d M Y').'.');
        }

        if ($this->invoice->coupon_code) {
            $mail->line('Discount applied: '.$this->invoice->coupon_code.' (−'.$currency.number_format((float) $this->invoice->discount_amount, 2).').');
        }

        return $mail
            ->action('View & pay', $billingUrl)
            ->line('Thank you for using '.config('app.name').'.');
    }

    private function subject(string $amount): string
    {
        return match ($this->stage) {
            'final' => 'Action needed: '.$amount.' overdue on your '.config('app.name').' subscription',
            'reminder' => 'Reminder: '.$amount.' due on your '.config('app.name').' subscription',
            default => 'Your '.config('app.name').' renewal invoice ('.$amount.')',
        };
    }
}
