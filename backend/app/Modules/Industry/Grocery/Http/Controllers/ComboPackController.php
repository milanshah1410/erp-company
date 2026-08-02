<?php

declare(strict_types=1);

namespace App\Modules\Industry\Grocery\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Warehouse;
use App\Modules\Industry\Grocery\Http\Requests\ComboPackRequest;
use App\Modules\Industry\Grocery\Models\ComboPack;
use App\Modules\Industry\Grocery\Models\ComboPackItem;
use App\Modules\Industry\Grocery\Services\ComboPackService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Combo packs: "1kg sugar + 1kg ghee + 500g dry fruit for ₹999".
 *
 * The list is priced three ways on purpose, because on a two-percent trade all
 * three are needed to see whether a pack is a good idea: what the pack sells for,
 * what its contents are worth separately (the gap is the offer), and what they
 * cost the shop (the gap is the money). A pack whose margin has gone negative is
 * a component price rise nobody noticed, and it is flagged rather than merely
 * displayed.
 *
 * "Buildable" is the fourth number and the one the counter asks for: how many
 * packs could be made from what is on the shelf right now. See
 * ComboPackService::buildableQuantities.
 */
class ComboPackController extends Controller
{
    public function __construct(private readonly ComboPackService $packs) {}

    public function index(Request $request)
    {
        $query = ComboPack::query()
            ->with([
                'product:id,sku,name,selling_price,cost_price,tax_percent,is_active',
                'items.product:id,sku,name,selling_price,cost_price',
            ])
            ->withCount('items');

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhereHas('product', fn ($p) => $p->where('name', 'like', "%{$search}%")
                        ->orWhere('sku', 'like', "%{$search}%"));
            });
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $query->orderByDesc('id');

        $page = $query->paginate(min((int) $request->query('per_page', 25), 100));

        // One stock query for the whole page rather than one per row.
        $warehouseId = $this->warehouseId($request);
        $buildable = $warehouseId
            ? $this->packs->buildableQuantities(
                $page->getCollection()->pluck('product_id')->map(fn ($id) => (int) $id)->all(),
                $warehouseId,
            )
            : [];

        return response()->json(
            $page->through(fn (ComboPack $pack) => $this->row($pack, $buildable))->toArray()
            + [
                'summary' => $this->summary(),
                'warehouse_id' => $warehouseId,
            ],
        );
    }

    public function show(Request $request, ComboPack $comboPack)
    {
        $comboPack->load([
            'product:id,sku,name,selling_price,cost_price,tax_percent',
            'items.product:id,sku,name,selling_price,cost_price',
            'items.variant:id,sku,name',
        ]);

        $warehouseId = $this->warehouseId($request);
        $buildable = $warehouseId
            ? $this->packs->buildableQuantities([(int) $comboPack->product_id], $warehouseId)
            : [];

        return response()->json([
            'data' => $this->row($comboPack, $buildable, withItems: true),
        ]);
    }

    public function store(ComboPackRequest $request)
    {
        $data = $request->validated();

        $pack = DB::transaction(function () use ($request, $data) {
            $pack = ComboPack::create([
                'product_id' => $data['product_id'],
                'name' => $data['name'],
                'starts_on' => $data['starts_on'] ?? null,
                'ends_on' => $data['ends_on'] ?? null,
                'is_active' => $data['is_active'] ?? true,
                'notes' => $data['notes'] ?? null,
                'created_by' => $request->user()?->id,
            ]);

            $this->syncItems($pack, $data['items']);

            return $pack;
        });

        return response()->json([
            'data' => $this->row($pack->fresh(['product', 'items.product']), [], withItems: true),
        ], 201);
    }

    public function update(ComboPackRequest $request, ComboPack $comboPack)
    {
        $data = $request->validated();

        DB::transaction(function () use ($comboPack, $data) {
            $comboPack->update([
                'product_id' => $data['product_id'],
                'name' => $data['name'],
                'starts_on' => $data['starts_on'] ?? null,
                'ends_on' => $data['ends_on'] ?? null,
                'is_active' => $data['is_active'] ?? true,
                'notes' => $data['notes'] ?? null,
            ]);

            /**
             * Replaced wholesale rather than diffed. A pack is a short list
             * rewritten as a whole, and matching by product would mangle one that
             * legitimately lists the same item twice ("two bars of soap" written
             * as two lines).
             */
            $comboPack->items()->delete();
            $this->syncItems($comboPack, $data['items']);
        });

        return response()->json([
            'data' => $this->row($comboPack->fresh(['product', 'items.product']), [], withItems: true),
        ]);
    }

    public function destroy(ComboPack $comboPack)
    {
        /**
         * The product is kept — it has a price, a tax rate, a barcode and
         * probably history on old bills. Deleting the definition is what stops
         * the till breaking apart a pack that no longer exists; the SKU simply
         * becomes an ordinary product again.
         */
        $comboPack->delete();

        return response()->json(['message' => 'Combo pack deleted.']);
    }

    /**
     * Write the component lines and snapshot what they are worth and what they
     * cost, so the list never has to price a pack per row.
     *
     * @param  array<int, array<string, mixed>>  $items
     */
    private function syncItems(ComboPack $pack, array $items): void
    {
        foreach ($items as $index => $line) {
            $pack->items()->create([
                'product_id' => $line['product_id'],
                'variant_id' => $line['variant_id'] ?? null,
                'quantity' => $line['quantity'],
                'sort_order' => $line['sort_order'] ?? $index,
            ]);
        }

        $pack->load('items.product:id,selling_price,cost_price');
        $pack->revalue();
    }

    /**
     * Warehouse the buildable count is answered for: the one asked for, else the
     * workspace's default. Null when the tenant has no warehouse at all, in which
     * case the column is simply absent rather than a misleading zero.
     */
    private function warehouseId(Request $request): ?int
    {
        if ($request->filled('warehouse_id')) {
            return (int) $request->query('warehouse_id');
        }

        // There is no default-warehouse flag on the master, so the oldest active
        // godown stands in — which for a kirana is the only one it has.
        $warehouse = Warehouse::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->first(['id']);

        return $warehouse?->id;
    }

    private function summary(): array
    {
        $packs = ComboPack::query()->with('product:id,selling_price')->get();

        return [
            'packs' => $packs->count(),
            'live' => $packs->filter(fn (ComboPack $p) => $p->isLiveOn())->count(),
            /**
             * Packs priced at or above what their contents are worth separately.
             * Not an error — a shop may charge for the convenience of a made-up
             * hamper — but it is the list an owner wants to look at.
             */
            'no_saving' => $packs->filter(fn (ComboPack $p) => $p->savingAmount() <= 0)->count(),
            /**
             * Packs sold below what their contents cost. This one *is* an error,
             * and on this trade an expensive one.
             */
            'below_cost' => $packs->filter(fn (ComboPack $p) => $p->marginAmount() < 0)->count(),
        ];
    }

    /**
     * @param  array<int, float>  $buildable  pack product id => buildable packs
     */
    private function row(ComboPack $pack, array $buildable, bool $withItems = false): array
    {
        $price = $pack->price();
        $value = round((float) $pack->component_value, 2);
        $cost = round((float) $pack->component_cost, 2);
        $margin = $pack->marginAmount();

        return [
            'id' => $pack->id,
            'product_id' => $pack->product_id,
            'sku' => $pack->product?->sku,
            'name' => $pack->name,
            'product_name' => $pack->product?->name,
            'price' => $price,
            'component_value' => $value,
            'saving' => round($value - $price, 2),
            'saving_percent' => $value > 0 ? round(($value - $price) / $value * 100, 1) : null,
            'component_cost' => $cost,
            'margin' => $margin,
            // Null rather than zero when nothing is priced: an unknown margin
            // reported as 0% reads as "we make nothing on this".
            'margin_percent' => $price > 0 ? round($margin / $price * 100, 1) : null,
            'starts_on' => $pack->starts_on?->toDateString(),
            'ends_on' => $pack->ends_on?->toDateString(),
            'is_active' => (bool) $pack->is_active,
            // Active and inside its window — what the till actually acts on.
            'is_live' => $pack->isLiveOn(),
            'buildable' => $buildable[(int) $pack->product_id] ?? null,
            'notes' => $pack->notes,
            'items_count' => $pack->items_count ?? $pack->items->count(),
            'items' => $withItems ? $pack->items->map(fn (ComboPackItem $item) => [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'item' => $item->product?->name,
                'sku' => $item->product?->sku,
                'variant_id' => $item->variant_id,
                'quantity' => (float) $item->quantity,
                'unit_price' => (float) ($item->product?->selling_price ?? 0),
                'unit_cost' => (float) ($item->product?->cost_price ?? 0),
                'sort_order' => (int) $item->sort_order,
            ])->values() : null,
        ];
    }
}
