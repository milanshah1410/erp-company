<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProductStoneRequest;
use App\Http\Resources\ProductStoneResource;
use App\Models\Product;
use App\Models\ProductStone;

/**
 * The stone register of one ornament.
 *
 * Nested under the product and gated on the product permissions rather than on
 * a pair of its own: describing what is set into a ring is part of maintaining
 * the ring, and a shop that trusts someone to price the piece already trusts
 * them with its stones.
 *
 * Every write ends in {@see Product::syncStoneTotals()} — the product's
 * `stone_value` is what the pricing service bills, so a line that moved without
 * the total moving would put the wrong number on the next invoice.
 */
class ProductStoneController extends Controller
{
    public function index(Product $product)
    {
        return ProductStoneResource::collection(
            $product->stones()->orderByDesc('carat_weight')->orderBy('id')->get()
        );
    }

    public function store(ProductStoneRequest $request, Product $product)
    {
        $stone = $product->stones()->create($this->resolved($request));
        $product->syncStoneTotals();

        return (new ProductStoneResource($stone))->response()->setStatusCode(201);
    }

    public function update(ProductStoneRequest $request, Product $product, ProductStone $stone)
    {
        if ($stone->product_id !== $product->id) {
            return response()->json(['message' => 'Stone does not belong to this product.'], 404);
        }

        $stone->update($this->resolved($request));
        $product->syncStoneTotals();

        return new ProductStoneResource($stone);
    }

    public function destroy(Product $product, ProductStone $stone)
    {
        if ($stone->product_id !== $product->id) {
            return response()->json(['message' => 'Stone does not belong to this product.'], 404);
        }

        $stone->delete();
        $product->syncStoneTotals();

        return response()->json(['message' => 'Stone deleted.']);
    }

    /**
     * Fill in the line value from its own rate unless the shop typed one.
     *
     * Done on a throwaway model rather than by repeating the match: the rule for
     * what a line is worth belongs to {@see ProductStone::computedValue()}, and
     * two copies of it would drift the first time a rate type is added.
     */
    private function resolved(ProductStoneRequest $request): array
    {
        $data = $request->validated();

        if (($data['value'] ?? null) === null) {
            $data['value'] = (new ProductStone($data))->computedValue();
        }

        return $data;
    }
}
