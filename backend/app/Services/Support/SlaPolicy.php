<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Models\SupportTicket;
use App\Support\TenantLimits;
use Illuminate\Support\Carbon;

/**
 * Resolves the SLA a ticket is held to, and reads the clocks back.
 *
 * The tier is decided once — when the ticket is opened — from the tenant's plan
 * (`feature_limits.support`) and then written onto the row along with both due
 * dates. Nothing recomputes them afterwards: a tenant that upgrades mid-thread
 * gets the faster clock on their *next* ticket, and an operator raising the
 * priority of a live ticket explicitly re-stamps it through `restamp()` rather
 * than having the deadline shift underneath them.
 */
final class SlaPolicy
{
    /**
     * The SLA tier that applies to a tenant, from its plan's support feature.
     */
    public static function tierFor(int $tenantId): string
    {
        $feature = TenantLimits::planFor($tenantId)?->feature_limits['support'] ?? null;

        $tier = config('support.plan_map')[$feature] ?? config('support.default_tier');

        return is_string($tier) && isset(config('support.tiers')[$tier])
            ? $tier
            : (string) config('support.default_tier');
    }

    /** Human label for a tier key. */
    public static function tierLabel(string $tier): string
    {
        return (string) (config("support.tiers.{$tier}.label") ?? ucfirst($tier));
    }

    /**
     * Response/resolution targets in hours for a (tier, priority) pair, falling
     * back to the default tier's `normal` row for anything unknown.
     *
     * @return array{response: int, resolution: int}
     */
    public static function targets(string $tier, string $priority): array
    {
        $default = config('support.default_tier');
        $targets = config("support.tiers.{$tier}.targets.{$priority}")
            ?? config("support.tiers.{$default}.targets.{$priority}")
            ?? config("support.tiers.{$default}.targets.normal");

        return [
            'response' => (int) ($targets['response'] ?? 24),
            'resolution' => (int) ($targets['resolution'] ?? 96),
        ];
    }

    /**
     * Stamp both clocks on a ticket. Both run from the moment the ticket was
     * raised, never from "now" — so re-stamping after a priority change (the
     * same call) escalates by *shortening* the remaining time rather than
     * handing support a fresh full window every time triage touches the row.
     * Does not save; the caller owns the write.
     */
    public static function stamp(SupportTicket $ticket, ?Carbon $from = null): SupportTicket
    {
        $from ??= $ticket->created_at ? Carbon::parse($ticket->created_at) : Carbon::now();
        $targets = self::targets($ticket->sla_tier ?: self::tierFor((int) $ticket->tenant_id), $ticket->priority);

        $ticket->response_due_at = (clone $from)->addHours($targets['response']);
        $ticket->resolution_due_at = (clone $from)->addHours($targets['resolution']);

        return $ticket;
    }

    /**
     * SLA state of a ticket for the queue view.
     *
     * `met` — the obligation is discharged (responded / resolved in time).
     * `breached` — a due date passed unmet.
     * `due_soon` — inside the warning window.
     * `on_track` — running, with room.
     * `none` — no clock (legacy rows opened before SLA shipped).
     *
     * @return array{state: string, response: string, resolution: string, minutes_to_due: ?int}
     */
    public static function state(SupportTicket $ticket): array
    {
        $now = Carbon::now();
        $window = (int) config('support.due_soon_hours', 2);

        $clock = function (?Carbon $due, ?Carbon $met) use ($now, $window): string {
            if (! $due) {
                return 'none';
            }
            if ($met) {
                return $met->lessThanOrEqualTo($due) ? 'met' : 'breached';
            }
            if ($now->greaterThan($due)) {
                return 'breached';
            }

            return $now->diffInMinutes($due) <= $window * 60 ? 'due_soon' : 'on_track';
        };

        // A resolved/closed ticket's resolution clock stopped when it was
        // resolved; an open one is still running against the wall clock.
        $resolvedAt = $ticket->resolved_at ?? $ticket->closed_at;

        $response = $clock($ticket->response_due_at, $ticket->first_response_at);
        $resolution = $clock($ticket->resolution_due_at, $resolvedAt);

        $rank = ['breached' => 4, 'due_soon' => 3, 'on_track' => 2, 'met' => 1, 'none' => 0];
        $worst = $rank[$response] >= $rank[$resolution] ? $response : $resolution;

        // Minutes left on whichever clock is still running and closest to due.
        $pending = collect([
            $ticket->first_response_at ? null : $ticket->response_due_at,
            $resolvedAt ? null : $ticket->resolution_due_at,
        ])->filter()->min();

        return [
            'state' => $worst,
            'response' => $response,
            'resolution' => $resolution,
            'minutes_to_due' => $pending ? (int) $now->diffInMinutes($pending, false) : null,
        ];
    }
}
