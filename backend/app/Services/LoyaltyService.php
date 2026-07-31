<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\LoyaltyTransaction;
use App\Models\Setting;

/**
 * The points programme (SRS §21).
 *
 * Two rules, both tenant-configurable: a bill earns points in proportion to its
 * value, and points redeem as a flat rupee discount. Everything else here exists
 * to stop a counter from awarding or spending points it should not — the minimum
 * bill, the minimum redemption, and the cap on how much of a bill points may pay.
 *
 * Points live in `loyalty_transactions` as a signed ledger; the balance is its
 * sum. Nothing is stored on the customer row, so an adjustment is always an
 * entry someone can point at.
 */
class LoyaltyService
{
    /** Is the programme switched on for this workspace? */
    public function enabled(): bool
    {
        return (string) Setting::get('loyalty_enabled', '0') === '1';
    }

    /**
     * The programme's configuration, resolved and typed.
     *
     * @return array{enabled: bool, points_per_amount: float, earn_amount_unit: float, redeem_value: float, min_redeem_points: int, min_bill_amount: float, max_redeem_percent: float}
     */
    public function config(): array
    {
        return [
            'enabled' => $this->enabled(),
            'points_per_amount' => (float) Setting::get('loyalty_points_per_amount', '1'),
            // Guarded against a stored 0: it is the divisor in earnedFor().
            'earn_amount_unit' => max(1.0, (float) Setting::get('loyalty_earn_amount_unit', '100')),
            'redeem_value' => (float) Setting::get('loyalty_redeem_value', '1'),
            'min_redeem_points' => (int) Setting::get('loyalty_min_redeem_points', '100'),
            'min_bill_amount' => (float) Setting::get('loyalty_min_bill_amount', '0'),
            'max_redeem_percent' => (float) Setting::get('loyalty_max_redeem_percent', '50'),
        ];
    }

    /**
     * Points a bill of this value earns. Rounded down: a shop would rather award
     * one point too few than one it did not mean to.
     */
    public function earnedFor(float $amount): int
    {
        $config = $this->config();

        if (! $config['enabled'] || $amount < $config['min_bill_amount'] || $config['points_per_amount'] <= 0) {
            return 0;
        }

        return (int) floor($amount / $config['earn_amount_unit'] * $config['points_per_amount']);
    }

    /** The rupee value of a number of points. */
    public function valueOf(int $points): float
    {
        return round(max(0, $points) * $this->config()['redeem_value'], 2);
    }

    /**
     * The most points this customer may spend against a bill of this value,
     * given their balance, the minimum redemption and the per-bill cap.
     *
     * Returns 0 when redemption is not available at all — the caller can treat
     * that as "hide the redeem box" without repeating the rules.
     */
    public function maxRedeemablePoints(?Customer $customer, float $billAmount): int
    {
        $config = $this->config();

        if (! $customer || ! $config['enabled'] || $config['redeem_value'] <= 0) {
            return 0;
        }

        $balance = $customer->loyaltyPoints();
        if ($balance < $config['min_redeem_points']) {
            return 0;
        }

        $cappedValue = round($billAmount * $config['max_redeem_percent'] / 100, 2);
        $affordable = (int) floor($cappedValue / $config['redeem_value']);

        return max(0, min($balance, $affordable));
    }

    /**
     * Validate a requested redemption against the rules and return the discount
     * it buys.
     *
     * @return array{points: int, amount: float}
     *
     * @throws \RuntimeException when the request breaks a rule — the caller turns
     *                           this into a 422 in whatever shape its form wants.
     */
    public function quoteRedemption(?Customer $customer, float $billAmount, int $points): array
    {
        if ($points <= 0) {
            return ['points' => 0, 'amount' => 0.0];
        }

        $config = $this->config();

        if (! $config['enabled']) {
            throw new \RuntimeException('The loyalty programme is not enabled.');
        }

        if (! $customer) {
            throw new \RuntimeException('Select a customer before redeeming points.');
        }

        if ($points < $config['min_redeem_points']) {
            throw new \RuntimeException("At least {$config['min_redeem_points']} points are needed to redeem.");
        }

        $max = $this->maxRedeemablePoints($customer, $billAmount);

        if ($points > $max) {
            throw new \RuntimeException(
                $customer->loyaltyPoints() < $points
                    ? 'Insufficient points balance for this redemption.'
                    : "At most {$max} points can be redeemed on this bill.",
            );
        }

        return ['points' => $points, 'amount' => $this->valueOf($points)];
    }

    /**
     * Credit the points a settled invoice earned. No-op when the programme is
     * off, the sale is anonymous, or the bill earns nothing — so callers can
     * invoke it unconditionally after a sale.
     *
     * Idempotent per invoice: a re-post (a retried job, a corrected total) will
     * not award the same bill twice.
     */
    public function awardForInvoice(Invoice $invoice, ?int $userId = null): ?LoyaltyTransaction
    {
        if (! $invoice->customer_id) {
            return null;
        }

        $points = $this->earnedFor((float) $invoice->total);
        if ($points <= 0) {
            return null;
        }

        $already = LoyaltyTransaction::where('customer_id', $invoice->customer_id)
            ->where('invoice_id', $invoice->id)
            ->where('type', 'earn')
            ->exists();

        if ($already) {
            return null;
        }

        return LoyaltyTransaction::create([
            'customer_id' => $invoice->customer_id,
            'type' => 'earn',
            'points' => $points,
            'invoice_id' => $invoice->id,
            'description' => "Earned on invoice {$invoice->invoice_number}",
            'created_by' => $userId,
        ]);
    }

    /**
     * Claw back the points a sale earned when its goods come back.
     *
     * Without this, buy → earn → return hands the customer their money *and*
     * the points, repeatably. Reversal is proportional to the value credited,
     * so a partial return only takes back a partial share, and repeated partial
     * returns never take back more than the sale earned in the first place.
     *
     * The balance is allowed to go negative: a customer who already spent the
     * points and then returned the goods really is short, and capping at zero
     * would leave the exploit open one redemption deep. Rounding is in the
     * customer's favour — a fractional share claws back nothing.
     */
    public function reverseForInvoice(
        Invoice $invoice,
        float $creditedAmount,
        ?string $reference = null,
        ?int $userId = null,
    ): ?LoyaltyTransaction {
        $total = (float) $invoice->total;

        if (! $invoice->customer_id || $creditedAmount <= 0 || $total <= 0) {
            return null;
        }

        $earned = (int) LoyaltyTransaction::where('customer_id', $invoice->customer_id)
            ->where('invoice_id', $invoice->id)
            ->where('type', 'earn')
            ->sum('points');

        if ($earned <= 0) {
            return null;
        }

        // What earlier partial returns against this same invoice already took.
        $alreadyReversed = (int) abs((float) LoyaltyTransaction::where('customer_id', $invoice->customer_id)
            ->where('invoice_id', $invoice->id)
            ->where('type', 'adjust')
            ->where('points', '<', 0)
            ->sum('points'));

        $share = (int) floor($earned * min($creditedAmount, $total) / $total);
        $points = max(0, min($share, $earned - $alreadyReversed));

        if ($points <= 0) {
            return null;
        }

        return LoyaltyTransaction::create([
            'customer_id' => $invoice->customer_id,
            'type' => 'adjust',
            'points' => -$points,
            'invoice_id' => $invoice->id,
            'description' => 'Reversed on return'.($reference ? " {$reference}" : '')
                ." against invoice {$invoice->invoice_number}",
            'created_by' => $userId,
        ]);
    }

    /**
     * Debit redeemed points against an invoice. The caller has already applied
     * the matching discount to the bill; this only records the ledger side.
     */
    public function redeemForInvoice(Invoice $invoice, int $points, ?int $userId = null): ?LoyaltyTransaction
    {
        if ($points <= 0 || ! $invoice->customer_id) {
            return null;
        }

        return LoyaltyTransaction::create([
            'customer_id' => $invoice->customer_id,
            'type' => 'redeem',
            'points' => -$points,
            'invoice_id' => $invoice->id,
            'description' => "Redeemed on invoice {$invoice->invoice_number}",
            'created_by' => $userId,
        ]);
    }
}
