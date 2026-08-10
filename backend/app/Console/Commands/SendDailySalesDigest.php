<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\IteratesTenants;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\DailySalesDigestNotification;
use App\Services\SalesDigestService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Notification;

/**
 * The daily sales digest (retail base §8c) — the scheduled summary push that was
 * the last gap in the notifications list.
 *
 * Runs at 21:30, covering *today*, not yesterday. That is the decision worth
 * defending: an Indian shop closes between eight and nine, and the message its
 * owner wants is "here is what today came to" read on the way home — not
 * yesterday's figures arriving with tomorrow's breakfast, by which time the day
 * has already been asked about over the phone.
 *
 * Two guards keep it from becoming noise:
 *
 *  - Opt-in per workspace (`sales_digest_enabled`). Off by default, because a
 *    workspace that never asked for an evening email should not start getting one.
 *  - Silent on a dead day. A shop that was shut sends nothing rather than a
 *    digest reading "0 bills" — the fastest way to train somebody to ignore a
 *    channel is to use it to say nothing.
 */
class SendDailySalesDigest extends Command
{
    use IteratesTenants;

    protected $signature = 'sales:daily-digest
        {--date= : The trading day to summarise (defaults to today)}
        {--tenant= : Only this tenant id}
        {--force : Send even when the workspace has the digest switched off, or the day was empty}';

    protected $description = "Send each workspace's owners a summary of the day's trading";

    public function __construct(private readonly SalesDigestService $digests)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $date = $this->option('date') ?: now()->toDateString();
        $only = $this->option('tenant') ? (int) $this->option('tenant') : null;
        $force = (bool) $this->option('force');

        $sent = 0;
        $skipped = 0;

        $this->eachTenant(function (Tenant $tenant) use ($date, $only, $force, &$sent, &$skipped): void {
            if ($only !== null && $tenant->id !== $only) {
                return;
            }
            if (! $force && Setting::get('sales_digest_enabled', '0') !== '1') {
                $skipped++;

                return;
            }

            $digest = $this->digests->forDate($date);

            if (! $force && ! $this->digests->isWorthSending($digest)) {
                $skipped++;

                return;
            }

            $recipients = $this->recipients();
            if ($recipients->isEmpty()) {
                $skipped++;

                return;
            }

            // One digest per workspace per day, whatever else re-runs the command.
            if (! $force && $this->alreadySent($date, $recipients)) {
                $skipped++;

                return;
            }

            Notification::send($recipients, new DailySalesDigestNotification($digest, (string) $tenant->company_name));
            $sent++;
            $this->line("  {$tenant->company_name}: {$this->digests->oneLine($digest)}");
        });

        $this->info("Daily digest: {$sent} workspace(s) notified, {$skipped} skipped.");

        return self::SUCCESS;
    }

    /**
     * Who gets it. Deliberately narrow: the digest is a whole-shop figure set,
     * including margin-adjacent numbers and what is owed, and that is not a
     * counter hand's business. A workspace that wants somebody else on it gives
     * them one of these roles.
     */
    private function recipients()
    {
        return User::role(['admin', 'super-admin', 'accountant', 'sales-manager'])
            ->where('is_active', true)
            ->get();
    }

    /**
     * Has this workspace already had today's digest?
     *
     * Scoped by recipient rather than by tenant: `notifications` carries no
     * tenant_id, so the only tenant-safe filter is the set of user ids that
     * belong to this workspace — the same approach NotifyOutstandingBalances
     * takes. Without it, the first workspace to receive a digest would suppress
     * everybody else's.
     */
    private function alreadySent(string $date, Collection $recipients): bool
    {
        return DatabaseNotification::query()
            ->where('type', DailySalesDigestNotification::class)
            ->whereIn('notifiable_id', $recipients->modelKeys())
            ->where('data->digest_date', $date)
            ->exists();
    }
}
