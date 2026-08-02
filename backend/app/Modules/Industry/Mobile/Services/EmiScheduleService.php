<?php

declare(strict_types=1);

namespace App\Modules\Industry\Mobile\Services;

use App\Models\Invoice;
use App\Models\Payment;
use App\Modules\Industry\Mobile\Models\EmiInstalment;
use App\Modules\Industry\Mobile\Models\EmiPlan;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Builds an EMI schedule and collects against it.
 *
 * Two things in here are load-bearing.
 *
 * **Rounding lands on the last instalment.** ₹31,999 over nine months is ₹3,555.44
 * recurring; nine equal rows would leave paise unaccounted for and a file that can
 * never close. So eight rows carry the rounded figure and the ninth carries
 * whatever is left, which is also how every financier in this trade writes the
 * schedule.
 *
 * **Collecting only moves money on an in-house file.** On a finance-company file
 * the shop was paid in full at disbursal; the instalments are the customer's
 * business with the bank, tracked here only so the counter can answer the question.
 * Writing a payment for one would bank the same money twice and put the cash book
 * out by the price of a phone.
 */
class EmiScheduleService
{
    /**
     * Write (or rewrite) the schedule for a plan.
     *
     * Refuses once anything has been collected: rebuilding under paid instalments
     * would either orphan the receipts or silently re-open settled money. A file
     * that needs different terms after a collection is a new file, and the old one
     * is cancelled.
     *
     * @return list<EmiInstalment>
     */
    public function build(EmiPlan $plan): array
    {
        if ($plan->instalments()->whereIn('status', ['paid', 'waived'])->exists()) {
            throw new RuntimeException(
                'Instalments have already been settled on this file, so its schedule can no longer be rebuilt. '
                .'Cancel it and open a fresh file instead.',
            );
        }

        $tenure = max(1, (int) $plan->tenure_months);
        $total = round((float) $plan->financed_amount, 2);

        // The regular instalment, floored to paise. Whatever the rounding loses is
        // added back on the final row below.
        $regular = floor(($total / $tenure) * 100) / 100;

        return DB::transaction(function () use ($plan, $tenure, $total, $regular) {
            $plan->instalments()->delete();

            $first = Carbon::parse($plan->first_due_date);
            $rows = [];

            for ($i = 1; $i <= $tenure; $i++) {
                $amount = $i === $tenure
                    ? round($total - ($regular * ($tenure - 1)), 2)
                    : $regular;

                $rows[] = $plan->instalments()->create([
                    'sequence' => $i,
                    /**
                     * addMonthsNoOverflow: a file dated the 31st must not skip
                     * February into March, which would put two instalments in one
                     * month and none in the other.
                     */
                    'due_date' => $first->copy()->addMonthsNoOverflow($i - 1)->toDateString(),
                    'amount' => $amount,
                ]);
            }

            // The stated instalment is the regular one, not the odd last row — that
            // is the figure the customer was quoted.
            $plan->forceFill(['instalment_amount' => $regular])->save();

            return $rows;
        });
    }

    /**
     * Record a collection against one instalment.
     *
     * @param  string  $mode  how the money arrived, for in-house files
     */
    public function collect(
        EmiInstalment $instalment,
        string $mode = 'cash',
        ?string $paidOn = null,
        ?int $userId = null,
        ?string $notes = null,
    ): EmiInstalment {
        if ($instalment->status !== 'pending') {
            throw new RuntimeException('This instalment has already been settled.');
        }

        $plan = $instalment->plan;

        if (! $plan || $plan->status === 'cancelled') {
            throw new RuntimeException('This finance file is cancelled — nothing can be collected against it.');
        }

        $paidOn ??= now()->toDateString();

        return DB::transaction(function () use ($instalment, $plan, $mode, $paidOn, $userId, $notes) {
            $payment = null;

            /**
             * Only an in-house file produces a receipt. And even then only against a
             * real invoice: a file opened before the sale was billed has nothing to
             * post to, and inventing a payment with no invoice behind it would
             * strand money outside the receivables ledger.
             */
            if ($plan->isReceivable() && $plan->invoice_id) {
                $payment = $this->postPayment($plan, $instalment, $mode, $paidOn, $userId);
            }

            $instalment->forceFill([
                'status' => 'paid',
                'paid_on' => $paidOn,
                'paid_amount' => (float) $instalment->amount,
                'payment_id' => $payment?->id,
                'notes' => $notes ?? $instalment->notes,
            ])->save();

            $plan->closeIfSettled();

            return $instalment->fresh(['payment']);
        });
    }

    /**
     * Write one instalment off — a subvention the brand absorbed, a goodwill waiver,
     * or a correction. Deliberately separate from collect(): no money moved, and the
     * register should not claim any did.
     */
    public function waive(EmiInstalment $instalment, ?string $reason = null): EmiInstalment
    {
        if ($instalment->status !== 'pending') {
            throw new RuntimeException('This instalment has already been settled.');
        }

        $instalment->forceFill([
            'status' => 'waived',
            'paid_on' => now()->toDateString(),
            'paid_amount' => 0,
            'notes' => trim((string) $instalment->notes.' Waived: '.($reason ?? '—')),
        ])->save();

        $instalment->plan?->closeIfSettled();

        return $instalment;
    }

    /**
     * A payment against the sale invoice, capped at what is still owed on it.
     *
     * The cap is not paranoia: the invoice may also have taken a down payment, a
     * trade-in credit and a card tender at the counter, and the schedule knows
     * nothing about any of them. Overshooting would show a customer as having
     * overpaid a bill they are halfway through.
     */
    private function postPayment(
        EmiPlan $plan,
        EmiInstalment $instalment,
        string $mode,
        string $paidOn,
        ?int $userId,
    ): ?Payment {
        $invoice = Invoice::lockForUpdate()->find($plan->invoice_id);

        if (! $invoice) {
            return null;
        }

        $balance = round(
            (float) $invoice->total - (float) $invoice->paid_amount - (float) $invoice->credited_amount,
            2,
        );

        $amount = min((float) $instalment->amount, max(0, $balance));

        if ($amount <= 0) {
            return null;
        }

        $payment = Payment::create([
            'invoice_id' => $invoice->id,
            /**
             * The tender enum holds no 'emi' mode on purpose: an instalment arrives
             * as cash, a card swipe or a UPI transfer like any other collection, and
             * it is the finance file that records *why*.
             */
            'mode' => in_array($mode, ['cash', 'card', 'upi'], true) ? $mode : 'cash',
            'amount' => $amount,
            'payment_date' => $paidOn,
            'notes' => "EMI {$instalment->sequence}/{$plan->tenure_months} on file {$plan->plan_number}",
            'created_by' => $userId,
        ]);

        $invoice->forceFill([
            'paid_amount' => round((float) $invoice->paid_amount + $amount, 2),
        ])->save();
        $invoice->refreshStatus();

        return $payment;
    }
}
