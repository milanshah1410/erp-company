<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\BomRequest;
use App\Http\Resources\BomResource;
use App\Models\Bom;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Bills of Materials: reusable recipes of raw-material components for a
 * finished product. A manufacturing order can be seeded from a BOM via the
 * explode endpoint, which scales the components to a target quantity.
 */
class BomController extends Controller
{
    public function index(Request $request)
    {
        $query = Bom::query()->with('product')->withCount('items');

        if ($search = $request->query('search')) {
            $query->where(fn ($q) => $q
                ->where('bom_number', 'like', "%{$search}%")
                ->orWhere('name', 'like', "%{$search}%"));
        }
        if ($request->filled('product_id')) {
            $query->where('product_id', $request->query('product_id'));
        }
        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $query->orderByDesc('id');
        $perPage = min((int) $request->query('per_page', 15), 100);

        return BomResource::collection($query->paginate($perPage));
    }

    public function store(BomRequest $request)
    {
        $bom = DB::transaction(function () use ($request) {
            $data = $request->validated();

            $bom = Bom::create([
                'bom_number' => Bom::nextNumber(),
                'name' => $data['name'],
                'product_id' => $data['product_id'],
                'variant_id' => $data['variant_id'] ?? null,
                'output_quantity' => $data['output_quantity'],
                'is_default' => $data['is_default'] ?? false,
                'is_active' => $data['is_active'] ?? true,
                'notes' => $data['notes'] ?? null,
                'created_by' => $request->user()?->id,
            ]);

            $this->syncItems($bom, $data['items']);
            $bom->recalcCost();
            $this->enforceSingleDefault($bom);

            return $bom;
        });

        return (new BomResource($bom->load(['product', 'items.product'])))
            ->response()->setStatusCode(201);
    }

    public function show(Bom $bom)
    {
        return new BomResource($bom->load(['product', 'items.product']));
    }

    public function update(BomRequest $request, Bom $bom)
    {
        DB::transaction(function () use ($request, $bom) {
            $data = $request->validated();

            $bom->update([
                'name' => $data['name'],
                'product_id' => $data['product_id'],
                'variant_id' => $data['variant_id'] ?? null,
                'output_quantity' => $data['output_quantity'],
                'is_default' => $data['is_default'] ?? false,
                'is_active' => $data['is_active'] ?? true,
                'notes' => $data['notes'] ?? null,
            ]);

            $bom->items()->delete();
            $this->syncItems($bom, $data['items']);
            $bom->recalcCost();
            $this->enforceSingleDefault($bom);
        });

        return new BomResource($bom->load(['product', 'items.product']));
    }

    public function destroy(Bom $bom)
    {
        $bom->delete();

        return response()->json(['message' => 'Bill of materials deleted.']);
    }

    /**
     * Explode this BOM to a target production quantity and return the scaled
     * component lines, shaped for a manufacturing order's material grid.
     */
    public function explode(Request $request, Bom $bom)
    {
        $data = $request->validate([
            'quantity' => ['required', 'numeric', 'min:0.001'],
        ]);

        $bom->load('items.product:id,sku,name');
        $lines = $bom->explode((float) $data['quantity']);

        // Attach product display fields so the caller can render names without
        // a second lookup.
        $lines = array_map(function (array $line) use ($bom) {
            $item = $bom->items->firstWhere('product_id', $line['product_id']);
            $line['product'] = $item?->product ? [
                'id' => $item->product->id,
                'sku' => $item->product->sku,
                'name' => $item->product->name,
            ] : null;

            return $line;
        }, $lines);

        return response()->json([
            'data' => [
                'bom_id' => $bom->id,
                'quantity' => (float) $data['quantity'],
                'materials' => $lines,
            ],
        ]);
    }

    /** Create component lines; defaults unit cost to the product's cost price. */
    private function syncItems(Bom $bom, array $items): void
    {
        foreach ($items as $line) {
            $unitCost = isset($line['unit_cost'])
                ? (float) $line['unit_cost']
                : (float) (Product::find($line['product_id'])?->cost_price ?? 0);
            $quantity = (float) $line['quantity'];

            $bom->items()->create([
                'product_id' => $line['product_id'],
                'variant_id' => $line['variant_id'] ?? null,
                'quantity' => $quantity,
                'wastage_percent' => (float) ($line['wastage_percent'] ?? 0),
                'unit_cost' => $unitCost,
                'total_cost' => round($quantity * $unitCost, 2),
                'remarks' => $line['remarks'] ?? null,
            ]);
        }
    }

    /**
     * A product has at most one default BOM: when this one is marked default,
     * clear the flag on its siblings for the same product.
     */
    private function enforceSingleDefault(Bom $bom): void
    {
        if (! $bom->is_default) {
            return;
        }

        Bom::query()
            ->where('product_id', $bom->product_id)
            ->whereKeyNot($bom->id)
            ->where('is_default', true)
            ->update(['is_default' => false]);
    }
}
