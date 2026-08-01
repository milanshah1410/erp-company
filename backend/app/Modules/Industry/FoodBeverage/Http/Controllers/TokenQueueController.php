<?php

declare(strict_types=1);

namespace App\Modules\Industry\FoodBeverage\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\RestaurantOrder;
use App\Models\RestaurantOrderItem;
use App\Modules\Industry\FoodBeverage\Services\TokenQueueService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * The QSR counter queue.
 *
 * Three screens read this controller and they want different things: the counter
 * wants the whole board with the items on each order, the kitchen wants to move
 * numbers along, and the customer-facing display wants nothing but a list of ready
 * numbers big enough to read across a room. Hence `board`, `advance` and `display`.
 */
class TokenQueueController extends Controller
{
    public function __construct(private readonly TokenQueueService $tokens) {}

    /** The counter's board: every live token for the day, with what is on it. */
    public function board(Request $request)
    {
        $date = $request->query('date');

        return response()->json([
            'data' => $this->tokens->board($date)->map(fn (RestaurantOrder $o) => [
                'id' => $o->id,
                'order_number' => $o->order_number,
                'token_number' => (int) $o->token_number,
                'token_status' => $o->token_status,
                'order_type' => $o->order_type,
                'total' => (float) $o->total,
                'waiting_minutes' => (int) $o->created_at->diffInMinutes(now()),
                'items' => $o->items
                    ->where('status', '!=', 'void')
                    ->map(fn (RestaurantOrderItem $i) => [
                        'name' => $i->product?->name,
                        'quantity' => (float) $i->quantity,
                    ])->values(),
            ])->values(),
            'stats' => $this->tokens->dayStats($date),
        ]);
    }

    /**
     * The customer-facing display: numbers that are ready, and nothing else.
     *
     * Kept separate from `board` so the display can be opened on an unattended
     * screen without exposing what anyone ordered or what they paid.
     */
    public function display(Request $request)
    {
        return response()->json([
            'data' => [
                'ready' => $this->tokens->ready($request->query('date')),
                'now_serving' => $this->tokens->ready($request->query('date'))[0] ?? null,
            ],
        ]);
    }

    /** Issue the next number for an order that does not have one. */
    public function issue(Request $request, RestaurantOrder $restaurantOrder)
    {
        $order = $this->tokens->issue($restaurantOrder);

        return response()->json([
            'data' => [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'token_number' => (int) $order->token_number,
                'token_status' => $order->token_status,
                'token_date' => $order->token_date?->toDateString(),
            ],
        ]);
    }

    /** Move a number along the flow — forwards only. */
    public function advance(Request $request, RestaurantOrder $restaurantOrder)
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(TokenQueueService::FLOW)],
        ]);

        try {
            $order = $this->tokens->advance($restaurantOrder, $data['status']);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => [
                'id' => $order->id,
                'token_number' => (int) $order->token_number,
                'token_status' => $order->token_status,
                'token_called_at' => $order->token_called_at?->toDateTimeString(),
                'token_collected_at' => $order->token_collected_at?->toDateTimeString(),
            ],
        ]);
    }

    /** Counter throughput for a day — issued, called, still waiting, average wait. */
    public function stats(Request $request)
    {
        return response()->json(['data' => $this->tokens->dayStats($request->query('date'))]);
    }
}
