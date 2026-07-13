<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProductVariantRequest;
use App\Http\Resources\ProductVariantResource;
use App\Models\Product;
use App\Models\ProductVariant;

class ProductVariantController extends Controller
{
    public function index(Product $product)
    {
        return ProductVariantResource::collection($product->variants()->orderBy('id')->get());
    }

    public function store(ProductVariantRequest $request, Product $product)
    {
        $variant = $product->variants()->create($request->validated());
        $product->update(['has_variants' => true]);

        return (new ProductVariantResource($variant))->response()->setStatusCode(201);
    }

    public function update(ProductVariantRequest $request, Product $product, ProductVariant $variant)
    {
        if ($variant->product_id !== $product->id) {
            return response()->json(['message' => 'Variant does not belong to this product.'], 404);
        }
        $variant->update($request->validated());

        return new ProductVariantResource($variant);
    }

    public function destroy(Product $product, ProductVariant $variant)
    {
        if ($variant->product_id !== $product->id) {
            return response()->json(['message' => 'Variant does not belong to this product.'], 404);
        }
        $variant->delete();
        $product->update(['has_variants' => $product->variants()->exists()]);

        return response()->json(['message' => 'Variant deleted.']);
    }
}
