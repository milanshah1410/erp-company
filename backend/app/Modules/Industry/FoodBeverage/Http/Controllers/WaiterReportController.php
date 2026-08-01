<?php

declare(strict_types=1);

namespace App\Modules\Industry\FoodBeverage\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\RestaurantOrder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Waiter-wise performance over a date range.
 *
 * The numbers a floor manager actually runs a shift on: how many tables each
 * waiter turned, how many covers they served, what they billed, and their average
 * bill — which is the one that reveals who upsells.
 *
 * Voids are reported per waiter alongside the sales, and deliberately so. A high
 * void count is either a training problem or a till being worked, and either way
 * it belongs next to the number it distorts rather than on a separate screen
 * nobody opens.
 */
class WaiterReportController extends Controller
{
    public function performance(Request $request)
    {
        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $from = $data['from'] ?? now()->startOfMonth()->toDateString();
        $to = $data['to'] ?? now()->toDateString();

        /**
         * Settled tickets only. An open ticket has no final value — counting it
         * would make every waiter currently holding tables look better than the
         * one who just closed theirs.
         */
        $rows = RestaurantOrder::query()
            ->leftJoin('employees', 'employees.id', '=', 'restaurant_orders.waiter_id')
            ->where('restaurant_orders.status', 'settled')
            ->whereBetween(DB::raw('DATE(restaurant_orders.settled_at)'), [$from, $to])
            ->groupBy('restaurant_orders.waiter_id', 'employees.first_name', 'employees.last_name')
            ->select([
                'restaurant_orders.waiter_id',
                'employees.first_name',
                'employees.last_name',
                DB::raw('COUNT(*) as orders_count'),
                DB::raw('COALESCE(SUM(restaurant_orders.guest_count), 0) as covers'),
                DB::raw('COALESCE(SUM(restaurant_orders.total), 0) as sales'),
                DB::raw('COALESCE(SUM(restaurant_orders.service_charge), 0) as service_charge'),
                DB::raw('COALESCE(AVG(restaurant_orders.total), 0) as avg_bill'),
                // Minutes from seating to settling — table turn time.
                DB::raw('COALESCE(AVG(TIMESTAMPDIFF(MINUTE, restaurant_orders.opened_at, restaurant_orders.settled_at)), 0) as avg_minutes'),
            ])
            ->orderByDesc('sales')
            ->get();

        // Voids per waiter, over the same window, in one query rather than a
        // correlated subquery per row.
        $voids = RestaurantOrder::query()
            ->join('restaurant_order_items', 'restaurant_order_items.restaurant_order_id', '=', 'restaurant_orders.id')
            ->where('restaurant_orders.status', 'settled')
            ->where('restaurant_order_items.status', 'void')
            ->whereBetween(DB::raw('DATE(restaurant_orders.settled_at)'), [$from, $to])
            ->groupBy('restaurant_orders.waiter_id')
            ->select('restaurant_orders.waiter_id', DB::raw('COUNT(*) as void_count'))
            ->pluck('void_count', 'waiter_id');

        $out = $rows->map(function ($r) use ($voids) {
            $covers = (int) $r->covers;

            return [
                'waiter_id' => $r->waiter_id,
                // Tickets with no waiter are real — a takeaway counter has none —
                // and are reported as such rather than dropped.
                'waiter' => $r->waiter_id
                    ? trim((string) $r->first_name.' '.(string) $r->last_name)
                    : 'Unassigned',
                'orders' => (int) $r->orders_count,
                'covers' => $covers,
                'sales' => round((float) $r->sales, 2),
                'service_charge' => round((float) $r->service_charge, 2),
                'avg_bill' => round((float) $r->avg_bill, 2),
                'avg_per_cover' => $covers > 0 ? round((float) $r->sales / $covers, 2) : null,
                'avg_minutes' => (int) round((float) $r->avg_minutes),
                'voids' => (int) ($voids[$r->waiter_id] ?? 0),
            ];
        });

        return response()->json([
            'data' => $out,
            'meta' => [
                'from' => $from,
                'to' => $to,
                'totals' => [
                    'orders' => (int) $out->sum('orders'),
                    'covers' => (int) $out->sum('covers'),
                    'sales' => round((float) $out->sum('sales'), 2),
                    'voids' => (int) $out->sum('voids'),
                ],
            ],
        ]);
    }
}
