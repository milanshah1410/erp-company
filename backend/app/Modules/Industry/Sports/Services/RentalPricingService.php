<?php

declare(strict_types=1);

namespace App\Modules\Industry\Sports\Services;

use App\Modules\Industry\Sports\Models\Rental;
use App\Modules\Industry\Sports\Models\RentalItem;
use Illuminate\Support\Carbon;

/**
 * The arithmetic of a hire: how many units, at what rate, and what the overrun
 * costs.
 *
 * Pure and stateless on purpose. Every number a hire counter argues about is
 * computed here and nowhere else, so the quote given on Friday, the figure on the
 * overdue board on Monday and the line on the invoice cannot disagree — which is
 * exactly what happens when a shop quotes from a rate card, chases from a diary
 * and bills from a till.
 *
 * Three rules, each of them a decision rather than a formula:
 *
 * **Part of a unit is a whole unit.** Six hours of a daily hire is a day; eight
 * days of a weekly hire is two weeks. This is how every hire counter on earth
 * quotes, and pro-rating instead would produce bills nobody recognises — and
 * would let a customer keep a bike for six days on a weekly rate.
 *
 * **The hire is charged on the promise, not on the return.** The billable period
 * runs from issue to the *agreed* due time, so bringing a weekend bike back on
 * Saturday night does not earn a refund: the shop turned other customers away for
 * Sunday. Time past the due point is not more hire — it is late, and it is priced
 * as late.
 *
 * **Lateness is measured from the grace, not from the due time.** Twenty minutes
 * over on a daily hire is the single commonest argument at a hire counter, and a
 * shop that has agreed two hours of grace has already settled it.
 */
class RentalPricingService
{
    /**
     * Billable units between two instants, rounded up, never less than one.
     *
     * The floor of one is what makes "I only had it for ten minutes" cost a day
     * on a daily rate — deliberate, and the reason the hourly basis exists for
     * shops that want to charge that honestly instead.
     */
    public function units(string $basis, Carbon $from, Carbon $to): float
    {
        $seconds = max(0, $from->diffInSeconds($to, false));
        $perUnit = Rental::BASIS_SECONDS[$basis] ?? Rental::BASIS_SECONDS['daily'];

        return (float) max(1, (int) ceil($seconds / $perUnit));
    }

    /**
     * Units past the promise, allowing for grace. Zero — not one — when the kit
     * came back in time: the minimum-one-unit rule belongs to the hire, and
     * applying it here would invent a late fee for every punctual return.
     */
    public function lateUnits(string $basis, Carbon $graceUntil, Carbon $returnedAt): float
    {
        $seconds = $graceUntil->diffInSeconds($returnedAt, false);

        if ($seconds <= 0) {
            return 0.0;
        }

        $perUnit = Rental::BASIS_SECONDS[$basis] ?? Rental::BASIS_SECONDS['daily'];

        return (float) (int) ceil($seconds / $perUnit);
    }

    /**
     * Price one line as at a given moment — the return, or now for a live quote.
     *
     * Returns the four numbers a counter reads out, and writes none of them: what
     * a line is *worth* on a Monday morning is a question the overdue board asks
     * about kit that has not come back yet, and a pricing call that saved its
     * answer would silently freeze a hire that is still running.
     *
     * @return array{hire_units: float, hire_charge: float, late_units: float, late_charge: float, total: float}
     */
    public function priceLine(Rental $rental, RentalItem $item, ?Carbon $at = null): array
    {
        $at ??= now();

        $from = $rental->chargeFrom();
        $due = $rental->due_at;
        $rate = (float) $item->rate;

        // The agreed period, whatever actually happened afterwards.
        $hireUnits = $this->units($rental->rate_basis, $from, $due);
        $hireCharge = round($hireUnits * $rate, 2);

        $lateUnits = $this->lateUnits($rental->rate_basis, $rental->graceUntil(), $at);
        $lateCharge = round($lateUnits * $item->lateRate(), 2);

        return [
            'hire_units' => $hireUnits,
            'hire_charge' => $hireCharge,
            'late_units' => $lateUnits,
            'late_charge' => $lateCharge,
            'total' => round($hireCharge + $lateCharge, 2),
        ];
    }

    /**
     * What a contract would cost if every outstanding line came back right now.
     *
     * The number the counter needs while the customer is still standing there —
     * "so what do I owe?" — and the number the overdue board shows growing. Lines
     * already back keep what they were charged; only the ones still out are priced
     * to the moment.
     *
     * @return array{hire: float, late: float, damage: float, subtotal: float, deposit_balance: float, due_from_customer: float}
     */
    public function quote(Rental $rental, ?Carbon $at = null): array
    {
        $at ??= now();
        $rental->loadMissing('items');

        $hire = 0.0;
        $late = 0.0;
        $damage = 0.0;

        foreach ($rental->items as $item) {
            if ($item->isReturned()) {
                $hire += (float) $item->hire_charge;
                $late += (float) $item->late_charge;
                $damage += (float) $item->damage_charge;

                continue;
            }

            $priced = $this->priceLine($rental, $item, $at);
            $hire += $priced['hire_charge'];
            $late += $priced['late_charge'];
        }

        $subtotal = round($hire + $late + $damage, 2);
        $deposit = (float) $rental->deposit_amount;

        return [
            'hire' => round($hire, 2),
            'late' => round($late, 2),
            'damage' => round($damage, 2),
            'subtotal' => $subtotal,
            'deposit_balance' => round($deposit, 2),
            /**
             * What the customer would actually hand over, deposit netted off.
             * Negative means the shop owes *them* — which is the ordinary case for
             * a hire returned on time, and the number the counter reads out.
             */
            'due_from_customer' => round($subtotal - $deposit, 2),
        ];
    }
}
