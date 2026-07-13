<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CustomerPriceListRequest;
use App\Http\Resources\CustomerPriceListResource;
use App\Models\Customer;
use App\Models\CustomerPriceList;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class CustomerPriceListController extends Controller
{
    public function index(Request $request, Customer $customer)
    {
        $query = $customer->priceListItems()->with(['product', 'variant']);

        if ($request->filled('product_id')) {
            $query->where('product_id', $request->query('product_id'));
        }

        $query->orderBy('product_id')->orderBy('min_quantity');

        return CustomerPriceListResource::collection($query->get());
    }

    public function store(CustomerPriceListRequest $request, Customer $customer)
    {
        $data = $request->validated();
        $data['min_quantity'] = $data['min_quantity'] ?? 1;

        if ($this->duplicateExists($customer, $data)) {
            throw ValidationException::withMessages([
                'product_id' => 'A price for this product/variant at that quantity already exists.',
            ]);
        }

        $item = $customer->priceListItems()->create($data);

        return (new CustomerPriceListResource($item->load(['product', 'variant'])))
            ->response()
            ->setStatusCode(201);
    }

    public function update(CustomerPriceListRequest $request, Customer $customer, CustomerPriceList $priceList)
    {
        $this->assertOwned($customer, $priceList);

        $data = $request->validated();
        $data['min_quantity'] = $data['min_quantity'] ?? 1;

        if ($this->duplicateExists($customer, $data, $priceList->id)) {
            throw ValidationException::withMessages([
                'product_id' => 'A price for this product/variant at that quantity already exists.',
            ]);
        }

        $priceList->update($data);

        return new CustomerPriceListResource($priceList->load(['product', 'variant']));
    }

    public function destroy(Customer $customer, CustomerPriceList $priceList)
    {
        $this->assertOwned($customer, $priceList);
        $priceList->delete();

        return response()->json(['message' => 'Price override deleted.']);
    }

    /**
     * Resolve the effective unit price for a product/variant at a quantity,
     * showing where it came from (customer override, category/tier, or base).
     */
    public function resolve(Request $request, Customer $customer)
    {
        $validated = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'variant_id' => ['nullable', 'integer', 'exists:product_variants,id'],
            'quantity' => ['nullable', 'numeric', 'min:0'],
        ]);

        $product = Product::findOrFail($validated['product_id']);
        $variantId = $validated['variant_id'] ?? null;
        $qty = (float) ($validated['quantity'] ?? 1);

        return response()->json([
            'data' => [
                'product_id' => $product->id,
                'variant_id' => $variantId,
                'quantity' => $qty,
                'price_level' => $customer->effectivePriceLevel(),
                'unit_price' => $customer->priceFor($product, $variantId, $qty),
                'base_price' => (float) $product->selling_price,
            ],
        ]);
    }

    private function duplicateExists(Customer $customer, array $data, ?int $ignoreId = null): bool
    {
        return $customer->priceListItems()
            ->where('product_id', $data['product_id'])
            ->where('variant_id', $data['variant_id'] ?? null)
            ->where('min_quantity', $data['min_quantity'])
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->exists();
    }

    private function assertOwned(Customer $customer, CustomerPriceList $priceList): void
    {
        if ($priceList->customer_id !== $customer->id) {
            throw new NotFoundHttpException('Price override not found for this customer.');
        }
    }
}
