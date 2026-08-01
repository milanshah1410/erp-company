<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\Tenant;
use App\Support\PlatformBillingProfile;

/**
 * Splits the money on a subscription invoice into taxable value and GST
 * (Volume 11 §Billing → GST).
 *
 * The one rule that matters: GST is destination-based, so *where the customer is*
 * decides the split, not where we are. Same state as the seller → the tax is
 * halved into CGST + SGST; different state → the whole rate is IGST. Both never
 * appear on one line, which is why {@see breakdown()} returns one or the other
 * zeroed rather than letting a caller choose.
 *
 * Place of supply is taken from the tenant's GSTIN (its first two digits *are* the
 * state of registration) and only falls back to a hand-entered state code. Absent
 * both, we assume the customer is in our own state — an intra-state B2C supply,
 * which is the conservative reading: it never under-collects.
 *
 * Zero-rated cases (no seller GSTIN, a rate of 0, or a non-INR invoice — an export
 * of services) come back with a rate of 0 and no tax at all.
 */
class PlatformTaxCalculator
{
    /**
     * Decompose a gross amount into the invoice's tax columns.
     *
     * @param  float  $gross  The list price for the period, before any discount.
     *                        Read as tax-inclusive when the profile says prices
     *                        include tax, tax-exclusive otherwise.
     * @param  float  $discount  Coupon discount, applied to the taxable value
     *                           *before* tax — the Indian treatment, where GST is
     *                           charged on what the customer actually pays.
     * @return array{
     *     subtotal: float, discount_amount: float, taxable_amount: float,
     *     tax_rate: float, cgst_amount: float, sgst_amount: float, igst_amount: float,
     *     tax_amount: float, amount: float, currency: string,
     *     place_of_supply: ?string, seller_state_code: ?string, is_interstate: bool,
     *     customer_gstin: ?string, customer_legal_name: ?string, sac_code: ?string
     * }
     */
    public function breakdown(
        float $gross,
        float $discount = 0.0,
        string $currency = 'INR',
        ?Tenant $tenant = null,
    ): array {
        $profile = PlatformBillingProfile::all();
        $sellerState = $profile['state_code'];
        $rate = $this->rateFor($currency);

        // A tax-inclusive price has to be stripped back to its taxable value
        // before anything else, or the discount would come off a gross figure and
        // the tax would be charged on tax.
        $subtotal = $profile['prices_include_tax'] && $rate > 0
            ? round($gross / (1 + $rate / 100), 2)
            : round($gross, 2);

        $discount = round(max(0.0, min($discount, $subtotal)), 2);
        $taxable = round($subtotal - $discount, 2);

        $placeOfSupply = $tenant?->placeOfSupply() ?: $sellerState;
        // With no seller state there is no such thing as "another state" — an
        // unregistered supplier raises no IGST.
        $interstate = $rate > 0
            && $sellerState !== null
            && $placeOfSupply !== null
            && $placeOfSupply !== $sellerState;

        $tax = round($taxable * $rate / 100, 2);

        // Half each, with the rounding remainder pushed onto SGST so the two
        // halves always add back to the total tax (₹0.01 differences here are what
        // make a GSTR-1 reconciliation fail).
        $cgst = $interstate ? 0.0 : round($tax / 2, 2);
        $sgst = $interstate ? 0.0 : round($tax - $cgst, 2);
        $igst = $interstate ? $tax : 0.0;

        return [
            'subtotal' => $subtotal,
            'discount_amount' => $discount,
            'taxable_amount' => $taxable,
            'tax_rate' => $rate,
            'cgst_amount' => $cgst,
            'sgst_amount' => $sgst,
            'igst_amount' => $igst,
            'tax_amount' => $tax,
            'amount' => round($taxable + $tax, 2),
            'currency' => $currency,
            'place_of_supply' => $rate > 0 ? $placeOfSupply : null,
            'seller_state_code' => $sellerState,
            'is_interstate' => $interstate,
            'customer_gstin' => $tenant?->billing_gstin ?: null,
            'customer_legal_name' => $tenant?->billingName(),
            'sac_code' => $rate > 0 ? $profile['sac_code'] : null,
        ];
    }

    /**
     * Decompose an amount the gateway has already collected.
     *
     * The customer was charged a total, so here the total is the fixed point and
     * the taxable value is back-computed from it — the reverse of {@see breakdown()},
     * where the list price is fixed and tax is added. Used when a Razorpay payment
     * lands and we record the invoice after the fact.
     */
    public function breakdownFromTotal(float $total, string $currency = 'INR', ?Tenant $tenant = null): array
    {
        $rate = $this->rateFor($currency);

        $taxable = $rate > 0 ? round($total / (1 + $rate / 100), 2) : round($total, 2);

        $result = $this->breakdown($taxable, 0.0, $currency, $tenant);

        // Re-assert the collected total: rounding the division above can leave the
        // recomposed figure a paisa off what the gateway actually took, and the
        // gateway is the authority on that number.
        $result['amount'] = round($total, 2);
        $result['tax_amount'] = round($total - $result['taxable_amount'], 2);

        if ($result['is_interstate']) {
            $result['igst_amount'] = $result['tax_amount'];
        } else {
            $result['cgst_amount'] = round($result['tax_amount'] / 2, 2);
            $result['sgst_amount'] = round($result['tax_amount'] - $result['cgst_amount'], 2);
        }

        return $result;
    }

    /**
     * The rate to apply. A non-INR invoice is an export of services — zero-rated
     * without a LUT, and not something this panel is trying to model.
     */
    private function rateFor(string $currency): float
    {
        if (strtoupper($currency) !== 'INR') {
            return 0.0;
        }

        return PlatformBillingProfile::effectiveTaxRate();
    }
}
