<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\MetalRate;
use App\Models\OldGoldReceipt;
use App\Models\Product;
use RuntimeException;

/**
 * What an ornament costs today.
 *
 * A jeweller's bill is built, not looked up:
 *
 *   metal   = net weight × the day's rate for that purity
 *   wastage = wastage% of the net weight, charged at the same rate
 *   making  = % of metal, or ₹/gram of net weight, or a flat amount
 *   stones  = a flat value, priced by the piece and never by the gram
 *   ------
 *   line    = metal + wastage + making + stones
 *
 * Two rules that decide whether a bill is right:
 *
 *  - Only the *net* weight earns the metal rate. Gross weight includes the
 *    stones, and charging gold rate on a stone is the classic way a jewellery
 *    bill comes out wrong.
 *  - The rate used is snapshotted onto the sale line, so tomorrow's rate card
 *    cannot restate an invoice the customer already has.
 */
class JewelleryPricingService
{
    /**
     * Price one ornament at the rate in force on a date.
     *
     * @return array{
     *     unit_price: float, metal_rate: float, net_weight: float,
     *     metal_value: float, wastage_value: float, making_charge: float,
     *     stone_value: float, purity: ?string, breakdown: list<array{label: string, amount: float}>
     * }
     *
     * @throws RuntimeException when no rate has ever been quoted for the purity.
     */
    public function priceProduct(Product $product, ?string $date = null, float $quantity = 1): array
    {
        if (! $product->isRatePriced()) {
            throw new RuntimeException("{$product->name} is not a rate-priced jewellery item.");
        }

        $rate = MetalRate::forDate($product->metal, (string) $product->purity, $date);

        if (! $rate) {
            throw new RuntimeException(
                "No {$product->metal} rate has been set for purity {$product->purity}. Set today's rate first.",
            );
        }

        $netWeight = (float) $product->net_weight;
        $ratePerGram = (float) $rate->rate_per_gram;

        $metalValue = round($netWeight * $ratePerGram, 2);

        // Wastage is extra *metal*, so it is charged at the metal rate — not a
        // percentage of the finished line, which would tax the making charge too.
        $wastageWeight = round($netWeight * (float) $product->wastage_percent / 100, 3);
        $wastageValue = round($wastageWeight * $ratePerGram, 2);

        $makingCharge = $this->makingCharge($product, $metalValue + $wastageValue, $netWeight);
        $stoneValue = (float) $product->stone_value;

        $unitPrice = round($metalValue + $wastageValue + $makingCharge + $stoneValue, 2);

        return [
            'unit_price' => $unitPrice,
            'metal_rate' => $ratePerGram,
            'net_weight' => $netWeight,
            'metal_value' => $metalValue,
            'wastage_value' => $wastageValue,
            'making_charge' => $makingCharge,
            'stone_value' => $stoneValue,
            'purity' => $product->purity,
            // What goes on the printed bill. Indian customers expect to see the
            // split, and a jeweller who cannot show it loses the argument.
            'breakdown' => array_values(array_filter([
                ['label' => "Metal ({$product->purity}, {$netWeight}g @ {$ratePerGram}/g)", 'amount' => $metalValue],
                $wastageValue > 0
                    ? ['label' => "Wastage ({$product->wastage_percent}%)", 'amount' => $wastageValue]
                    : null,
                $makingCharge > 0
                    ? ['label' => $this->makingLabel($product), 'amount' => $makingCharge]
                    : null,
                $stoneValue > 0 ? ['label' => 'Stones', 'amount' => $stoneValue] : null,
            ])),
        ];
    }

    /**
     * Resolve the making charge from however the shop quotes it.
     *
     * @param  float  $metalValue  Metal plus wastage — a percentage making charge
     *                             is taken on the metal the customer is paying
     *                             for, wastage included.
     */
    private function makingCharge(Product $product, float $metalValue, float $netWeight): float
    {
        $value = (float) $product->making_charge_value;

        if ($value <= 0) {
            return 0.0;
        }

        return round(match ($product->making_charge_type) {
            'percent' => $metalValue * $value / 100,
            'per_gram' => $netWeight * $value,
            'flat' => $value,
            default => 0.0,
        }, 2);
    }

    private function makingLabel(Product $product): string
    {
        $value = (float) $product->making_charge_value;

        return match ($product->making_charge_type) {
            'percent' => "Making ({$value}%)",
            'per_gram' => "Making ({$value}/g)",
            default => 'Making charges',
        };
    }

    /**
     * Value scrap a customer has brought in.
     *
     * Paid on the *chargeable* weight: net weight less the touch/melting
     * deduction the shop takes, at the buy rate rather than the selling rate.
     * When the shop has never quoted that exact purity — odd-karat scrap is
     * common — the 24K rate is scaled by fineness, which is what the counter
     * does by hand anyway.
     *
     * @return array{chargeable_weight: float, rate_per_gram: float, amount: float, fineness: int}
     */
    public function valueOldGold(
        string $metal,
        string $purity,
        float $netWeight,
        float $deductionPercent = 0,
        ?string $date = null,
    ): array {
        $rate = MetalRate::forDate($metal, $purity, $date);
        $fineness = $rate?->fineness ?? MetalRate::finenessFor($purity);

        if (! $rate) {
            // Fall back to pure metal scaled by fineness.
            $pure = MetalRate::forDate($metal, '24K', $date)
                ?? MetalRate::forDate($metal, '999', $date);

            if (! $pure || ! $fineness) {
                throw new RuntimeException(
                    "No {$metal} rate is available to value {$purity} scrap. Set today's rate first.",
                );
            }

            $ratePerGram = round($pure->buyRate() * $fineness / $pure->fineness, 2);
        } else {
            $ratePerGram = $rate->buyRate();
        }

        $chargeable = round($netWeight * (1 - $deductionPercent / 100), 3);

        return [
            'chargeable_weight' => $chargeable,
            'rate_per_gram' => $ratePerGram,
            'amount' => round($chargeable * $ratePerGram, 2),
            'fineness' => (int) ($fineness ?? 0),
        ];
    }

    /**
     * Old-gold credit a customer is holding — receipts taken in but not yet set
     * off against any bill.
     */
    public function openCreditFor(int $customerId): float
    {
        return (float) OldGoldReceipt::where('customer_id', $customerId)
            ->whereNull('invoice_id')
            ->sum('amount');
    }
}
