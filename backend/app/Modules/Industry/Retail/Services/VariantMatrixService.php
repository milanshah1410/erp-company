<?php

declare(strict_types=1);

namespace App\Modules\Industry\Retail\Services;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\WarehouseStock;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The size × colour matrix.
 *
 * A garment shop does not create forty SKUs; it says "this shirt, in six sizes
 * and five colours" and expects thirty rows to exist afterwards. That is the
 * whole of this service.
 *
 * The one decision worth stating: it writes ordinary `product_variants` rows.
 * The matrix is a way of *entering* variants, not a parallel variant system —
 * everything downstream (the POS scan, warehouse stock, barcode labels, channel
 * listings, the B2B portal) already speaks variants, and a second table would
 * have meant teaching every one of them about garments. The grid is
 * reconstructed on read from `attributes.size` and `attributes.colour`, so a
 * variant created by hand on the Products screen shows up in it, and one created
 * here is nothing special anywhere else.
 *
 * Generation is additive and idempotent. Re-running it after adding a colour
 * fills in that column and leaves the rest untouched, because the alternative —
 * delete and recreate — would destroy the barcodes on the shelf and the stock
 * attached to them.
 */
class VariantMatrixService
{
    /** Attribute keys the grid is built on. */
    public const SIZE_KEY = 'size';

    public const COLOUR_KEY = 'colour';

    /**
     * Create every missing size × colour combination for a product.
     *
     * @param  list<string>  $sizes  Ordered size run — the grid's columns.
     * @param  list<array{name: string, code?: string|null, hex?: string|null}>  $colours  The grid's rows.
     * @param  array{cost_price?: float|null, selling_price?: float|null}  $pricing
     * @return array{created: int, skipped: int, variants: list<ProductVariant>}
     */
    public function generate(Product $product, array $sizes, array $colours, array $pricing = []): array
    {
        $sizes = $this->cleanSizes($sizes);
        $colours = $this->cleanColours($colours);

        if ($sizes === [] || $colours === []) {
            throw new RuntimeException('A matrix needs at least one size and one colour.');
        }

        // 200 is not a technical limit; it is the point past which the request
        // is a mis-click rather than a range plan. A real apparel style tops out
        // around 10 sizes by 12 colours.
        if (count($sizes) * count($colours) > 200) {
            throw new RuntimeException('That grid would create over 200 variants. Narrow the sizes or colours.');
        }

        $existing = $this->indexExisting($product);

        $created = [];
        $skipped = 0;

        DB::transaction(function () use ($product, $sizes, $colours, $pricing, $existing, &$created, &$skipped) {
            foreach ($colours as $colour) {
                foreach ($sizes as $size) {
                    $cell = $this->cellKey($size, $colour['name']);

                    if (isset($existing[$cell])) {
                        $skipped++;

                        continue;
                    }

                    $created[] = ProductVariant::create([
                        'product_id' => $product->id,
                        'sku' => $this->uniqueSku($product, $size, $colour),
                        'name' => "{$size} / {$colour['name']}",
                        'attributes' => [
                            self::SIZE_KEY => $size,
                            self::COLOUR_KEY => $colour['name'],
                        ] + array_filter([
                            'colour_code' => $colour['code'] ?? null,
                            'colour_hex' => $colour['hex'] ?? null,
                        ], static fn ($v) => $v !== null && $v !== ''),
                        /**
                         * Priced from the style/product, not left at zero. Every
                         * piece cut from one style sells at one price in this
                         * trade, and a grid of thirty zero-priced SKUs is thirty
                         * things somebody has to go and fix before the shop can
                         * bill anything.
                         */
                        'cost_price' => $pricing['cost_price'] ?? $product->cost_price ?? 0,
                        'selling_price' => $pricing['selling_price'] ?? $product->selling_price ?? 0,
                        'is_active' => true,
                    ]);
                }
            }

            // The product is a variant parent from the first cell onwards; the
            // POS and the portal both read this flag to decide whether to make
            // the buyer pick before adding to the bill.
            if ($created !== [] && ! $product->has_variants) {
                $product->forceFill(['has_variants' => true])->save();
            }
        });

        return ['created' => count($created), 'skipped' => $skipped, 'variants' => $created];
    }

    /**
     * The grid as it stands: one row per colour, one cell per size, each cell
     * carrying its variant and live stock.
     *
     * Sizes and colours come from the variants themselves, in the order they
     * were declared where that is known and alphabetically otherwise — so the
     * screen renders whatever is really there, including variants somebody keyed
     * by hand before the matrix existed.
     *
     * @param  list<string>  $preferredSizes  Declared size run, to hold column order.
     * @return array<string, mixed>
     */
    public function grid(Product $product, array $preferredSizes = [], ?int $warehouseId = null): array
    {
        $variants = $product->variants()->orderBy('id')->get();
        $stock = $this->stockFor($product, $warehouseId);

        $sizes = $this->cleanSizes($preferredSizes);
        $colours = [];

        foreach ($variants as $variant) {
            $size = $this->attr($variant, self::SIZE_KEY);
            $colour = $this->attr($variant, self::COLOUR_KEY);

            if ($size === null || $colour === null) {
                continue;
            }

            if (! in_array($size, $sizes, true)) {
                $sizes[] = $size;
            }
            $colours[$colour] ??= [
                'name' => $colour,
                'code' => $variant->attributes['colour_code'] ?? null,
                'hex' => $variant->attributes['colour_hex'] ?? null,
            ];
        }

        $rows = [];
        foreach ($colours as $colourName => $colour) {
            $cells = [];
            $rowQty = 0.0;

            foreach ($sizes as $size) {
                $variant = $variants->first(fn (ProductVariant $v) => $this->attr($v, self::SIZE_KEY) === $size
                    && $this->attr($v, self::COLOUR_KEY) === $colourName);

                $qty = $variant ? (float) ($stock[$variant->id] ?? 0) : 0.0;
                $rowQty += $qty;

                $cells[] = [
                    'size' => $size,
                    'variant_id' => $variant?->id,
                    'sku' => $variant?->sku,
                    'barcode' => $variant?->barcode,
                    'selling_price' => $variant ? (float) $variant->selling_price : null,
                    'is_active' => $variant ? (bool) $variant->is_active : null,
                    'quantity' => $qty,
                ];
            }

            $rows[] = $colour + ['cells' => $cells, 'quantity' => round($rowQty, 3)];
        }

        return [
            'product' => [
                'id' => $product->id,
                'sku' => $product->sku,
                'name' => $product->name,
                'has_variants' => (bool) $product->has_variants,
                'selling_price' => (float) $product->selling_price,
                'cost_price' => (float) $product->cost_price,
                'style_id' => $product->style_id,
            ],
            'sizes' => $sizes,
            'rows' => $rows,
            // Column totals, which is the number a garment buyer actually reads
            // the grid for: "we are out of M across the board".
            'size_totals' => array_map(
                fn (string $size) => round((float) collect($rows)
                    ->sum(fn (array $row) => collect($row['cells'])->firstWhere('size', $size)['quantity'] ?? 0), 3),
                $sizes,
            ),
            'total_quantity' => round((float) collect($rows)->sum('quantity'), 3),
        ];
    }

    /**
     * Existing cells, keyed size|colour, so generation can skip what is there
     * without a query per cell.
     *
     * @return array<string, int>
     */
    private function indexExisting(Product $product): array
    {
        $out = [];

        foreach ($product->variants()->get(['id', 'attributes']) as $variant) {
            $size = $this->attr($variant, self::SIZE_KEY);
            $colour = $this->attr($variant, self::COLOUR_KEY);

            if ($size !== null && $colour !== null) {
                $out[$this->cellKey($size, $colour)] = $variant->id;
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

    private function attr(ProductVariant $variant, string $key): ?string
    {
        $value = $variant->attributes[$key] ?? null;

        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    private function cellKey(string $size, string $colour): string
    {
        return strtoupper($size).'|'.strtoupper($colour);
    }

    /**
     * Build the variant SKU, and make it unique.
     *
     * The pattern is the parent SKU plus size and colour code, which is what a
     * shop expects to read off a tag. A collision is rare but must not silently
     * merge two SKUs, so a numeric suffix is appended rather than the row being
     * skipped — the variant's identity in the grid is size × colour, not its SKU.
     */
    private function uniqueSku(Product $product, string $size, array $colour): string
    {
        $part = static fn (string $v) => strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $v) ?: '');

        $base = trim(implode('-', array_filter([
            $product->sku ?: 'STY'.$product->id,
            $part($size),
            $part($colour['code'] ?? $colour['name']),
        ])), '-');

        $sku = $base;
        $n = 1;

        while (ProductVariant::where('sku', $sku)->exists()) {
            $sku = $base.'-'.(++$n);
        }

        return $sku;
    }

    /** @return list<string> */
    private function cleanSizes(array $sizes): array
    {
        $out = [];
        foreach ($sizes as $size) {
            $clean = strtoupper(trim((string) $size));
            if ($clean !== '' && ! in_array($clean, $out, true)) {
                $out[] = $clean;
            }
        }

        return $out;
    }

    /** @return list<array{name: string, code: ?string, hex: ?string}> */
    private function cleanColours(array $colours): array
    {
        $out = [];
        $seen = [];

        foreach ($colours as $colour) {
            // Accepts either a plain name or the full row from the colour master,
            // so the screen can send whichever it has without a shape negotiation.
            $row = is_array($colour) ? $colour : ['name' => $colour];
            $name = trim((string) ($row['name'] ?? ''));

            if ($name === '' || in_array(strtoupper($name), $seen, true)) {
                continue;
            }

            $seen[] = strtoupper($name);
            $out[] = [
                'name' => $name,
                'code' => isset($row['code']) && trim((string) $row['code']) !== '' ? (string) $row['code'] : null,
                'hex' => isset($row['hex']) && trim((string) $row['hex']) !== '' ? (string) $row['hex'] : null,
            ];
        }

        return $out;
    }
}
