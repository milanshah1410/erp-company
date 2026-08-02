<?php

declare(strict_types=1);

namespace App\Modules\Industry\Cosmetics\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Modules\Industry\Cosmetics\Http\Requests\ShadeCardRequest;
use App\Modules\Industry\Cosmetics\Services\ShadeCardService;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * The shade card screen: read the range, then extend it.
 *
 * Two endpoints and one service. Everything interesting is in ShadeCardService,
 * including the decision that generation writes ordinary product variants.
 */
class ShadeCardController extends Controller
{
    public function __construct(private readonly ShadeCardService $cards) {}

    /** The card as it stands, with live stock per shade. */
    public function show(Request $request, Product $product)
    {
        return response()->json([
            'data' => $this->cards->card($product, $request->integer('warehouse_id') ?: null),
            // What the "add shades" picker offers — the shades not already on it.
            'available' => $this->cards->availableShades($product, $request->integer('brand_id') ?: null),
        ]);
    }

    /** Put shades on the card: one variant each, skipping what is already there. */
    public function generate(ShadeCardRequest $request)
    {
        $data = $request->validated();
        $product = Product::findOrFail($data['product_id']);

        try {
            $result = $this->cards->generate($product, $data['shade_ids'], [
                'cost_price' => $data['cost_price'] ?? null,
                'selling_price' => $data['selling_price'] ?? null,
            ]);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => $result['created'] === 0
                // Not an error. Re-running the card is how a buyer checks it is
                // complete, and "nothing to do" is a perfectly good answer.
                ? 'Every shade picked is already on the card.'
                : "{$result['created']} shade(s) added to the card.",
            'created' => $result['created'],
            'skipped' => $result['skipped'],
            'data' => $this->cards->card($product->refresh()),
        ], $result['created'] > 0 ? 201 : 200);
    }
}
