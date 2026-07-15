<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProductRequest;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Support\TenantStorage;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $query = Product::query()->with(['category', 'brand', 'unit']);

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%")
                    ->orWhere('barcode', 'like', "%{$search}%")
                    ->orWhere('rfid_tag', 'like', "%{$search}%");
            });
        }

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->query('category_id'));
        }
        if ($request->filled('brand_id')) {
            $query->where('brand_id', $request->query('brand_id'));
        }
        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $sort = $request->query('sort', 'created_at');
        $direction = $request->query('direction', 'desc');
        if (in_array($sort, ['name', 'sku', 'selling_price', 'cost_price', 'created_at'], true)) {
            $query->orderBy($sort, $direction === 'asc' ? 'asc' : 'desc');
        }

        $perPage = min((int) $request->query('per_page', 15), 100);

        return ProductResource::collection($query->paginate($perPage));
    }

    public function store(ProductRequest $request)
    {
        $product = Product::create($request->validated());

        return (new ProductResource($product->load(['category', 'brand', 'unit'])))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Product $product)
    {
        return new ProductResource($product->load(['category', 'brand', 'unit', 'variants', 'stocks']));
    }

    public function update(ProductRequest $request, Product $product)
    {
        $product->update($request->validated());

        return new ProductResource($product->load(['category', 'brand', 'unit']));
    }

    public function destroy(Product $product)
    {
        $product->delete();

        return response()->json(['message' => 'Product deleted.']);
    }

    /** Upload (or replace) the product image. Stored on the public disk. */
    public function uploadImage(Request $request, Product $product)
    {
        $request->validate([
            'image' => ['required', 'image', 'max:2048'], // 2 MB
        ]);

        // Replace: remove the previous file if it lives on our disk.
        $disk = TenantStorage::disk();
        if ($product->image && $disk->exists($product->image)) {
            $disk->delete($product->image);
        }

        $path = $disk->putFile('products', $request->file('image'));
        $product->update(['image' => $path]);

        return new ProductResource($product->load(['category', 'brand', 'unit']));
    }
}
