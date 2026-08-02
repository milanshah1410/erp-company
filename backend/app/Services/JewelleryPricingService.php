<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\MetalRate;
use App\Models\OldGoldReceipt;
use App\Models\Product;
use App\Models\ProductStone;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * What an ornament costs today.
 *
 * A jeweller's bill is built, not looked up:
 *
 *   metal    = net weight × the day's rate for that purity
 *   wastage  = wastage% of the net weight, charged at the same rate
 *   making   = % of metal, or ₹/gram of net weight, or a flat amount
 *   stones   = a flat value, priced by the piece and never by the gram
 *   hallmark = what the assaying centre charged to strike the mark
 *   ------
 *   line     = metal + wastage + making + stones + hallmark
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
     * Rates already looked up, keyed metal|purity|date.
     *
     * Request-scoped by construction — the service is injected, not bound as a
     * singleton, so an instance lives exactly as long as the controller holding
     * it. That is the right lifetime: pricing a hundred-ornament POS grid asks
     * for the same three rates a hundred times, and the rate card does not move
     * during a page load.
     *
     * @var array<string, MetalRate|null>
     */
    private array $rates = [];

    /**
     * Price one ornament at the rate in force on a date.
     *
     * @return array{
     *     unit_price: float, metal_rate: float, net_weight: float,
     *     metal_value: float, wastage_value: float, making_charge: float,
     *     stone_value: float, hallmark_charge: float, purity: ?string,
     *     stones: list<array<string, mixed>>, huid: ?string,
     *     breakdown: list<array{label: string, amount: float}>
     * }
     *
     * @throws RuntimeException when no rate has ever been quoted for the purity.
     */
    public function priceProduct(Product $product, ?string $date = null, float $quantity = 1): array
    {
        if (! $product->isRatePriced()) {
            throw new RuntimeException("{$product->name} is not a rate-priced jewellery item.");
        }

        $rate = $this->rateFor($product->metal, (string) $product->purity, $date);

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
        // Per piece, flat, and not a percentage of anything — the assaying
        // centre charges the same to mark a bangle as a bracelet.
        $hallmarkCharge = (float) $product->hallmark_charge;

        /**
         * The stone register, when the piece has one. It does not change the
         * money — `stone_value` is already the total of these lines, kept in
         * step by Product::syncStoneTotals() — it changes what the bill can
         * *say*, which for a studded piece is most of what the customer is
         * buying. A piece described as a lump sum prices exactly as before.
         */
        $stones = $product->relationLoaded('stones')
            ? $product->stones
            : $product->stones()->orderByDesc('carat_weight')->get();

        $unitPrice = round($metalValue + $wastageValue + $makingCharge + $stoneValue + $hallmarkCharge, 2);

        return [
            'unit_price' => $unitPrice,
            'metal_rate' => $ratePerGram,
            'net_weight' => $netWeight,
            'metal_value' => $metalValue,
            'wastage_value' => $wastageValue,
            'making_charge' => $makingCharge,
            'stone_value' => $stoneValue,
            'hallmark_charge' => $hallmarkCharge,
            'purity' => $product->purity,
            'huid' => $product->huid,
            'stones' => $stones->map(fn (ProductStone $s) => $s->toSnapshot())->values()->all(),
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
                // Named lines when the register knows them, one 'Stones' line
                // when it does not. The amounts add up to the same total either
                // way, so nothing downstream has to choose between the two.
                ...$this->stoneBreakdown($stones, $stoneValue),
                $hallmarkCharge > 0
                    ? ['label' => 'Hallmarking', 'amount' => $hallmarkCharge]
                    : null,
            ])),
        ];
    }

    /**
     * The stone side of the bill.
     *
     * The register's own lines are used only when they account for the whole
     * stone value. A shop that typed a lump sum and *also* listed two of the
     * stones would otherwise get a bill whose parts do not sum to its total —
     * so in that case the detail is dropped and the single line stands.
     *
     * @param  Collection<int, ProductStone>  $stones
     * @return list<array{label: string, amount: float}>
     */
    private function stoneBreakdown($stones, float $stoneValue): array
    {
        if ($stoneValue <= 0) {
            return [];
        }

        $listed = round((float) $stones->sum('value'), 2);

        if ($stones->isEmpty() || abs($listed - round($stoneValue, 2)) > 0.01) {
            return [['label' => 'Stones', 'amount' => $stoneValue]];
        }

        return $stones
            ->map(fn (ProductStone $s) => ['label' => $s->label(), 'amount' => (float) $s->value])
            ->values()
            ->all();
    }

    /**
     * The build-up to store on a sale line, or an empty array for anything that
     * is not an ornament.
     *
     * Recomputed here rather than taken from the till's cart: money is decided
     * on the server, and a client that can post its own metal rate can post any
     * rate it likes. The columns are a *snapshot* — once written they are what
     * the invoice says, whatever the rate card does tomorrow.
     *
     * When the counter has overridden the price, the components are still the
     * shop's own build-up and will not add up to `unit_price`. That is the
     * honest record of what happened: the piece was worth this and sold for
     * that, and the difference is a concession the owner can see.
     *
     * @return array<string, mixed>
     */
    public function snapshotFor(Product $product, ?string $date = null): array
    {
        if (! $product->isRatePriced()) {
            return [];
        }

        try {
            $priced = $this->priceProduct($product, $date);
        } catch (RuntimeException) {
            // No rate for the purity. The sale itself is not in doubt — it was
            // priced off the stored fallback — so it goes through unsnapshotted
            // rather than being refused at the tender screen.
            return [];
        }

        return [
            'metal_rate' => $priced['metal_rate'],
            'net_weight' => $priced['net_weight'],
            'metal_value' => $priced['metal_value'],
            'making_charge' => $priced['making_charge'],
            'wastage_value' => $priced['wastage_value'],
            'stone_value' => $priced['stone_value'],
            'hallmark_charge' => $priced['hallmark_charge'],
            'stone_details' => $priced['stones'] ?: null,
        ];
    }

    /**
     * The build-up for every ornament in a set of priced document lines, keyed
     * by product id and ready to spread onto the sale line.
     *
     * Here rather than in the till so the order desk gets the same record from
     * the same code: a bespoke piece booked at the counter and one billed over
     * the till have to show the same rate for the same day.
     *
     * A cart with no metal in it costs one `whereIn` over an empty set, which is
     * what every non-jewellery workspace will pay for it.
     *
     * @param  array<int, array<string, mixed>>  $lines  Priced lines (DocumentPricingService)
     * @return array<int, array<string, mixed>>
     */
    public function snapshotsForLines(array $lines, ?string $date = null): array
    {
        $ids = array_values(array_unique(array_filter(array_column($lines, 'product_id'))));

        if (! $ids) {
            return [];
        }

        return Product::whereIn('id', $ids)
            ->whereNotNull('metal')
            ->with('stones')
            ->get()
            ->mapWithKeys(fn (Product $p) => [$p->id => $this->snapshotFor($p, $date)])
            // A purity with no rate quoted yet snapshots to nothing; dropping it
            // here keeps the caller's spread a no-op rather than a row of nulls.
            ->filter()
            ->all();
    }

    /**
     * The rate in force, looked up once per metal/purity/date.
     *
     * `array_key_exists` rather than `??=` on purpose: "no rate has ever been
     * quoted for this purity" is a real answer worth remembering, and coalescing
     * would re-run the query for it on every line of the page.
     */
    private function rateFor(string $metal, string $purity, ?string $date): ?MetalRate
    {
        $key = $metal.'|'.$purity.'|'.($date ?? 'today');

        if (! array_key_exists($key, $this->rates)) {
            $this->rates[$key] = MetalRate::forDate($metal, $purity, $date);
        }

        return $this->rates[$key];
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
        $rate = $this->rateFor($metal, $purity, $date);
        $fineness = $rate?->fineness ?? MetalRate::finenessFor($purity);

        if (! $rate) {
            // Fall back to pure metal scaled by fineness.
            $pure = $this->rateFor($metal, '24K', $date)
                ?? $this->rateFor($metal, '999', $date);

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
