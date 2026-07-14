<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\IteratesTenants;
use App\Models\FollowUp;
use App\Models\User;
use App\Notifications\FollowUpDueNotification;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Notification;

class NotifyDueFollowUps extends Command
{
    use IteratesTenants;

    protected $signature = 'crm:notify-due-followups {--cooldown-days=1}';

    protected $description = 'Notify assignees (or sales managers) about pending follow-ups due today or overdue';

    public function handle(): int
    {
        $cooldown = (int) $this->option('cooldown-days');
        $total = 0;

        $this->eachTenant(function () use ($cooldown, &$total): void {
            // Pending follow-ups whose due time has arrived (today or earlier).
            $followUps = FollowUp::query()
                ->with('followupable:id,name')
                ->pending()
                ->where('due_at', '<=', now()->endOfDay())
                ->get();
            if ($followUps->isEmpty()) {
                return;
            }

            // Fallback recipients when a follow-up has no explicit assignee.
            $managers = User::role(['admin', 'super-admin', 'sales-manager'])
                ->where('is_active', true)->get();

            foreach ($followUps as $followUp) {
                $recipients = $this->recipientsFor($followUp, $managers);
                if ($recipients->isEmpty()) {
                    continue;
                }
                if ($this->recentlyNotified($recipients, $followUp->id, $cooldown)) {
                    continue;
                }
                Notification::send($recipients, new FollowUpDueNotification($followUp));
                $total++;
            }
        });

        $this->info("Sent {$total} follow-up reminder(s).");

        return self::SUCCESS;
    }

    /** The assigned user if active, otherwise the sales-manager fallback set. */
    private function recipientsFor(FollowUp $followUp, Collection $managers): Collection
    {
        if ($followUp->assigned_to) {
            $assignee = User::query()->where('is_active', true)->find($followUp->assigned_to);
            if ($assignee) {
                return new Collection([$assignee]);
            }
        }

        return $managers;
    }

    /** Skip a follow-up already reminded within the cooldown window (per tenant). */
    private function recentlyNotified(Collection $recipients, int $followUpId, int $cooldownDays): bool
    {
        return DatabaseNotification::query()
            ->where('type', FollowUpDueNotification::class)
            ->whereIn('notifiable_id', $recipients->modelKeys())
            ->where('data->follow_up_id', $followUpId)
            ->where('created_at', '>=', now()->subDays($cooldownDays))
            ->exists();
    }
}
