<?php

declare(strict_types=1);

namespace App\Modules\Industry\FoodBeverage\Services;

use App\Models\RestaurantOrder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The counter queue: take the money, hand over a number, call it when it is ready.
 *
 * A QSR has no tables, so the floor plan is no use to it — but the ticket and the
 * kitchen dockets are exactly right, which is why a token is columns on a
 * restaurant order rather than a document of its own.
 *
 * Numbers restart every day. That is the whole reason the counter can shout them:
 * "fourteen" has to be a small number, and a number small enough to shout is a
 * number that must be allowed to come round again tomorrow.
 */
class TokenQueueService
{
    /** States a token moves through, in order. */
    public const FLOW = ['waiting', 'preparing', 'ready', 'called', 'collected'];

    /**
     * Issue the next token for today and put the order in the queue.
     *
     * Idempotent: an order that already holds a token keeps it. Re-issuing would
     * hand the same customer a second number, and the first one would then be
     * called to nobody.
     */
    public function issue(RestaurantOrder $order): RestaurantOrder
    {
        if ($order->token_number !== null) {
            return $order;
        }

        /**
         * Allocated inside a transaction with a locking read on today's rows, so
         * two tills serving two customers at the same second cannot both be told
         * the next number is fifteen. The unique index on
         * (tenant, token_date, token_number) is the real guarantee; this is what
         * stops it from being hit in the first place.
         */
        return DB::transaction(function () use ($order) {
            $today = now()->toDateString();

            $last = RestaurantOrder::query()
                ->whereDate('token_date', $today)
                ->lockForUpdate()
                ->max('token_number');

            $order->forceFill([
                'token_date' => $today,
                'token_number' => (int) $last + 1,
                'token_status' => 'waiting',
            ])->save();

            return $order;
        });
    }

    /**
     * Move a token along the flow.
     *
     * Forwards only. A tray that has been collected cannot go back to preparing,
     * and a board that could step backwards is a board nobody trusts — if the
     * counter got it wrong, the order is re-keyed, not rewound.
     */
    public function advance(RestaurantOrder $order, string $to): RestaurantOrder
    {
        if (! in_array($to, self::FLOW, true)) {
            throw new RuntimeException("Unknown token state '{$to}'.");
        }

        if ($order->token_number === null) {
            throw new RuntimeException("Order {$order->order_number} has no token to advance.");
        }

        $from = (string) ($order->token_status ?: 'waiting');

        if (array_search($to, self::FLOW, true) < array_search($from, self::FLOW, true)) {
            throw new RuntimeException("A token cannot go back from '{$from}' to '{$to}'.");
        }

        $order->forceFill(array_filter([
            'token_status' => $to,
            // Stamped once, on first entry into the state: the counter's service
            // time is issued → called, and re-calling a number nobody came for
            // must not reset the clock it is measured against.
            'token_called_at' => $to === 'called' && ! $order->token_called_at ? now() : null,
            'token_collected_at' => $to === 'collected' && ! $order->token_collected_at ? now() : null,
        ]))->save();

        return $order;
    }

    /**
     * Today's board, in the order the counter works it.
     *
     * Collected tokens are dropped: the board is what is still owed to somebody
     * standing in the shop, and a finished number on it is noise. History stays on
     * the orders themselves.
     *
     * @return Collection<int, RestaurantOrder>
     */
    public function board(?string $onDate = null): Collection
    {
        return RestaurantOrder::query()
            ->whereDate('token_date', $onDate ?: now()->toDateString())
            ->whereNotNull('token_number')
            ->where('token_status', '!=', 'collected')
            ->with(['items:id,restaurant_order_id,product_id,quantity,status', 'items.product:id,name'])
            ->orderBy('token_number')
            ->get();
    }

    /**
     * Numbers ready to be handed over — what goes on the customer-facing display.
     *
     * @return list<int>
     */
    public function ready(?string $onDate = null): array
    {
        return RestaurantOrder::query()
            ->whereDate('token_date', $onDate ?: now()->toDateString())
            ->whereIn('token_status', ['ready', 'called'])
            ->orderBy('token_number')
            ->pluck('token_number')
            ->map(fn ($n) => (int) $n)
            ->all();
    }

    /**
     * Counter throughput for a day: how many tokens, and how long each took from
     * issue to call.
     *
     * Averaged over the tokens that were actually called — including the ones
     * still waiting would report a queue as fast service.
     *
     * @return array{date: string, issued: int, called: int, pending: int, avg_minutes: float}
     */
    public function dayStats(?string $onDate = null): array
    {
        $date = $onDate ?: now()->toDateString();

        $orders = RestaurantOrder::query()
            ->whereDate('token_date', $date)
            ->whereNotNull('token_number')
            ->get(['id', 'token_status', 'created_at', 'token_called_at']);

        $called = $orders->whereNotNull('token_called_at');

        $minutes = $called->map(
            fn (RestaurantOrder $o) => $o->created_at->diffInSeconds($o->token_called_at) / 60,
        );

        return [
            'date' => $date,
            'issued' => $orders->count(),
            'called' => $called->count(),
            'pending' => $orders->whereNotIn('token_status', ['called', 'collected'])->count(),
            'avg_minutes' => $minutes->isEmpty() ? 0.0 : round((float) $minutes->avg(), 1),
        ];
    }
}
