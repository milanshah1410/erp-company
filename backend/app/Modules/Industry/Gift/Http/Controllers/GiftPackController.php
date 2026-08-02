<?php

declare(strict_types=1);

namespace App\Modules\Industry\Gift\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\WarehouseStock;
use App\Modules\Industry\Gift\Http\Requests\GiftPackBuildRequest;
use App\Modules\Industry\Gift\Http\Requests\GiftPackRequest;
use App\Modules\Industry\Gift\Models\GiftPack;
use App\Modules\Industry\Gift\Models\GiftPackBuild;
use App\Modules\Industry\Gift\Services\GiftPackService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Hamper packs: what goes in one, and what happens when a batch is assembled.
 *
 * The list is designed around the question a shop asks in the fortnight before a
 * festival — "can I still build these?" — so every row carries how many complete
 * hampers the contents on hand would make. That number is the shortest possible
 * answer to a week of stock chasing, and it is the reason the screen exists rather
 * than being a tab on the product master.
 *
 * Contents are edited whole: a pack is *designed* as a list, and replacing the set
 * is both what the screen does and what makes an edit idempotent. Sending no
 * `items` key at all leaves the contents alone, which is what a rename is.
 */
class GiftPackController extends Controller
{
    public function __construct(private readonly GiftPackService $packs) {}

    public function index(Request $request)
    {
        $query = GiftPack::query()->with([
            'product:id,sku,name,selling_price,cost_price',
            'wrapOption:id,code,name,kind',
            'items.product:id,sku,name,cost_price,selling_price,is_service',
        ]);

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('code', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhere('occasion_tag', 'like', "%{$search}%")
                    ->orWhereHas('product', fn ($p) => $p->where('name', 'like', "%{$search}%"));
            });
        }

        if ($request->filled('occasion_tag')) {
            $query->where('occasion_tag', $request->query('occasion_tag'));
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $query->orderBy('name');

        return response()->json(
            $query->paginate(min((int) $request->query('per_page', 25), 100))
                ->through(fn (GiftPack $p) => $this->row($p))->toArray()
            + ['summary' => $this->summary()],
        );
    }

    public function show(GiftPack $pack)
    {
        $pack->load([
            'product:id,sku,name,selling_price,cost_price',
            'wrapOption:id,code,name,kind,charge',
            'items.product:id,sku,name,cost_price,selling_price,is_service',
            'items.variant:id,sku,attributes',
            'builds' => fn ($q) => $q->with('warehouse:id,name')->orderByDesc('build_date')->orderByDesc('id')->limit(50),
        ]);

        return response()->json(['data' => $this->row($pack, detailed: true)]);
    }

    public function store(GiftPackRequest $request)
    {
        $data = $request->validated();
        $items = $data['items'] ?? null;
        unset($data['items']);

        $pack = DB::transaction(function () use ($data, $items, $request) {
            $pack = GiftPack::create($data + ['created_by' => $request->user()?->id]);

            if (is_array($items)) {
                $this->syncItems($pack, $items);
            }

            return $pack;
        });

        return response()->json(['data' => $this->row($pack->fresh($this->relations()), detailed: true)], 201);
    }

    public function update(GiftPackRequest $request, GiftPack $pack)
    {
        $data = $request->validated();
        $items = $data['items'] ?? null;
        unset($data['items']);

        DB::transaction(function () use ($pack, $data, $items) {
            $pack->update($data);

            if (is_array($items)) {
                $this->syncItems($pack, $items);
            }
        });

        return response()->json(['data' => $this->row($pack->fresh($this->relations()), detailed: true)]);
    }

    public function destroy(GiftPack $pack)
    {
        /**
         * A posted build is a stock document that points at this pack, and its
         * contents are how the reversal knows what to put back. Deleting the pack
         * would cascade those builds away and leave ledger rows referring to
         * nothing. Deactivate instead — the picker stops offering it and the
         * history survives.
         */
        if ($pack->builds()->whereNotNull('posted_at')->exists()) {
            return response()->json([
                'message' => 'This pack has been built. Switch it off instead of deleting it.',
            ], 422);
        }

        $pack->delete();

        return response()->json(['message' => 'Pack removed.']);
    }

    /**
     * Assemble a batch. Creates the build and posts it in one step — a build that
     * exists but has moved no stock is a half-finished thought, and every screen
     * that reads a hamper's stock would have to know about it.
     */
    public function build(GiftPackBuildRequest $request, GiftPack $pack)
    {
        if (! $pack->is_active) {
            return response()->json(['message' => 'This pack is switched off.'], 422);
        }

        $data = $request->validated();

        try {
            $build = DB::transaction(function () use ($pack, $data, $request) {
                $build = GiftPackBuild::create([
                    'build_number' => GiftPackBuild::nextNumber(),
                    'pack_id' => $pack->id,
                    'quantity' => $data['quantity'],
                    'warehouse_id' => $data['warehouse_id'],
                    'build_date' => $data['build_date'] ?? today(),
                    'notes' => $data['notes'] ?? null,
                    'created_by' => $request->user()?->id,
                ]);

                $build->setRelation('pack', $pack);
                $this->packs->post($build, $request->user()?->id);

                return $build;
            });
        } catch (RuntimeException $e) {
            /**
             * Almost always "insufficient stock" from StockService, which is the
             * useful answer rather than a failure: a build refuses to go negative
             * on purpose (see GiftPackService), so this is the shop being told its
             * contents are short *before* it prices a season off them.
             */
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => $this->buildRow($build->fresh(['warehouse'])),
            'pack' => $this->row($pack->fresh($this->relations()), detailed: true),
            'message' => 'Built '.rtrim(rtrim(number_format((float) $build->quantity, 3, '.', ''), '0'), '.')
                .' × '.$pack->name.'.',
        ], 201);
    }

    /** Every batch of this pack ever assembled. */
    public function builds(Request $request, GiftPack $pack)
    {
        $builds = $pack->builds()
            ->with('warehouse:id,name', 'creator:id,name')
            ->orderByDesc('build_date')
            ->orderByDesc('id')
            ->paginate(min((int) $request->query('per_page', 25), 100))
            ->through(fn (GiftPackBuild $b) => $this->buildRow($b));

        return response()->json($builds->toArray());
    }

    /**
     * Unwind a batch: hampers out, contents back. A miscount gets noticed the next
     * morning, so correcting one is a posted reversal rather than a database edit.
     */
    public function unbuild(Request $request, GiftPack $pack, GiftPackBuild $build)
    {
        if ((int) $build->pack_id !== (int) $pack->id) {
            return response()->json(['message' => 'That build belongs to another pack.'], 404);
        }

        if (! $build->isPosted()) {
            return response()->json(['message' => 'That build has already been reversed.'], 422);
        }

        DB::transaction(function () use ($build, $request) {
            $build->load('pack.items');
            $this->packs->unpost($build, $request->user()?->id);
        });

        return response()->json([
            'data' => $this->buildRow($build->fresh(['warehouse'])),
            'message' => 'Build reversed: the hampers came off stock and their contents went back.',
        ]);
    }

    /** @return list<string> */
    private function relations(): array
    {
        return ['product', 'wrapOption', 'items.product', 'items.variant'];
    }

    /**
     * Replace the contents.
     *
     * Delete-and-recreate is safe here in a way it is not for product variants:
     * a pack item carries no barcode, no stock and no history — the history lives
     * on the builds, which record what a batch actually cost at the time.
     *
     * @param  list<array<string, mixed>>  $items
     */
    private function syncItems(GiftPack $pack, array $items): void
    {
        $pack->items()->delete();

        foreach (array_values($items) as $index => $item) {
            $pack->items()->create([
                'product_id' => $item['product_id'],
                'variant_id' => $item['variant_id'] ?? null,
                'quantity' => $item['quantity'],
                'sort_order' => $index,
                'notes' => $item['notes'] ?? null,
            ]);
        }
    }

    private function summary(): array
    {
        return [
            'packs' => GiftPack::count(),
            'active' => GiftPack::where('is_active', true)->count(),
            'built_this_month' => (float) GiftPackBuild::whereNotNull('posted_at')
                ->whereBetween('build_date', [now()->startOfMonth(), now()->endOfMonth()])
                ->sum('quantity'),
            'buildable' => GiftPack::with('items')->where('is_active', true)->get()
                ->filter(fn (GiftPack $p) => $p->items->isNotEmpty() && $this->buildableCount($p) > 0)
                ->count(),
        ];
    }

    private function row(GiftPack $p, bool $detailed = false): array
    {
        $base = [
            'id' => $p->id,
            'code' => $p->code,
            'name' => $p->name,
            'product_id' => $p->product_id,
            'product' => $p->product?->name,
            'sku' => $p->product?->sku,
            'selling_price' => (float) ($p->product?->selling_price ?? 0),
            'occasion_tag' => $p->occasion_tag,
            'wrap_option_id' => $p->wrap_option_id,
            'wrap_option' => $p->wrapOption?->name,
            'item_count' => $p->items->count(),
            'estimated_cost' => $p->estimatedCost(),
            'contents_value' => $p->contentsValue(),
            /**
             * How many complete hampers the contents on hand would make — the
             * question the fortnight before a festival is spent on. Derived from
             * live stock, so it is right at the moment it is read and stale a
             * second later, which is the honest way round.
             */
            'buildable' => $this->buildableCount($p),
            'is_active' => (bool) $p->is_active,
        ];

        if (! $detailed) {
            return $base;
        }

        return $base + [
            'notes' => $p->notes,
            'items' => $p->items->map(fn ($item) => [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'product' => $item->product?->name,
                'sku' => $item->product?->sku,
                'variant_id' => $item->variant_id,
                'variant' => $item->variant?->sku,
                'quantity' => (float) $item->quantity,
                'unit_cost' => $item->unitCost(),
                'selling_price' => (float) ($item->product?->selling_price ?? 0),
                'on_hand' => $this->onHand((int) $item->product_id, $item->variant_id ? (int) $item->variant_id : null),
                'notes' => $item->notes,
            ])->values(),
            'builds' => $p->relationLoaded('builds')
                ? $p->builds->map(fn (GiftPackBuild $b) => $this->buildRow($b))->values()
                : [],
        ];
    }

    private function buildRow(GiftPackBuild $b): array
    {
        return [
            'id' => $b->id,
            'build_number' => $b->build_number,
            'pack_id' => $b->pack_id,
            'quantity' => (float) $b->quantity,
            'warehouse_id' => $b->warehouse_id,
            'warehouse' => $b->warehouse?->name,
            'build_date' => $b->build_date?->toDateString(),
            'unit_cost' => (float) $b->unit_cost,
            'total_cost' => $b->totalCost(),
            'is_posted' => $b->isPosted(),
            'posted_at' => $b->posted_at?->toIso8601String(),
            'notes' => $b->notes,
            'created_by' => $b->relationLoaded('creator') ? $b->creator?->name : null,
        ];
    }

    /**
     * The limiting component. Whole hampers only — three and a half baskets of
     * chocolate is three hampers, and a shop planning a build wants the number it
     * can actually put on a shelf.
     */
    private function buildableCount(GiftPack $pack): int
    {
        if ($pack->items->isEmpty()) {
            return 0;
        }

        $possible = null;

        foreach ($pack->items as $item) {
            $per = (float) $item->quantity;

            if ($per <= 0) {
                continue;
            }

            $canMake = (int) floor(
                $this->onHand((int) $item->product_id, $item->variant_id ? (int) $item->variant_id : null) / $per,
            );

            $possible = $possible === null ? $canMake : min($possible, $canMake);
        }

        return max(0, (int) ($possible ?? 0));
    }

    /** Free stock across every warehouse, reservations excluded. */
    private function onHand(int $productId, ?int $variantId): float
    {
        $query = WarehouseStock::query()->where('product_id', $productId);

        $variantId === null
            ? $query->whereNull('variant_id')
            : $query->where('variant_id', $variantId);

        return round(
            (float) $query->sum('quantity') - (float) $query->sum('reserved_quantity'),
            3,
        );
    }
}
