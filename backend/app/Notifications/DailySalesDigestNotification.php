<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Services\SalesDigestService;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The day's trading, pushed rather than waited for (retail base §8c).
 *
 * Goes to the notification bell, to email, and — where the recipient has a
 * registered handset — to their phone. Three channels for one message because
 * this is the one notification an owner reads away from the shop: the bell is
 * useless to somebody who has gone home, and email is what actually gets opened
 * on a phone at nine in the evening.
 *
 * The `push` channel is declared unconditionally and PushChannel decides whether
 * there is anything to send to; a notification that had to know about handsets
 * would have to be edited every time another one was added.
 */
class DailySalesDigestNotification extends Notification
{
    public function __construct(
        private readonly array $digest,
        private readonly string $shopName = '',
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail', 'push'];
    }

    private function headline(): string
    {
        return 'Day summary — '.$this->digest['date'];
    }

    private function summaryLine(): string
    {
        return app(SalesDigestService::class)->oneLine($this->digest);
    }

    public function toDatabase(object $notifiable): array
    {
        $s = $this->digest['sales'];

        return [
            'title' => $this->headline(),
            'message' => $this->summaryLine(),
            // Informational unless something needs doing tomorrow, in which case
            // the bell should say so rather than sitting at the same weight as
            // every other evening's summary.
            'severity' => $this->digest['attention']['low_stock'] > 0
                || $this->digest['attention']['overdue_invoices'] > 0 ? 'warning' : 'info',
            'url' => '/reports/sales',
            'digest_date' => $this->digest['date'],
            'bills' => $s['bills'],
            'net' => $s['net'],
            'collected' => $this->digest['payments']['collected'],
        ];
    }

    /** What a phone shows: one line, because that is all a lock screen holds. */
    public function toPush(object $notifiable): array
    {
        return [
            'title' => $this->headline(),
            'body' => $this->summaryLine(),
            'url' => '/reports/sales',
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $symbol = $this->digest['currency'];
        $s = $this->digest['sales'];
        $p = $this->digest['payments'];
        $money = fn ($v) => $symbol.number_format((float) $v, 2);

        $mail = (new MailMessage)
            ->subject(trim(($this->shopName ? $this->shopName.': ' : '').$this->headline()))
            ->greeting('Day summary for '.$this->digest['date'])
            ->line("**Sales** — {$s['bills']} bill(s), {$money($s['billed'])} billed, {$money($s['net'])} net of returns (average bill {$money($s['average_bill'])}).")
            ->line("**Collected** — {$money($p['collected'])}".($p['on_credit'] > 0 ? ", {$money($p['on_credit'])} left on credit." : '.'));

        if ($p['by_mode'] !== []) {
            $modes = collect($p['by_mode'])
                ->map(fn ($v, $mode) => ucfirst(str_replace('_', ' ', (string) $mode)).' '.$money($v['total']))
                ->implode(' · ');
            $mail->line("**By tender** — {$modes}");
        }

        if ($this->digest['returns']['count'] > 0) {
            $mail->line("**Returns** — {$this->digest['returns']['count']} taken back, {$money($this->digest['returns']['value'])}.");
        }

        if ($this->digest['purchases']['bills'] > 0) {
            $mail->line("**Purchases** — {$this->digest['purchases']['bills']} bill(s), {$money($this->digest['purchases']['value'])}.");
        }

        if ($this->digest['top_items'] !== []) {
            $mail->line('**What moved**');
            foreach ($this->digest['top_items'] as $item) {
                $mail->line("· {$item['name']} — ".rtrim(rtrim(number_format($item['quantity'], 3), '0'), '.')." @ {$money($item['value'])}");
            }
        }

        $attention = [];
        if ($this->digest['attention']['low_stock'] > 0) {
            $attention[] = "{$this->digest['attention']['low_stock']} item(s) at or below reorder level";
        }
        if ($this->digest['attention']['overdue_invoices'] > 0) {
            $attention[] = "{$this->digest['attention']['overdue_invoices']} invoice(s) past due";
        }
        if ($attention !== []) {
            $mail->line('**Needs attention tomorrow** — '.implode('; ', $attention).'.');
        }

        if ($this->digest['customers']['new'] > 0) {
            $mail->line("{$this->digest['customers']['new']} new customer(s) added.");
        }

        return $mail->action('Open the sales report', url('/reports/sales'));
    }
}
