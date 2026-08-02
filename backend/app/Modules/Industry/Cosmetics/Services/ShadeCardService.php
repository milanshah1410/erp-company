<?php

declare(strict_types=1);

namespace App\Modules\Industry\Cosmetics\Services;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\WarehouseStock;
use App\Modules\Industry\Cosmetics\Models\CosmeticShade;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The shade card: one range, all its shades, and what is left of each.
 *
 * A cosmetics buyer does not create forty SKUs. They buy "this foundation, in
 * these forty shades" and expect forty rows to exist afterwards, in depth order,
 * with the four that are out of stock obvious at a glance. That is the whole of
 * this service, and it is deliberately the same shape as the garment matrix
 * (Retail\Services\VariantMatrixService) for the same reason that one is shaped
 * that way:
 *
 * **It writes ordinary `product_variants` rows.** The card is a way of *entering*
 * variants, not a parallel variant system — the POS scan, warehouse stock,
 * barcode labels, channel listings and the B2B portal all already speak variants,
 * and a second table would have meant teaching every one of them about make-up.
 *
 * Where it differs from the garment matrix is the axis, and the difference is
 * not cosmetic (so to speak). A garment grid is two-dimensional — size × colour
 * — because a shirt exists in both. A shade card is one-dimensional: a lipstick
 * is a shade and nothing else, and the second axis a garment shop needs (size)
 * has no counterpart here. Forcing the range through a two-axis grid would have
 * produced a forty-by-one table with an empty column header.
 *
 * The other difference is that the shade is a *row in a master*, not a word. The
 * generated variant carries `cosmetic_shade_id` as well as the attributes, so
 * "which shades of this range are we out of" is a join rather than a scan
 * through JSON — which is the question the screen exists to answer.
 *
 * Generation is additive and idempotent, exactly as the matrix is: re-running it
 * after the house adds six shades to the range fills in the six and leaves the
 * rest alone, because delete-and-recreate would destroy the barcodes on the
 * shelf and the stock attached to them.
 */
class ShadeCardService
{
    /** The attribute key the shade is mirrored into on the variant. */
    public const SHADE_KEY = 'shade';

    /**
     * Create a variant for every shade on the card that does not have one yet.
     *
     * @param  list<int>  $shadeIds
     * @param  array{cost_price?: float|null, selling_price?: float|null}  $pricing
     * @return array{created: int, skipped: int, variants: list<ProductVariant>}
     */
    public function generate(Product $product, array $shadeIds, array $pricing = []): array
    {
        $shades = CosmeticShade::query()
            ->whereIn('id', array_values(array_unique(array_map('intval', $shadeIds))))
            ->cardOrder()
            ->get();

        if ($shades->isEmpty()) {
            throw new RuntimeException('Pick at least one shade to put on the card.');
        }

        // 200 is not a technical limit; it is the point past which the request is
        // a mis-click rather than a range. The widest foundation range on sale
        // anywhere is about sixty shades.
        if ($shades->count() > 200) {
            throw new RuntimeException('That card would create over 200 variants. Narrow the shade selection.');
        }

        $existing = $this->indexExisting($product);

        $created = [];
        $skipped = 0;

        DB::transaction(function () use ($product, $shades, $pricing, $existing, &$created, &$skipped) {
            foreach ($shades as $shade) {
                if (isset($existing[$shade->id])) {
                    $skipped++;

                    continue;
                }

                $created[] = ProductVariant::create([
                    'product_id' => $product->id,
                    'sku' => $this->uniqueSku($product, $shade),
                    'name' => $shade->displayName(),
                    'cosmetic_shade_id' => $shade->id,
                    /**
                     * Mirrored into `attributes` as well as the foreign key, and
                     * both are load-bearing. The key is what the card and the
                     * stock question join on; the attribute is what every screen
                     * that renders a variant generically — the POS variant
                     * picker, a channel listing, a printed label — already knows
                     * how to display without being taught this vertical exists.
                     */
                    'attributes' => array_filter([
                        self::SHADE_KEY => $shade->displayName(),
                        'shade_code' => $shade->code,
                        'shade_family' => $shade->family,
                        'shade_finish' => $shade->finish,
                        'shade_hex' => $shade->hex,
                    ], static fn ($v) => $v !== null && $v !== ''),
                    /**
                     * Priced from the product, not left at zero. Every shade in a
                     * range sells at one price in this trade, and forty
                     * zero-priced SKUs are forty things somebody must fix before
                     * the shop can bill anything.
                     */
                    'cost_price' => $pricing['cost_price'] ?? $product->cost_price ?? 0,
                    'selling_price' => $pricing['selling_price'] ?? $product->selling_price ?? 0,
                    'is_active' => true,
                ]);
            }

            // The product is a variant parent from the first shade onwards; the
            // POS and the portal both read this flag to decide whether to make
            // the buyer pick before adding to the bill.
            if ($created !== [] && ! $product->has_variants) {
                $product->forceFill(['has_variants' => true])->save();
            }
        });

        return ['created' => count($created), 'skipped' => $skipped, 'variants' => $created];
    }

    /**
     * The card as it stands: one row per shade, with its variant and live stock.
     *
     * Rows come from the variants that exist, in card order, so a variant keyed
     * by hand on the Products screen appears here too. Variants with no shade
     * behind them are listed at the end rather than dropped — an unshaded variant
     * on a cosmetics product is a data problem the buyer needs to *see*, not one
     * the screen should hide by filtering.
     *
     * @return array<string, mixed>
     */
    public function card(Product $product, ?int $warehouseId = null): array
    {
        $variants = $product->variants()->with('cosmeticShade')->orderBy('id')->get();
        $stock = $this->stockFor($product, $warehouseId);

        $shaded = $variants->filter(fn (ProductVariant $v) => $v->cosmetic_shade_id !== null)
            ->sortBy(fn (ProductVariant $variant) => $this->cardWeight($variant))
            ->values();

        $rows = [];
        foreach ($shaded as $variant) {
            $rows[] = $this->row($variant, $variant->cosmeticShade, (float) ($stock[$variant->id] ?? 0));
        }

        $unshaded = [];
        foreach ($variants->filter(fn (ProductVariant $v) => $v->cosmetic_shade_id === null) as $variant) {
            $unshaded[] = $this->row($variant, null, (float) ($stock[$variant->id] ?? 0));
        }

        $inStock = collect($rows)->filter(fn (array $r) => $r['quantity'] > 0)->count();

        return [
            'product' => [
                'id' => $product->id,
                'sku' => $product->sku,
                'name' => $product->name,
                'has_variants' => (bool) $product->has_variants,
                'selling_price' => (float) $product->selling_price,
                'cost_price' => (float) $product->cost_price,
                'pao_months' => $product->pao_months,
                'cosmetic_shade_id' => $product->cosmetic_shade_id,
            ],
            'rows' => $rows,
            'unshaded' => $unshaded,
            /**
             * The number the buyer actually opens this screen for. "Thirty-six of
             * forty" is the state of a range; the individual quantities are how
             * you find which four.
             */
            'summary' => [
                'shades' => count($rows),
                'in_stock' => $inStock,
                'out_of_stock' => count($rows) - $inStock,
                'total_quantity' => round((float) collect($rows)->sum('quantity'), 3),
            ],
        ];
    }

    /**
     * Shades not yet on this product's card — what the "add shades" picker
     * offers, so the buyer is not asked to re-pick the thirty-four already there.
     *
     * @return list<array<string, mixed>>
     */
    public function availableShades(Product $product, ?int $brandId = null): array
    {
        $taken = array_keys($this->indexExisting($product));

        return CosmeticShade::query()
            ->where('is_active', true)
            ->when($taken !== [], fn ($q) => $q->whereNotIn('id', $taken))
            /**
             * Narrowed to the article's own brand when it has one. A shade card
             * is a house's card — offering a buyer every shade every brand on the
             * shelf has ever carried is how "015" from the wrong house ends up
             * under a product.
             */
            ->when($brandId ?? $product->brand_id, fn ($q, $brand) => $q->where(
                fn ($w) => $w->where('brand_id', $brand)->orWhereNull('brand_id'),
            ))
            ->cardOrder()
            ->get()
            ->map(fn (CosmeticShade $shade) => [
                'id' => $shade->id,
                'code' => $shade->code,
                'name' => $shade->name,
                'display_name' => $shade->displayName(),
                'family' => $shade->family,
                'finish' => $shade->finish,
                'undertone' => $shade->undertone,
                'depth_level' => $shade->depth_level,
                'hex' => $shade->hex,
                'descriptor' => $shade->descriptor(),
            ])
            ->all();
    }

    /** One card row: the variant, its shade and what is on the shelf. */
    private function row(ProductVariant $variant, ?CosmeticShade $shade, float $quantity): array
    {
        return [
            'variant_id' => $variant->id,
            'sku' => $variant->sku,
            'barcode' => $variant->barcode,
            'name' => $variant->name,
            'selling_price' => (float) $variant->selling_price,
            'is_active' => (bool) $variant->is_active,
            'quantity' => round($quantity, 3),
            'shade' => $shade ? [
                'id' => $shade->id,
                'code' => $shade->code,
                'name' => $shade->name,
                'family' => $shade->family,
                'finish' => $shade->finish,
                'undertone' => $shade->undertone,
                'depth_level' => $shade->depth_level,
                'hex' => $shade->hex,
                'descriptor' => $shade->descriptor(),
            ] : null,
        ];
    }

    /** Sort weight: depth first where present, then the shop's order, then code. */
    private function cardWeight(ProductVariant $variant): string
    {
        $shade = $variant->cosmeticShade;

        if (! $shade) {
            return 'zzz';
        }

        return sprintf(
            '%03d|%06d|%s',
            $shade->depth_level ?? 999,
            $shade->sort_order ?? 0,
            $shade->code,
        );
    }

    /**
     * Shades already on the card, keyed by shade id.
     *
     * @return array<int, int> shade_id => variant_id
     */
    private function indexExisting(Product $product): array
    {
        $out = [];

        foreach ($product->variants()->get(['id', 'cosmetic_shade_id']) as $variant) {
            if ($variant->cosmetic_shade_id) {
                $out[(int) $variant->cosmetic_shade_id] = $variant->id;
            }
        }

        return $out;
    }

    /** On-hand per variant, across all warehouses or one of them. */
    private function stockFor(Product $product, ?int $warehouseId): array
    {
        return WarehouseStock::query()
            ->where('product_id', $product->id)
            ->whereNotNull('variant_id')
            ->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->selectRaw('variant_id, SUM(quantity - reserved_quantity) as available')
            ->groupBy('variant_id')
            ->pluck('available', 'variant_id')
            ->all();
    }

    /**
     * Build the variant SKU and make it unique.
     *
     * Parent SKU plus shade code, which is exactly what is written on the box in
     * this trade. A collision is rare but must not silently merge two SKUs, so a
     * numeric suffix is appended rather than the shade being skipped — the
     * variant's identity on the card is its shade, not its SKU.
     */
    private function uniqueSku(Product $product, CosmeticShade $shade): string
    {
        $part = static fn (string $v) => strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $v) ?: '');

        $base = trim(implode('-', array_filter([
            $product->sku ?: 'CSM'.$product->id,
            $part($shade->code),
        ])), '-');

        $sku = $base;
        $n = 1;

        while (ProductVariant::where('sku', $sku)->exists()) {
            $sku = $base.'-'.(++$n);
        }

        return $sku;
    }
}
