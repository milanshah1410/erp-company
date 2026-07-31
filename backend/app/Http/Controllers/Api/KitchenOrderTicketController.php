<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\KitchenOrderTicket;
use App\Models\RestaurantOrderItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * The kitchen display.
 *
 * One screen per station, oldest ticket first, because the only question a
 * kitchen asks is "what has been waiting longest?". Marking a ticket served
 * carries its dishes with it, so the floor never has to tick off lines one by
 * one during a rush.
 */
class KitchenOrderTicketController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate([
            'station' => ['nullable', Rule::in(KitchenOrderTicket::STATIONS)],
            'status' => ['nullable', Rule::in(['pending', 'preparing', 'ready', 'served', 'cancelled'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $query = KitchenOrderTicket::query()
            ->with([
                'order:id,order_number,order_type,restaurant_table_id',
                'order.table:id,name',
                'items:id,kitchen_order_ticket_id,product_id,quantity,instructions,status',
                'items.product:id,name',
            ]);

        if (! empty($data['station'])) {
            $query->where('station', $data['station']);
        }
        if (! empty($data['status'])) {
            $query->where('status', $data['status']);
        }
        // The default board: everything the kitchen still owes the floor.
        if ($request->boolean('active_only', true) && empty($data['status'])) {
            $query->whereIn('status', ['pending', 'preparing', 'ready']);
        }

        // Oldest first — a kitchen works the queue, not the newest docket.
        $query->orderBy('fired_at')->orderBy('id');

        return response()->json([
            'data' => $query->paginate((int) ($data['per_page'] ?? 100))
                ->through(fn (KitchenOrderTicket $t) => $this->row($t)),
            'summary' => $this->summary(),
        ]);
    }

    /**
     * Move a ticket along. Marking it served marks its dishes served too — a
     * cook calling a docket away should not leave the floor to tick off six
     * lines by hand.
     */
    public function setStatus(Request $request, KitchenOrderTicket $kitchenOrderTicket)
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['pending', 'preparing', 'ready', 'served', 'cancelled'])],
        ]);

        $status = $data['status'];

        if (in_array($kitchenOrderTicket->status, ['served', 'cancelled'], true)) {
            return response()->json(['message' => 'This ticket is already closed.'], 422);
        }

        DB::transaction(function () use ($kitchenOrderTicket, $status) {
            $kitchenOrderTicket->forceFill(array_filter([
                'status' => $status,
                'ready_at' => $kitchenOrderTicket->ready_at ?? ($status === 'ready' ? now() : null),
                'served_at' => $status === 'served' ? now() : $kitchenOrderTicket->served_at,
            ], fn ($v) => $v !== null))->save();

            if ($status === 'served') {
                // Voided lines stay voided — they were never cooked to serve.
                RestaurantOrderItem::where('kitchen_order_ticket_id', $kitchenOrderTicket->id)
                    ->where('status', 'sent')
                    ->update(['status' => 'served']);
            }
        });

        return response()->json(['data' => $this->row($kitchenOrderTicket->fresh()->load([
            'order:id,order_number,order_type,restaurant_table_id', 'order.table:id,name',
            'items:id,kitchen_order_ticket_id,product_id,quantity,instructions,status', 'items.product:id,name',
        ]))]);
    }

    /** How much each station is holding, for the board's header. */
    private function summary(): array
    {
        $rows = KitchenOrderTicket::query()
            ->whereIn('status', ['pending', 'preparing', 'ready'])
            ->selectRaw('station, status, COUNT(*) as total')
            ->groupBy('station', 'status')
            ->get();

        return [
            'active' => (int) $rows->sum('total'),
            'by_station' => $rows->groupBy('station')->map(fn ($g) => (int) $g->sum('total')),
            'by_status' => $rows->groupBy('status')->map(fn ($g) => (int) $g->sum('total')),
        ];
    }

    private function row(KitchenOrderTicket $t): array
    {
        return [
            'id' => $t->id,
            'kot_number' => $t->kot_number,
            'station' => $t->station,
            'status' => $t->status,
            'order_number' => $t->relationLoaded('order') ? $t->order?->order_number : null,
            'order_type' => $t->relationLoaded('order') ? $t->order?->order_type : null,
            'table' => $t->relationLoaded('order') ? $t->order?->table?->name : null,
            'fired_at' => $t->fired_at?->toIso8601String(),
            'waiting_minutes' => $t->waitingMinutes(),
            'notes' => $t->notes,
            'items' => $t->relationLoaded('items') ? $t->items->map(fn ($i) => [
                'id' => $i->id,
                'name' => $i->relationLoaded('product') ? $i->product?->name : null,
                'quantity' => (float) $i->quantity,
                'instructions' => $i->instructions,
                'status' => $i->status,
            ]) : null,
        ];
    }
}
