<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Payment;
use App\Models\PosCounter;
use App\Models\PosCounterSession;
use App\Models\SalesOrder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Opening, working and cashing up a till.
 *
 * The one piece of arithmetic worth explaining is what "expected cash" means,
 * because getting it wrong is how a shop ends up chasing a shortfall that never
 * happened:
 *
 *   expected = opening float + every *cash* payment stamped with this session
 *
 * Cash only. A card sale never enters the drawer, so counting it would make
 * every shift look short by its card takings. Refunds need no special handling
 * because a refund is stored as a negative payment row — money leaving the
 * drawer is money leaving the sum. And the stamp is on the payment rather than
 * on the sale for the reason set out in the migration: cash refunded today
 * against last Tuesday's bill comes out of today's drawer.
 *
 * Non-cash tenders are still totalled, but for the Z report rather than for the
 * variance: a supervisor comparing the card machine's own end-of-day against the
 * shift needs the figure, and it can never be part of what is counted by hand.
 */
class PosCounterService
{
    /**
     * Open a shift.
     *
     * The float defaults to the counter's configured one but is overridable,
     * because what actually went in the drawer is what the shift must reconcile
     * against — not what somebody configured last year.
     */
    public function open(PosCounter $counter, ?int $userId, ?float $openingFloat = null, ?string $notes = null): PosCounterSession
    {
        if (! $counter->is_active) {
            throw new RuntimeException("Counter {$counter->name} is not in service.");
        }

        if ($counter->sessions()->where('status', 'open')->exists()) {
            throw new RuntimeException("Counter {$counter->name} already has an open session. Close it before opening another.");
        }

        return PosCounterSession::create([
            'counter_id' => $counter->id,
            'status' => 'open',
            'opened_by' => $userId,
            'opened_at' => now(),
            'opening_float' => round($openingFloat ?? (float) $counter->default_float, 2),
            'notes' => $notes,
        ]);
    }

    /**
     * Cash up and close.
     *
     * `declared_cash` is what the cashier physically counted. Everything else is
     * computed here and frozen onto the row — see PosCounterSession for why a Z
     * report must not re-derive itself.
     *
     * A variance is *recorded*, never refused. A till that will not close until
     * it balances is a till that gets closed by making the count agree, which is
     * exactly the behaviour the report exists to catch.
     */
    public function close(PosCounterSession $session, ?int $userId, float $declaredCash, ?string $notes = null): PosCounterSession
    {
        if (! $session->isOpen()) {
            throw new RuntimeException('This session is already closed.');
        }

        return DB::transaction(function () use ($session, $userId, $declaredCash, $notes) {
            $expected = $this->expectedCash($session);
            $declared = round($declaredCash, 2);

            $session->forceFill([
                'status' => 'closed',
                'closed_by' => $userId,
                'closed_at' => now(),
                'declared_cash' => $declared,
                'expected_cash' => $expected,
                'variance' => round($declared - $expected, 2),
                'tender_totals' => $this->tenderTotals($session),
                'notes' => $notes ?: $session->notes,
            ])->save();

            return $session->fresh(['counter', 'openedBy', 'closedBy']);
        });
    }

    /**
     * What should be in the drawer right now: the float plus net cash taken.
     *
     * Live, so the mid-shift X report and the close both read the same number
     * from the same place and cannot disagree.
     */
    public function expectedCash(PosCounterSession $session): float
    {
        $cash = (float) Payment::query()
            ->where('pos_session_id', $session->id)
            ->where('mode', 'cash')
            ->sum('amount');

        return round((float) $session->opening_float + $cash, 2);
    }

    /**
     * Money through the drawer by tender, refunds netted off.
     *
     * Keyed by mode and derived from whatever is actually there, so a tender
     * added to the enum later (this is already the ninth) shows up without
     * anything here changing.
     *
     * @return array<string, float>
     */
    public function tenderTotals(PosCounterSession $session): array
    {
        return Payment::query()
            ->where('pos_session_id', $session->id)
            ->groupBy('mode')
            ->selectRaw('mode, SUM(amount) as total')
            ->pluck('total', 'mode')
            ->map(static fn ($total) => round((float) $total, 2))
            ->all();
    }

    /**
     * The shift report.
     *
     * One method for both X and Z: they are the same figures, and the only
     * difference is that an X is taken mid-shift and settles nothing. A closed
     * session reads its frozen snapshot; an open one is computed live.
     *
     * @return array<string, mixed>
     */
    public function report(PosCounterSession $session): array
    {
        $closed = ! $session->isOpen();

        $expected = $closed ? (float) $session->expected_cash : $this->expectedCash($session);
        $tenders = $closed ? ($session->tender_totals ?? []) : $this->tenderTotals($session);

        $sales = SalesOrder::query()
            ->where('pos_session_id', $session->id)
            ->selectRaw('COUNT(*) as bills, COALESCE(SUM(total), 0) as total')
            ->first();

        $bills = (int) ($sales->bills ?? 0);
        $salesTotal = round((float) ($sales->total ?? 0), 2);

        return [
            'type' => $closed ? 'z' : 'x',
            'session_id' => $session->id,
            'counter' => [
                'id' => $session->counter?->id,
                'code' => $session->counter?->code,
                'name' => $session->counter?->name,
                'mode' => $session->counter?->mode,
            ],
            'status' => $session->status,
            'opened_at' => $session->opened_at?->toDateTimeString(),
            'opened_by' => $session->openedBy?->name,
            'closed_at' => $session->closed_at?->toDateTimeString(),
            'closed_by' => $session->closedBy?->name,
            'opening_float' => (float) $session->opening_float,
            'bills' => $bills,
            'sales_total' => $salesTotal,
            // The average basket. A supermarket's single most-watched counter
            // number, and cheap to give here rather than making every caller
            // divide two figures it already has.
            'average_bill' => $bills > 0 ? round($salesTotal / $bills, 2) : 0.0,
            'tenders' => $tenders,
            'cash_taken' => round((float) ($tenders['cash'] ?? 0), 2),
            'expected_cash' => $expected,
            'declared_cash' => $closed ? (float) $session->declared_cash : null,
            'variance' => $closed ? (float) $session->variance : null,
            'notes' => $session->notes,
        ];
    }

    /**
     * Resolve the open session a till is billing against.
     *
     * Called from PosController when a sale names a counter. It refuses rather
     * than silently opening one: a drawer that opens itself on the first sale
     * has no float behind it, so its first cash-up is guaranteed to be over by
     * whatever was already in it.
     */
    public function requireOpenSession(PosCounter $counter): PosCounterSession
    {
        $session = $counter->sessions()->where('status', 'open')->first();

        if (! $session) {
            throw new RuntimeException("Counter {$counter->name} has no open session. Open the till before billing.");
        }

        return $session;
    }
}
