<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RestaurantSection;
use App\Models\RestaurantTable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The floor: sections, tables, and what is sitting at each one.
 *
 * {@see floor()} is the screen a restaurant actually lives on — every table
 * with its open ticket, in one query — so it is deliberately separate from the
 * table master's CRUD.
 */
class RestaurantTableController extends Controller
{
    /**
     * The whole floor, grouped by section, each occupied table carrying its
     * open ticket. One query per relation rather than per table: a busy
     * restaurant refreshes this every few seconds.
     */
    public function floor()
    {
        $tables = RestaurantTable::query()
            ->with([
                'section:id,name,sort_order',
                'openOrder:id,restaurant_table_id,order_number,guest_count,total,opened_at,waiter_id',
                'openOrder.waiter:id,first_name,last_name',
            ])
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $sections = $tables
            ->groupBy(fn (RestaurantTable $t) => $t->section?->name ?? 'Unassigned')
            ->map(fn ($group, $name) => [
                'section' => $name,
                'sort_order' => $group->first()->section?->sort_order ?? 999,
                'tables' => $group->map(fn (RestaurantTable $t) => [
                    'id' => $t->id,
                    'name' => $t->name,
                    'capacity' => $t->capacity,
                    'status' => $t->status,
                    'is_seatable' => $t->isSeatable(),
                    'order' => $t->openOrder ? [
                        'id' => $t->openOrder->id,
                        'order_number' => $t->openOrder->order_number,
                        'guest_count' => $t->openOrder->guest_count,
                        'total' => (float) $t->openOrder->total,
                        'opened_at' => $t->openOrder->opened_at?->toIso8601String(),
                        // What the floor manager scans for: how long they have been sitting.
                        'seated_minutes' => $t->openOrder->opened_at
                            ? (int) $t->openOrder->opened_at->diffInMinutes(now())
                            : null,
                        'waiter' => $t->openOrder->waiter?->fullName(),
                    ] : null,
                ])->values(),
            ])
            ->sortBy('sort_order')
            ->values();

        return response()->json([
            'data' => $sections,
            'summary' => [
                'total' => $tables->count(),
                'free' => $tables->where('status', 'free')->count(),
                'occupied' => $tables->where('status', 'occupied')->count(),
                'cleaning' => $tables->where('status', 'cleaning')->count(),
                'reserved' => $tables->where('status', 'reserved')->count(),
            ],
        ]);
    }

    public function index(Request $request)
    {
        $query = RestaurantTable::query()->with('section:id,name');

        if ($request->filled('restaurant_section_id')) {
            $query->where('restaurant_section_id', $request->query('restaurant_section_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        $query->orderBy('sort_order')->orderBy('name');

        return response()->json([
            'data' => $query->paginate(min((int) $request->query('per_page', 100), 200))
                ->through(fn (RestaurantTable $t) => $this->row($t)),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $table = RestaurantTable::create($data + ['status' => 'free']);

        return response()->json(['data' => $this->row($table->load('section:id,name'))], 201);
    }

    public function update(Request $request, RestaurantTable $restaurantTable)
    {
        $restaurantTable->update($this->validated($request, $restaurantTable->id));

        return response()->json(['data' => $this->row($restaurantTable->load('section:id,name'))]);
    }

    public function destroy(RestaurantTable $restaurantTable)
    {
        if ($restaurantTable->orders()->exists()) {
            return response()->json([
                'message' => 'Orders have been taken at this table. Mark it inactive instead of deleting it.',
            ], 422);
        }

        $restaurantTable->delete();

        return response()->json(['message' => 'Table deleted.']);
    }

    /**
     * Change a table's state by hand — reserving it, or marking it wiped and
     * ready. An occupied table is not settable this way: it is freed by
     * settling the ticket, so the floor and the bill cannot disagree.
     */
    public function setStatus(Request $request, RestaurantTable $restaurantTable)
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['free', 'reserved', 'cleaning'])],
        ]);

        if ($restaurantTable->status === 'occupied') {
            return response()->json([
                'message' => 'This table has an open order. Settle or cancel it to free the table.',
            ], 422);
        }

        $restaurantTable->update(['status' => $data['status']]);

        return response()->json(['data' => $this->row($restaurantTable->load('section:id,name'))]);
    }

    /** Sections master — small enough to live alongside the tables. */
    public function sections()
    {
        return response()->json([
            'data' => RestaurantSection::query()
                ->withCount('tables')
                ->orderBy('sort_order')
                ->get(['id', 'name', 'sort_order', 'is_active']),
        ]);
    }

    public function storeSection(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        return response()->json(['data' => RestaurantSection::create($data)], 201);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'restaurant_section_id' => ['nullable', 'exists:restaurant_sections,id'],
            'name' => [
                'required', 'string', 'max:30',
                Rule::unique('restaurant_tables', 'name')->ignore($ignoreId)
                    ->where(fn ($q) => $q->where('tenant_id', app(\App\Support\TenantContext::class)->id())),
            ],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:50'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);
    }

    private function row(RestaurantTable $t): array
    {
        return [
            'id' => $t->id,
            'restaurant_section_id' => $t->restaurant_section_id,
            'section' => $t->relationLoaded('section') ? $t->section?->name : null,
            'name' => $t->name,
            'capacity' => $t->capacity,
            'status' => $t->status,
            'is_active' => $t->is_active,
            'sort_order' => $t->sort_order,
        ];
    }
}
