<?php

declare(strict_types=1);

namespace App\Modules\Industry\Stationery\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Modules\Industry\Stationery\Http\Requests\ClassKitRequest;
use App\Modules\Industry\Stationery\Http\Requests\KitSupplyRequest;
use App\Modules\Industry\Stationery\Models\ClassKit;
use App\Modules\Industry\Stationery\Models\ClassKitItem;
use App\Modules\Industry\Stationery\Services\ClassKitSupplyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The booklists — the register this whole vertical is built around.
 *
 * Two things on this controller are worth knowing before changing it.
 *
 * **Only a published list can be supplied from.** A half-typed booklist looks
 * exactly like a finished one, and a counter that can sell a draft will sell the
 * eleven items somebody keyed in before lunch and send a parent home short. So
 * `publish` is an explicit act, it refuses an empty list, and `estimate`/`quote`/
 * `order` all go through the service's own check rather than trusting the caller.
 *
 * **Lines are replaced wholesale on update, and that is safe here.** The repo's
 * usual caution about `items()->delete()` applies to documents whose lines become
 * immutable once acted on — a kit's never do. It is a master list, not a
 * transaction: nothing has been issued against these rows, and the orders raised
 * from them carry their own snapshotted copies.
 */
class ClassKitController extends Controller
{
    public function __construct(
        private readonly ClassKitSupplyService $supply,
    ) {}

    public function index(Request $request)
    {
        $query = ClassKit::query()
            ->with('institution:id,code,name,board')
            ->withCount('items');

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('kit_number', 'like', "%{$search}%")
                    ->orWhere('class_label', 'like', "%{$search}%")
                    ->orWhere('title', 'like', "%{$search}%")
                    ->orWhereHas('institution', fn ($i) => $i->where('name', 'like', "%{$search}%"));
            });
        }

        foreach (['status', 'academic_year', 'institution_id', 'class_label'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->query($filter));
            }
        }

        // The shop's own generic lists, for the walk-in whose school publishes
        // nothing.
        if ($request->boolean('generic_only')) {
            $query->whereNull('institution_id');
        }

        $query->orderBy('academic_year', 'desc')->orderBy('class_label')->orderBy('id');

        return response()->json(
            $query->paginate(min((int) $request->query('per_page', 25), 100))
                ->through(fn (ClassKit $k) => $this->row($k))->toArray()
            + ['summary' => $this->summary()],
        );
    }

    public function show(ClassKit $kit)
    {
        $kit->load([
            'institution:id,code,name,board,parent_discount_percent,school_discount_percent,requires_po,customer_id',
            'items.product:id,sku,name,selling_price,tax_percent',
            'items.variant:id,sku,name',
        ]);

        return response()->json(['data' => $this->row($kit, detailed: true)]);
    }

    public function store(ClassKitRequest $request)
    {
        $data = $request->validated();
        $items = $data['items'] ?? [];
        unset($data['items']);

        $kit = DB::transaction(function () use ($data, $items, $request) {
            $kit = ClassKit::create($data + [
                'kit_number' => ClassKit::nextNumber(),
                'status' => 'draft',
                'created_by' => $request->user()?->id,
            ]);

            $this->syncItems($kit, $items);

            return $kit;
        });

        return response()->json(
            ['data' => $this->row($kit->fresh(['institution', 'items.product', 'items.variant']), detailed: true)],
            201,
        );
    }

    public function update(ClassKitRequest $request, ClassKit $kit)
    {
        if ($kit->status === 'archived') {
            return response()->json(['message' => 'This list is archived. Copy it forward instead of editing it.'], 422);
        }

        $data = $request->validated();
        $items = $data['items'] ?? null;
        unset($data['items']);

        DB::transaction(function () use ($kit, $data, $items) {
            $kit->update($data);

            // Null means "header only" — the editor sends lines when it has them
            // and an inline status/discount tweak should not blank the list.
            if ($items !== null) {
                $kit->items()->delete();
                $this->syncItems($kit, $items);
            }
        });

        return response()->json(
            ['data' => $this->row($kit->fresh(['institution', 'items.product', 'items.variant']), detailed: true)],
        );
    }

    /** Release the list to the counter. Refuses an empty one. */
    public function publish(ClassKit $kit)
    {
        if (! $kit->publish()) {
            return response()->json([
                'message' => $kit->status === 'archived'
                    ? 'An archived list cannot be published. Copy it forward first.'
                    : 'Add at least one item before publishing — a published list is a promise that a parent can be served.',
            ], 422);
        }

        return response()->json(['data' => $this->row($kit->fresh('institution'))]);
    }

    /**
     * Take a list out of service.
     *
     * Archiving rather than deleting, always: last year's list is what next
     * year's is built from, and it is also the only record of what was supplied
     * to the parents who bought it.
     */
    public function archive(ClassKit $kit)
    {
        $kit->forceFill(['status' => 'archived'])->save();

        return response()->json(['data' => $this->row($kit->fresh('institution'))]);
    }

    /** Copy the list into another session — the April time-saver. */
    public function rollForward(Request $request, ClassKit $kit)
    {
        $validated = $request->validate([
            'academic_year' => ['required', 'string', 'regex:/^\d{4}-\d{2}$/'],
        ]);

        try {
            $copy = $this->supply->rollForward($kit, $validated['academic_year'], $request->user()?->id);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(
            ['data' => $this->row($copy->fresh(['institution', 'items.product']), detailed: true)],
            201,
        );
    }

    /**
     * What this list costs one buyer today, and what is missing off the shelf.
     *
     * The counter's whole job in June, and read-only on purpose: the parent is
     * still deciding, three lines are short, and nothing should be committed
     * until they say yes.
     */
    public function estimate(KitSupplyRequest $request, ClassKit $kit)
    {
        $data = $request->validated();

        try {
            $estimate = $this->supply->estimate(
                kit: $kit,
                customer: $this->customerFrom($data),
                sets: (float) ($data['sets'] ?? 1),
                warehouseId: (int) $data['warehouse_id'],
                includeOptional: (bool) ($data['include_optional'] ?? false),
                onlyItemIds: $data['item_ids'] ?? null,
                buyerIsTheInstitution: false,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'kit' => ['id' => $kit->id, 'kit_number' => $kit->kit_number, 'label' => $kit->label()],
            'lines' => array_map(fn (array $l) => $this->estimateLine($l), $estimate['lines']),
            'header' => $estimate['header'],
            'shortfalls' => $estimate['shortfalls'],
            'discount_percent' => $estimate['discount_percent'],
            'is_sellable' => $kit->isSellable(),
        ]);
    }

    /** Answer a school's tender — a core quotation, stamped with the school. */
    public function quote(KitSupplyRequest $request, ClassKit $kit)
    {
        try {
            $quotation = $this->supply->quote($kit, $request->validated(), $request->user()?->id);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => "Quotation {$quotation->quote_number} raised.",
            'data' => [
                'id' => $quotation->id,
                'quote_number' => $quotation->quote_number,
                'total' => (float) $quotation->total,
            ],
        ], 201);
    }

    /** Raise the school's supply order — a core sales order, on the institution channel. */
    public function order(KitSupplyRequest $request, ClassKit $kit)
    {
        try {
            $order = $this->supply->order($kit, $request->validated(), $request->user()?->id);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => "Sales order {$order->so_number} raised.",
            'data' => [
                'id' => $order->id,
                'so_number' => $order->so_number,
                'total' => (float) $order->total,
            ],
        ], 201);
    }

    public function destroy(ClassKit $kit)
    {
        /**
         * A list that has been supplied against is the record of what those
         * parents were sold. Archiving keeps it readable next April, which is
         * the only reason anyone opens an old booklist at all.
         */
        if ($kit->salesOrders()->exists() || $kit->quotations()->exists()) {
            return response()->json([
                'message' => 'This list has orders or quotations against it. Archive it instead of deleting it.',
            ], 422);
        }

        $kit->delete();

        return response()->json(['message' => 'Booklist deleted.']);
    }

    /** @param  array<int, array<string, mixed>>  $items */
    private function syncItems(ClassKit $kit, array $items): void
    {
        foreach (array_values($items) as $index => $item) {
            $kit->items()->create([
                'product_id' => $item['product_id'],
                'variant_id' => $item['variant_id'] ?? null,
                'quantity' => $item['quantity'],
                'is_optional' => (bool) ($item['is_optional'] ?? false),
                'remark' => $item['remark'] ?? null,
                // Falls back to the order they arrived in, which is the order
                // they were read off the school's sheet.
                'sort_order' => $item['sort_order'] ?? $index,
            ]);
        }
    }

    /** @param  array<string, mixed>  $data */
    private function customerFrom(array $data): ?Customer
    {
        return isset($data['customer_id'])
            ? Customer::find($data['customer_id'])
            : null;
    }

    /** @param  array<string, mixed>  $line */
    private function estimateLine(array $line): array
    {
        $source = $line['source'] ?? [];

        return [
            'kit_item_id' => $source['_kit_item_id'] ?? null,
            'product_id' => $line['product_id'],
            'variant_id' => $line['variant_id'],
            'name' => $source['_name'] ?? null,
            'catalogue_name' => $source['_catalogue_name'] ?? null,
            'sku' => $source['_sku'] ?? null,
            'is_optional' => (bool) ($source['_is_optional'] ?? false),
            'available' => $source['_available'] ?? null,
            'quantity' => $line['quantity'],
            'unit_price' => $line['unit_price'],
            'discount_amount' => $line['discount_amount'],
            'tax_percent' => $line['tax_percent'],
            'tax_amount' => $line['tax_amount'],
            'line_total' => $line['line_total'],
        ];
    }

    private function summary(): array
    {
        return [
            'lists' => ClassKit::count(),
            'published' => ClassKit::where('status', 'published')->count(),
            'draft' => ClassKit::where('status', 'draft')->count(),
            // Sets the shop expects to sell this season — the number every April
            // purchase order is worked back from.
            'expected_sets' => (int) ClassKit::where('status', 'published')->sum('expected_students'),
        ];
    }

    private function row(ClassKit $k, bool $detailed = false): array
    {
        $base = [
            'id' => $k->id,
            'kit_number' => $k->kit_number,
            'label' => $k->label(),
            'institution_id' => $k->institution_id,
            'institution' => $k->institution?->name,
            'board' => $k->institution?->board,
            'academic_year' => $k->academic_year,
            'class_label' => $k->class_label,
            'stream' => $k->stream,
            'title' => $k->title,
            'status' => $k->status,
            'is_sellable' => $k->isSellable(),
            'is_mandatory_set' => (bool) $k->is_mandatory_set,
            'discount_percent' => (float) $k->discount_percent,
            'expected_students' => $k->expected_students,
            'items_count' => $k->items_count ?? $k->items()->count(),
            'published_at' => $k->published_at?->toIso8601String(),
        ];

        if (! $detailed) {
            return $base;
        }

        return $base + [
            'notes' => $k->notes,
            /**
             * The school's concession is echoed onto the list because it is
             * charged together with the list's own set discount — showing one
             * without the other makes the estimate look wrong to whoever typed
             * the kit.
             */
            'institution_discounts' => $k->institution ? [
                'parent_percent' => (float) $k->institution->parent_discount_percent,
                'school_percent' => (float) $k->institution->school_discount_percent,
                'requires_po' => (bool) $k->institution->requires_po,
                'customer_id' => $k->institution->customer_id,
            ] : null,
            'items' => $k->relationLoaded('items')
                ? $k->items->map(fn (ClassKitItem $i) => [
                    'id' => $i->id,
                    'product_id' => $i->product_id,
                    'variant_id' => $i->variant_id,
                    'product' => $i->product?->name,
                    'sku' => $i->product?->sku,
                    'variant' => $i->variant?->name,
                    'quantity' => (float) $i->quantity,
                    'is_optional' => (bool) $i->is_optional,
                    'remark' => $i->remark,
                    'printed_name' => $i->printedName(),
                    'selling_price' => (float) ($i->product?->selling_price ?? 0),
                    'sort_order' => (int) $i->sort_order,
                ])->values()
                : [],
        ];
    }
}
