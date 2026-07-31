<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\RestaurantOrder;
use App\Models\RestaurantOrderItem;
use App\Services\RestaurantService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Open tickets: what a table has ordered, and what has gone to the kitchen.
 *
 * Adding items, firing them and settling are separate endpoints because they
 * are separate acts on the floor — a waiter keys in a round, fires it, comes
 * back twice more, and only then does the table ask for the bill.
 */
class RestaurantOrderController extends Controller
{
    public function __construct(private readonly RestaurantService $restaurant) {}

    public function index(Request $request)
    {
        $query = RestaurantOrder::query()->with([
            'table:id,name', 'customer:id,name', 'waiter:id,first_name,last_name',
            'invoice:id,invoice_number',
        ])->withCount('items');

        if ($search = $request->query('search')) {
            $query->where('order_number', 'like', "%{$search}%");
        }
        foreach (['status', 'order_type', 'restaurant_table_id', 'waiter_id'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->query($filter));
            }
        }
        if ($request->boolean('open_only')) {
            $query->where('status', 'open');
        }

        $query->orderByDesc('id');

        return response()->json(
            $query->paginate(min((int) $request->query('per_page', 25), 100))
                ->through(fn (RestaurantOrder $o) => $this->row($o))->toArray()
            + ['summary' => $this->summary()],
        );
    }

    /** Seat a party, or start a takeaway/delivery ticket. */
    public function store(Request $request)
    {
        $data = $request->validate([
            'order_type' => ['required', Rule::in(['dine_in', 'takeaway', 'delivery'])],
            'restaurant_table_id' => ['nullable', 'exists:restaurant_tables,id'],
            'customer_id' => ['nullable', 'exists:customers,id'],
            'waiter_id' => ['nullable', 'exists:employees,id'],
            'guest_count' => ['nullable', 'integer', 'min:1', 'max:100'],
            'service_charge_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'notes' => ['nullable', 'string'],
        ]);

        if ($data['order_type'] === 'dine_in' && empty($data['restaurant_table_id'])) {
            throw ValidationException::withMessages([
                'restaurant_table_id' => ['A dine-in order needs a table.'],
            ]);
        }
        // Takeaway occupies no table; accepting one would strand it as occupied.
        if ($data['order_type'] !== 'dine_in') {
            $data['restaurant_table_id'] = null;
        }

        try {
            $this->restaurant->assertTableFree($data['restaurant_table_id'] ?? null);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['restaurant_table_id' => [$e->getMessage()]]);
        }

        $order = DB::transaction(function () use ($request, $data) {
            $order = RestaurantOrder::create($data + [
                'order_number' => RestaurantOrder::nextNumber(),
                'status' => 'open',
                'guest_count' => $data['guest_count'] ?? 1,
                'opened_at' => now(),
                'created_by' => $request->user()?->id,
            ]);

            $this->restaurant->setTableStatus($order->restaurant_table_id, 'occupied');

            return $order;
        });

        return response()->json(['data' => $this->row($order->load($this->relations()))], 201);
    }

    public function show(RestaurantOrder $restaurantOrder)
    {
        return response()->json([
            'data' => $this->row($restaurantOrder->load([...$this->relations(), 'items.product:id,sku,name,kitchen_station', 'tickets'])),
        ]);
    }

    /** Edit the ticket header — guests, waiter, discount, service charge. */
    public function update(Request $request, RestaurantOrder $restaurantOrder)
    {
        if (! $restaurantOrder->isOpen()) {
            return response()->json(['message' => 'This ticket is already closed.'], 422);
        }

        $data = $request->validate([
            'customer_id' => ['nullable', 'exists:customers,id'],
            'waiter_id' => ['nullable', 'exists:employees,id'],
            'guest_count' => ['nullable', 'integer', 'min:1', 'max:100'],
            'discount_type' => ['nullable', Rule::in(['percent', 'flat'])],
            'discount_value' => ['nullable', 'numeric', 'min:0'],
            'service_charge_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'notes' => ['nullable', 'string'],
        ]);

        DB::transaction(function () use ($restaurantOrder, $data) {
            $restaurantOrder->update($data);
            $this->restaurant->reprice($restaurantOrder);
        });

        return response()->json(['data' => $this->row($restaurantOrder->load($this->relations()))]);
    }

    /** Add a round of dishes. They sit pending until fired. */
    public function addItems(Request $request, RestaurantOrder $restaurantOrder)
    {
        if (! $restaurantOrder->isOpen()) {
            return response()->json(['message' => 'This ticket is already closed.'], 422);
        }

        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'exists:products,id'],
            'items.*.variant_id' => ['nullable', 'exists:product_variants,id'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.001'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'items.*.tax_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'items.*.instructions' => ['nullable', 'string', 'max:150'],
            'items.*.course' => ['nullable', 'integer', 'min:1', 'max:9'],
        ]);

        DB::transaction(function () use ($request, $restaurantOrder, $data) {
            foreach ($data['items'] as $line) {
                // Price off the menu unless the till overrides it, so a waiter
                // keying a dish never has to know what it costs.
                $product = Product::find($line['product_id']);

                $restaurantOrder->items()->create([
                    'product_id' => $line['product_id'],
                    'variant_id' => $line['variant_id'] ?? null,
                    'quantity' => $line['quantity'],
                    'unit_price' => $line['unit_price'] ?? (float) ($product?->selling_price ?? 0),
                    'tax_percent' => $line['tax_percent'] ?? (float) ($product?->tax_percent ?? 0),
                    'instructions' => $line['instructions'] ?? null,
                    'course' => $line['course'] ?? 1,
                    'status' => 'pending',
                    'created_by' => $request->user()?->id,
                ]);
            }

            $this->restaurant->reprice($restaurantOrder);
        });

        return response()->json([
            'data' => $this->row($restaurantOrder->load([...$this->relations(), 'items.product:id,sku,name,kitchen_station'])),
        ]);
    }

    /**
     * Remove a line. Only possible while it is still pending — once the kitchen
     * has it, the way off the bill is a void with a reason.
     */
    public function removeItem(RestaurantOrder $restaurantOrder, RestaurantOrderItem $item)
    {
        if ($item->restaurant_order_id !== $restaurantOrder->id) {
            return response()->json(['message' => 'That line is not on this ticket.'], 422);
        }
        if (! $item->isEditable()) {
            return response()->json([
                'message' => 'This dish has already gone to the kitchen. Void it with a reason instead.',
            ], 422);
        }

        DB::transaction(function () use ($restaurantOrder, $item) {
            $item->delete();
            $this->restaurant->reprice($restaurantOrder);
        });

        return response()->json(['data' => $this->row($restaurantOrder->load($this->relations()))]);
    }

    /** Void a fired line — the dish was cooked, so it stays on the ticket. */
    public function voidItem(Request $request, RestaurantOrder $restaurantOrder, RestaurantOrderItem $item)
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:150'],
        ]);

        if ($item->restaurant_order_id !== $restaurantOrder->id) {
            return response()->json(['message' => 'That line is not on this ticket.'], 422);
        }

        DB::transaction(fn () => $this->restaurant->voidItem($item, $data['reason'], $request->user()?->id));

        return response()->json([
            'data' => $this->row($restaurantOrder->fresh()->load([...$this->relations(), 'items.product:id,sku,name'])),
        ]);
    }

    /** Send everything pending to the kitchen, split by station. */
    public function fire(Request $request, RestaurantOrder $restaurantOrder)
    {
        if (! $restaurantOrder->isOpen()) {
            return response()->json(['message' => 'This ticket is already closed.'], 422);
        }

        $tickets = DB::transaction(fn () => $this->restaurant->fire($restaurantOrder, $request->user()?->id));

        return response()->json([
            'data' => $this->row($restaurantOrder->load([...$this->relations(), 'items.product:id,sku,name', 'tickets'])),
            'tickets' => $tickets->map(fn ($t) => [
                'id' => $t->id, 'kot_number' => $t->kot_number, 'station' => $t->station,
            ]),
        ]);
    }

    /** Bill the table and free it. */
    public function settle(Request $request, RestaurantOrder $restaurantOrder)
    {
        try {
            $invoice = DB::transaction(
                fn () => $this->restaurant->settle($restaurantOrder, $request->user()?->id),
            );
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['order' => [$e->getMessage()]]);
        }

        return response()->json([
            'data' => $this->row($restaurantOrder->fresh()->load($this->relations())),
            'invoice' => $invoice ? [
                'id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'total' => (float) $invoice->total,
            ] : null,
        ]);
    }

    /** Abandon a ticket — a party that walked out before ordering anything. */
    public function cancel(Request $request, RestaurantOrder $restaurantOrder)
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        if (! $restaurantOrder->isOpen()) {
            return response()->json(['message' => 'This ticket is already closed.'], 422);
        }
        if ($restaurantOrder->invoice_id) {
            return response()->json([
                'message' => 'This ticket is billed. Raise a credit note against the invoice instead.',
            ], 422);
        }
        // Food that was cooked has to be accounted for, not cancelled away.
        if ($restaurantOrder->billableItems()->where('status', '!=', 'pending')->exists()) {
            return response()->json([
                'message' => 'Dishes have already gone to the kitchen. Void them individually, then settle the ticket.',
            ], 422);
        }

        DB::transaction(function () use ($restaurantOrder, $data) {
            $restaurantOrder->forceFill([
                'status' => 'cancelled',
                'notes' => trim((string) $restaurantOrder->notes."\nCancelled: ".($data['reason'] ?? '—')),
            ])->save();

            $this->restaurant->setTableStatus($restaurantOrder->restaurant_table_id, 'free');
        });

        return response()->json(['data' => $this->row($restaurantOrder->load($this->relations()))]);
    }

    private function summary(): array
    {
        $open = RestaurantOrder::where('status', 'open');

        return [
            'open' => (clone $open)->count(),
            'open_value' => round((float) (clone $open)->sum('total'), 2),
            'settled_today' => RestaurantOrder::where('status', 'settled')
                ->whereDate('settled_at', now()->toDateString())->count(),
            'sales_today' => round((float) RestaurantOrder::where('status', 'settled')
                ->whereDate('settled_at', now()->toDateString())->sum('total'), 2),
        ];
    }

    private function row(RestaurantOrder $o): array
    {
        return [
            'id' => $o->id,
            'order_number' => $o->order_number,
            'order_type' => $o->order_type,
            'status' => $o->status,
            'table' => $o->relationLoaded('table') ? $o->table?->name : null,
            'restaurant_table_id' => $o->restaurant_table_id,
            'customer' => $o->relationLoaded('customer') ? $o->customer?->name : null,
            'waiter' => $o->relationLoaded('waiter') ? $o->waiter?->fullName() : null,
            'guest_count' => $o->guest_count,

            'subtotal' => (float) $o->subtotal,
            'discount_type' => $o->discount_type,
            'discount_value' => (float) $o->discount_value,
            'discount_amount' => (float) $o->discount_amount,
            'service_charge_percent' => (float) $o->service_charge_percent,
            'service_charge' => (float) $o->service_charge,
            'tax_amount' => (float) $o->tax_amount,
            'round_off' => (float) $o->round_off,
            'total' => (float) $o->total,

            'opened_at' => $o->opened_at?->toIso8601String(),
            'settled_at' => $o->settled_at?->toIso8601String(),
            'seated_minutes' => $o->opened_at && $o->isOpen()
                ? (int) $o->opened_at->diffInMinutes(now())
                : null,
            'invoice' => $o->relationLoaded('invoice') ? $o->invoice?->invoice_number : null,
            'items_count' => $o->items_count ?? null,
            'notes' => $o->notes,

            'items' => $o->relationLoaded('items') ? $o->items->map(fn (RestaurantOrderItem $i) => [
                'id' => $i->id,
                'product_id' => $i->product_id,
                'name' => $i->relationLoaded('product') ? $i->product?->name : null,
                'station' => $i->relationLoaded('product') ? $i->product?->kitchen_station : null,
                'quantity' => (float) $i->quantity,
                'unit_price' => (float) $i->unit_price,
                'line_total' => (float) $i->line_total,
                'status' => $i->status,
                'void_reason' => $i->void_reason,
                'instructions' => $i->instructions,
                'course' => $i->course,
                'is_editable' => $i->isEditable(),
            ]) : null,
            'tickets' => $o->relationLoaded('tickets') ? $o->tickets->map(fn ($t) => [
                'id' => $t->id, 'kot_number' => $t->kot_number,
                'station' => $t->station, 'status' => $t->status,
                'fired_at' => $t->fired_at?->toIso8601String(),
            ]) : null,
        ];
    }

    /** @return list<string> */
    private function relations(): array
    {
        return [
            'table:id,name', 'customer:id,name', 'waiter:id,first_name,last_name',
            'invoice:id,invoice_number',
        ];
    }
}
