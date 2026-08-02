<?php

declare(strict_types=1);

namespace App\Modules\Industry\Retail\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Modules\Industry\Retail\Models\GarmentColour;
use App\Modules\Industry\Retail\Models\GarmentSizeSet;
use App\Modules\Industry\Retail\Services\VariantMatrixService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * The size × colour grid for one product.
 *
 * Two endpoints and no state of its own: `show` reconstructs the grid from the
 * product's variants, and `generate` fills in the cells that are missing. The
 * matrix owns no table because it is a way of *entering* and *reading* ordinary
 * product variants, not a second variant system — see VariantMatrixService.
 *
 * Gated on products.view / products.edit rather than a permission of its own:
 * generating a grid creates product variants, and anyone allowed to do that from
 * the Products screen is allowed to do it faster from here. A separate
 * permission would only mean a shop could grant one route to the catalogue and
 * not the other, which is a distinction nobody in this trade draws.
 */
class GarmentMatrixController extends Controller
{
    public function __construct(private readonly VariantMatrixService $matrix) {}

    /**
     * The grid as it stands, plus the pickers needed to extend it.
     *
     * Column order comes from the style's size run where the product has a style
     * behind it, so the grid reads XS→XXXL rather than in variant-id order; any
     * size found on a variant but missing from the run is appended, so a variant
     * keyed by hand before the matrix existed is still visible.
     */
    public function show(Request $request, Product $product)
    {
        $request->validate(['warehouse_id' => ['nullable', 'exists:warehouses,id']]);

        $style = $product->style_id ? $product->style : null;
        $preferred = $style?->sizeSet?->sizeList() ?? [];

        return response()->json(
            $this->matrix->grid($product, $preferred, $request->integer('warehouse_id') ?: null)
                + [
                    'style' => $style ? [
                        'id' => $style->id,
                        'code' => $style->code,
                        'name' => $style->name,
                        'size_set_id' => $style->size_set_id,
                        'mrp' => (float) $style->mrp,
                        'cost_price' => (float) $style->cost_price,
                    ] : null,
                    // The two axes to build from, so the screen needs one call.
                    'size_sets' => GarmentSizeSet::where('is_active', true)->orderBy('name')->get()
                        ->map(fn (GarmentSizeSet $s) => [
                            'id' => $s->id,
                            'name' => $s->name,
                            'sizes' => $s->sizeList(),
                        ])->values(),
                    'colours' => GarmentColour::where('is_active', true)
                        ->orderBy('sort_order')->orderBy('name')
                        ->get(['id', 'name', 'code', 'hex']),
                ],
        );
    }

    /**
     * Create the missing cells.
     *
     * Additive and idempotent: re-running after adding a colour fills in that
     * row and leaves everything else alone. The alternative — wipe and recreate —
     * would destroy the barcodes on the shelf and the stock attached to them, so
     * it is not offered at all.
     */
    public function generate(Request $request, Product $product)
    {
        $data = $request->validate([
            // Either name a stored run, or send sizes directly. Both are allowed
            // because a one-off style ("this jacket comes in S/M/L only") should
            // not require polluting the master with a run used once.
            'size_set_id' => ['nullable', 'exists:garment_size_sets,id'],
            'sizes' => ['nullable', 'array', 'max:40'],
            'sizes.*' => ['string', 'max:12'],
            'colour_ids' => ['nullable', 'array', 'max:40'],
            'colour_ids.*' => ['integer', 'exists:garment_colours,id'],
            'colours' => ['nullable', 'array', 'max:40'],
            'colours.*.name' => ['required', 'string', 'max:60'],
            'colours.*.code' => ['nullable', 'string', 'max:12'],
            'colours.*.hex' => ['nullable', 'string', 'max:7'],
            'cost_price' => ['nullable', 'numeric', 'min:0'],
            'selling_price' => ['nullable', 'numeric', 'min:0'],
        ]);

        $sizes = $data['sizes'] ?? [];
        if ($sizes === [] && ! empty($data['size_set_id'])) {
            $sizes = GarmentSizeSet::findOrFail($data['size_set_id'])->sizeList();
        }

        $colours = $data['colours'] ?? [];
        if (! empty($data['colour_ids'])) {
            $colours = array_merge(
                $colours,
                GarmentColour::whereIn('id', $data['colour_ids'])->orderBy('sort_order')->orderBy('name')
                    ->get()
                    ->map(fn (GarmentColour $c) => ['name' => $c->name, 'code' => $c->code, 'hex' => $c->hex])
                    ->all(),
            );
        }

        /**
         * Price defaults follow the style, not the product, when the product has
         * one. In this trade a style has one price across every size and colour,
         * and the style is where a merchandiser sets it — so a grid generated
         * after a markdown picks the markdown up.
         */
        $style = $product->style_id ? $product->style : null;
        $pricing = [
            'cost_price' => $data['cost_price'] ?? ($style ? (float) $style->cost_price : null),
            'selling_price' => $data['selling_price'] ?? ($style ? (float) $style->mrp : null),
        ];

        try {
            $result = $this->matrix->generate($product, $sizes, $colours, array_filter(
                $pricing,
                static fn ($v) => $v !== null,
            ));
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['sizes' => [$e->getMessage()]]);
        }

        return response()->json([
            'message' => $result['created'] === 0
                ? 'Every one of those cells already exists.'
                : "{$result['created']} variant(s) created".
                    ($result['skipped'] > 0 ? ", {$result['skipped']} already existed." : '.'),
            'created' => $result['created'],
            'skipped' => $result['skipped'],
            'data' => $this->matrix->grid(
                $product->fresh(),
                $style?->sizeSet?->sizeList() ?? [],
            ),
        ], 201);
    }
}
