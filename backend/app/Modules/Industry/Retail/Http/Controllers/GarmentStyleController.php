<?php

declare(strict_types=1);

namespace App\Modules\Industry\Retail\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\WarehouseStock;
use App\Modules\Industry\Retail\Http\Requests\GarmentStyleRequest;
use App\Modules\Industry\Retail\Models\GarmentStyle;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The style master.
 *
 * A style is the level the whole trade talks at — "SS-2214" is what the buyer
 * ordered, what the supplier invoiced and what gets marked down in January — and
 * it is not a product, because by the time it reaches the shelf it is thirty
 * products, one per size × colour.
 *
 * So the interesting endpoints here are not the CRUD ones. They are `attach`,
 * which puts a product under a style, and the stock roll-up on `show`, which is
 * the only place in the system that can answer "how much of SS-2214 do we have
 * left, across every size and colour?" — the question a garment shop actually
 * asks.
 */
class GarmentStyleController extends Controller
{
    public function index(Request $request)
    {
        $query = GarmentStyle::query()->with([
            'category:id,name', 'brand:id,name', 'fabric:id,code,name', 'sizeSet:id,name,sizes',
        ])->withCount('products');

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('code', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhere('season', 'like', "%{$search}%");
            });
        }

        foreach (['category_id', 'brand_id', 'fabric_id', 'gender', 'season'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->query($filter));
            }
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $query->orderByDesc('id');

        return response()->json(
            $query->paginate(min((int) $request->query('per_page', 25), 100))
                ->through(fn (GarmentStyle $s) => $this->row($s))->toArray()
                + ['summary' => $this->summary()],
        );
    }

    public function store(GarmentStyleRequest $request)
    {
        $style = GarmentStyle::create($request->validated() + ['created_by' => $request->user()?->id]);

        return response()->json(['data' => $this->row($this->loaded($style))], 201);
    }

    /**
     * One style with the products cut from it and what is left of each.
     *
     * The stock figure is the roll-up across every variant of every product on
     * the style — which is the number a buyer reorders on, and one that cannot
     * be read off any single product row.
     */
    public function show(GarmentStyle $style)
    {
        $style = $this->loaded($style);
        $products = $style->products()->with('unit:id,short_name')->get();

        $stock = WarehouseStock::query()
            ->whereIn('product_id', $products->pluck('id'))
            ->selectRaw('product_id, SUM(quantity - reserved_quantity) as available')
            ->groupBy('product_id')
            ->pluck('available', 'product_id');

        return response()->json([
            'data' => $this->row($style, detailed: true) + [
                'products' => $products->map(fn (Product $p) => [
                    'id' => $p->id,
                    'sku' => $p->sku,
                    'name' => $p->name,
                    'has_variants' => (bool) $p->has_variants,
                    'variant_count' => $p->variants()->count(),
                    'selling_price' => (float) $p->selling_price,
                    'quantity' => round((float) ($stock[$p->id] ?? 0), 3),
                ])->values(),
                'total_quantity' => round((float) $stock->sum(), 3),
            ],
        ]);
    }

    public function update(GarmentStyleRequest $request, GarmentStyle $style)
    {
        $style->update($request->validated());

        return response()->json(['data' => $this->row($this->loaded($style->fresh()))]);
    }

    public function destroy(GarmentStyle $style)
    {
        // Deleting would null out `style_id` on every product cut from it
        // (nullOnDelete), quietly detaching the stock from the thing it is
        // reordered and marked down by. Deactivating keeps the history.
        if ($style->products()->exists()) {
            return response()->json([
                'message' => 'Products are cut from this style. Mark it inactive instead of deleting it.',
            ], 422);
        }

        $style->delete();

        return response()->json(['message' => 'Style removed from the master.']);
    }

    /**
     * Put products under this style, or take them out of it.
     *
     * A separate endpoint rather than a field on the product form because this
     * is a bulk act: a buyer receiving a style attaches the whole delivery at
     * once, and doing it one product screen at a time is how half a style ends
     * up unlinked.
     */
    public function attach(Request $request, GarmentStyle $style)
    {
        $data = $request->validate([
            'product_ids' => ['required', 'array', 'min:1'],
            'product_ids.*' => ['integer', 'exists:products,id'],
            'detach' => ['nullable', 'boolean'],
        ]);

        $detaching = (bool) ($data['detach'] ?? false);

        if ($detaching) {
            // Scoped to this style: a product sitting under a *different* style
            // must not be detached by a mis-sent id.
            $affected = Product::whereIn('id', $data['product_ids'])
                ->where('style_id', $style->id)
                ->update(['style_id' => null]);
        } else {
            $affected = Product::whereIn('id', $data['product_ids'])->update(['style_id' => $style->id]);
        }

        return response()->json([
            'message' => $detaching
                ? "{$affected} product(s) detached from {$style->code}."
                : "{$affected} product(s) attached to {$style->code}.",
            'data' => $this->row($this->loaded($style->fresh())),
        ]);
    }

    /**
     * Create the product a style is sold as, in one step.
     *
     * A style with no product behind it cannot be billed, and the fastest route
     * from "we bought this style" to "we can sell it" is: create the style,
     * create its product here, run the matrix. Doing it through the ordinary
     * Products screen means re-keying the name, the price, the brand and the
     * category that are already on the style.
     */
    public function createProduct(Request $request, GarmentStyle $style)
    {
        $data = $request->validate([
            'sku' => ['nullable', 'string', 'max:100'],
            'name' => ['nullable', 'string', 'max:255'],
            'unit_id' => ['nullable', 'exists:units,id'],
            'hsn_code' => ['nullable', 'string', 'max:20'],
            'tax_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        $sku = strtoupper(trim((string) ($data['sku'] ?? $style->code)));

        if (Product::withTrashed()->where('sku', $sku)->exists()) {
            throw ValidationException::withMessages([
                'sku' => ["A product with SKU \"{$sku}\" already exists. Give this one a different code."],
            ]);
        }

        $product = Product::create([
            'sku' => $sku,
            'name' => $data['name'] ?? $style->name,
            'style_id' => $style->id,
            'category_id' => $style->category_id,
            'brand_id' => $style->brand_id,
            'unit_id' => $data['unit_id'] ?? null,
            'hsn_code' => $data['hsn_code'] ?? null,
            'tax_percent' => $data['tax_percent'] ?? 0,
            'cost_price' => $style->cost_price,
            'selling_price' => $style->mrp,
            'description' => $style->description,
            'is_active' => true,
        ]);

        return response()->json([
            'message' => "Product {$product->sku} created. Build its size & colour grid next.",
            'data' => ['id' => $product->id, 'sku' => $product->sku, 'name' => $product->name],
        ], 201);
    }

    private function loaded(GarmentStyle $style): GarmentStyle
    {
        return $style->load([
            'category:id,name', 'brand:id,name', 'fabric:id,code,name', 'sizeSet:id,name,sizes',
        ])->loadCount('products');
    }

    private function summary(): array
    {
        return [
            'styles' => GarmentStyle::count(),
            'active' => GarmentStyle::where('is_active', true)->count(),
            // Styles nobody can sell yet: the gap between buying a range and
            // having it on the floor, which is exactly the list a merchandiser
            // wants first thing.
            'without_products' => GarmentStyle::whereDoesntHave('products')->count(),
        ];
    }

    private function row(GarmentStyle $s, bool $detailed = false): array
    {
        $base = [
            'id' => $s->id,
            'code' => $s->code,
            'name' => $s->name,
            'category_id' => $s->category_id,
            'category_name' => $s->category?->name,
            'brand_id' => $s->brand_id,
            'brand_name' => $s->brand?->name,
            'fabric_id' => $s->fabric_id,
            'fabric_name' => $s->fabric?->name,
            'size_set_id' => $s->size_set_id,
            'size_set_name' => $s->sizeSet?->name,
            'sizes' => $s->sizeSet?->sizeList() ?? [],
            'gender' => $s->gender,
            'season' => $s->season,
            'mrp' => (float) $s->mrp,
            'cost_price' => (float) $s->cost_price,
            'products_count' => (int) ($s->products_count ?? 0),
            'is_active' => (bool) $s->is_active,
        ];

        if (! $detailed) {
            return $base;
        }

        return $base + [
            'fit' => $s->fit,
            'sleeve' => $s->sleeve,
            'neck' => $s->neck,
            'pattern' => $s->pattern,
            'image' => $s->image,
            'description' => $s->description,
            'fabric' => $s->fabric ? [
                'code' => $s->fabric->code,
                'name' => $s->fabric->name,
                'composition' => $s->fabric->composition,
                'gsm' => $s->fabric->gsm,
                'care' => $s->fabric->care,
            ] : null,
        ];
    }
}
