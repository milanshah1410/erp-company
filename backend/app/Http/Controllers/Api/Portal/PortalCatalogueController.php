<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Portal;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Product;
use App\Models\WarehouseStock;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Read-only storefront for the B2B portal (SRS §14): products priced at the
 * customer's effective tier (with per-customer overrides) and live stock
 * availability. Both the catalogue and the price list are served from here.
 */
class PortalCatalogueController extends Controller
{
    /** Browse the catalogue: priced, stock-aware, searchable, paginated. */
    public function index(Request $request): JsonResponse
    {
        $query = Product::query()->where('is_active', true)
            ->with(['unit:id,name', 'category:id,name', 'brand:id,name', 'variants']);

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%")
                    ->orWhere('barcode', 'like', "%{$search}%");
            });
        }
        if ($request->filled('category_id')) {
            $query->where('category_id', $request->query('category_id'));
        }

        return $this->paginated($query->orderBy('name'), $request, $request->user());
    }

    /** The full price list (all active products at the customer's prices). */
    public function priceList(Request $request): JsonResponse
    {
        $query = Product::query()->where('is_active', true)
            ->with(['unit:id,name', 'category:id,name', 'variants'])
            ->orderBy('name');

        if ($search = $request->query('search')) {
            $query->where('name', 'like', "%{$search}%");
        }

        return $this->paginated($query, $request, $request->user());
    }

    /** A single product with its variants, prices and per-warehouse availability. */
    public function show(Request $request, Product $product): JsonResponse
    {
        abort_unless($product->is_active, 404);

        $product->load(['unit:id,name', 'category:id,name', 'brand:id,name', 'variants']);
        $stock = $this->stockMap([$product->id]);

        return response()->json(['data' => $this->mapProduct($product, $request->user(), $stock)]);
    }

    /** Paginate a product query and map each row into a priced catalogue entry. */
    private function paginated(Builder $query, Request $request, Customer $customer): JsonResponse
    {
        $perPage = min((int) $request->query('per_page', 20), 100);
        /** @var LengthAwarePaginator $products */
        $products = $query->paginate($perPage);

        $stock = $this->stockMap($products->getCollection()->pluck('id')->all());

        $onlyInStock = $request->boolean('in_stock');
        $rows = $products->getCollection()
            ->map(fn (Product $p) => $this->mapProduct($p, $customer, $stock))
            ->when($onlyInStock, fn (Collection $c) => $c->where('stock_status', '!=', 'out')->values());

        return response()->json([
            'data' => $rows,
            'meta' => [
                'current_page' => $products->currentPage(),
                'last_page' => $products->lastPage(),
                'per_page' => $products->perPage(),
                'total' => $products->total(),
            ],
        ]);
    }

    /**
     * Available quantity (on-hand minus reserved) per product and per variant,
     * summed across every warehouse, in one grouped query.
     *
     * @param  array<int>  $productIds
     * @return array<int, array{product: float, variants: array<int, float>}>
     */
    private function stockMap(array $productIds): array
    {
        if (empty($productIds)) {
            return [];
        }

        $map = [];
        WarehouseStock::query()
            ->whereIn('product_id', $productIds)
            ->selectRaw('product_id, variant_id, SUM(quantity - reserved_quantity) as available')
            ->groupBy('product_id', 'variant_id')
            ->get()
            ->each(function ($row) use (&$map) {
                $pid = (int) $row->product_id;
                $available = (float) $row->available;
                $map[$pid] ??= ['product' => 0.0, 'variants' => []];
                $map[$pid]['product'] += $available;
                if ($row->variant_id !== null) {
                    $map[$pid]['variants'][(int) $row->variant_id] = $available;
                }
            });

        return $map;
    }

    /** Build one catalogue entry: customer price, live stock and variants. */
    private function mapProduct(Product $product, Customer $customer, array $stock): array
    {
        $available = round((float) ($stock[$product->id]['product'] ?? 0), 3);
        $reorder = (float) $product->reorder_level;

        return [
            'id' => $product->id,
            'sku' => $product->sku,
            'name' => $product->name,
            'description' => $product->description,
            'image' => $product->image,
            'unit' => $product->unit?->name,
            'category' => $product->category?->name,
            'hsn_code' => $product->hsn_code,
            'tax_percent' => (float) $product->tax_percent,
            'has_variants' => (bool) $product->has_variants,
            'price' => $customer->priceFor($product, null, 1),
            'mrp' => (float) $product->selling_price,
            'available_quantity' => $available,
            'stock_status' => $this->stockStatus($available, $reorder),
            'variants' => $product->variants->map(function ($variant) use ($product, $customer, $stock, $reorder) {
                $vAvailable = round((float) ($stock[$product->id]['variants'][$variant->id] ?? 0), 3);

                return [
                    'id' => $variant->id,
                    'name' => $variant->name,
                    'sku' => $variant->sku,
                    'price' => $customer->priceFor($product, $variant->id, 1),
                    'available_quantity' => $vAvailable,
                    'stock_status' => $this->stockStatus($vAvailable, $reorder),
                ];
            })->values(),
        ];
    }

    /** Live-stock badge: out / low (at or below reorder level) / in. */
    private function stockStatus(float $available, float $reorderLevel): string
    {
        if ($available <= 0) {
            return 'out';
        }
        if ($reorderLevel > 0 && $available <= $reorderLevel) {
            return 'low';
        }

        return 'in';
    }
}
