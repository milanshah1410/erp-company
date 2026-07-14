<?php

namespace App\Services;

/**
 * Central pricing for sales documents (orders, POS, quotations, returns).
 *
 * Applies discounts BEFORE GST, in the standard Indian-invoice order:
 *   subtotal (gross) -> per-line discount -> overall invoice discount
 *   -> per-line GST on the discounted value -> round to nearest rupee -> grand total.
 *
 * The overall discount is spread across lines in proportion to each line's net
 * (post per-line-discount) value, so per-line GST stays correct even when lines
 * carry different tax rates. The stored line_total is the net taxable value plus
 * that line's tax, keeping GST reporting (taxable_value = line_total - tax_amount)
 * accurate.
 */
class DocumentPricingService
{
    /**
     * Price a set of document lines and derive the header totals.
     *
     * @param  array  $items  Each item: product_id, variant_id?, quantity, unit_price,
     *                        tax_percent?, discount_type?, discount_value?, plus any
     *                        passthrough keys (kept under 'source' for the caller).
     * @param  string|null  $overallType  'percent' | 'flat' | null
     * @param  float  $overallValue  the overall discount input (% or ₹)
     * @return array{lines: array<int, array<string, mixed>>, header: array<string, mixed>}
     */
    public function price(array $items, ?string $overallType = null, float $overallValue = 0): array
    {
        $staged = [];
        $subtotal = 0.0;
        $itemDiscountTotal = 0.0;
        $lineNetSubtotal = 0.0;

        foreach ($items as $item) {
            $qty = (float) $item['quantity'];
            $price = (float) $item['unit_price'];
            $base = $qty * $price;

            $discType = $item['discount_type'] ?? null;
            $discValue = (float) ($item['discount_value'] ?? 0);
            $disc = round($this->discountAmount($discType, $discValue, $base), 2);
            $net = $base - $disc;

            $staged[] = [
                'source' => $item,
                'quantity' => $qty,
                'unit_price' => $price,
                'tax_percent' => (float) ($item['tax_percent'] ?? 0),
                'discount_type' => $disc > 0 ? $discType : null,
                'discount_value' => $discValue,
                'discount_amount' => $disc,
                'net' => $net,
            ];

            $subtotal += $base;
            $itemDiscountTotal += $disc;
            $lineNetSubtotal += $net;
        }

        // Overall invoice discount, taken on the line-adjusted subtotal.
        $overallDiscount = round($this->discountAmount($overallType, $overallValue, $lineNetSubtotal), 2);

        // Distribute the overall discount across lines proportionally to net value.
        // The rounding remainder lands on the last line so the parts sum exactly.
        $taxTotal = 0.0;
        $allocated = 0.0;
        $lastIdx = count($staged) - 1;
        $lines = [];

        foreach ($staged as $idx => $line) {
            if ($idx === $lastIdx) {
                $share = round($overallDiscount - $allocated, 2);
            } elseif ($lineNetSubtotal > 0) {
                $share = round($overallDiscount * ($line['net'] / $lineNetSubtotal), 2);
            } else {
                $share = 0.0;
            }
            $allocated = round($allocated + $share, 2);

            $taxable = $line['net'] - $share;
            $lineTax = round($taxable * $line['tax_percent'] / 100, 2);
            $taxTotal += $lineTax;

            $src = $line['source'];
            $lines[] = [
                'source' => $src,
                'product_id' => $src['product_id'] ?? null,
                'variant_id' => $src['variant_id'] ?? null,
                'quantity' => $line['quantity'],
                'unit_price' => $line['unit_price'],
                'discount_type' => $line['discount_type'],
                'discount_value' => $line['discount_value'],
                'discount_amount' => $line['discount_amount'],
                'tax_percent' => $line['tax_percent'],
                'tax_amount' => $lineTax,
                'line_total' => round($taxable + $lineTax, 2),
            ];
        }

        $taxableTotal = round($lineNetSubtotal - $overallDiscount, 2);
        $preRound = $taxableTotal + round($taxTotal, 2);
        $total = round($preRound, 0);
        $roundOff = round($total - $preRound, 2);

        return [
            'lines' => $lines,
            'header' => [
                'subtotal' => round($subtotal, 2),
                'item_discount_total' => round($itemDiscountTotal, 2),
                'discount_type' => $overallDiscount > 0 ? $overallType : null,
                'discount_value' => round($overallValue, 2),
                'discount_amount' => $overallDiscount,
                'taxable' => $taxableTotal,
                'tax_amount' => round($taxTotal, 2),
                'round_off' => $roundOff,
                'total' => round($total, 2),
            ],
        ];
    }

    /** Resolve a discount input (percent of / flat off a base), capped at the base. */
    private function discountAmount(?string $type, float $value, float $base): float
    {
        if ($value <= 0 || $base <= 0) {
            return 0.0;
        }
        if ($type === 'percent') {
            return min($base, $base * $value / 100);
        }
        if ($type === 'flat') {
            return min($base, $value);
        }

        return 0.0;
    }
}
