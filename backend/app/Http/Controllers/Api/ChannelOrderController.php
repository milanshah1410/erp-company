<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ChannelOrderResource;
use App\Models\ChannelOrder;
use App\Services\Ecommerce\ChannelSyncService;
use Illuminate\Http\Request;
use Throwable;

/**
 * The marketplace order inbox (SRS §15): orders pulled from every connected
 * channel, which staff import into the sales pipeline or ignore.
 */
class ChannelOrderController extends Controller
{
    public function __construct(private readonly ChannelSyncService $sync) {}

    public function index(Request $request)
    {
        $query = ChannelOrder::query()
            ->with(['integration:id,name,platform', 'salesOrder:id,so_number'])
            ->orderByDesc('placed_at')
            ->orderByDesc('id');

        if ($request->filled('channel_integration_id')) {
            $query->where('channel_integration_id', $request->query('channel_integration_id'));
        }
        if ($request->filled('import_status')) {
            $query->where('import_status', $request->query('import_status'));
        }
        if ($search = $request->query('search')) {
            $query->where(fn ($q) => $q
                ->where('external_order_number', 'like', "%{$search}%")
                ->orWhere('external_order_id', 'like', "%{$search}%")
                ->orWhere('customer_name', 'like', "%{$search}%"));
        }

        $perPage = min((int) $request->query('per_page', 15), 100);

        return ChannelOrderResource::collection($query->paginate($perPage))
            ->additional(['pending_count' => ChannelOrder::where('import_status', 'pending')->count()]);
    }

    public function show(ChannelOrder $channelOrder)
    {
        return new ChannelOrderResource($channelOrder->load(['integration:id,name,platform', 'salesOrder:id,so_number']));
    }

    /** Import an inbox order into the sales pipeline as a SalesOrder. */
    public function import(ChannelOrder $channelOrder)
    {
        try {
            $salesOrder = $this->sync->importOrder($channelOrder);
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => "Imported as sales order {$salesOrder->so_number}.",
            'data' => new ChannelOrderResource($channelOrder->fresh()->load(['integration:id,name,platform', 'salesOrder:id,so_number'])),
        ]);
    }

    /** Mark an inbox order as ignored so it drops out of the pending queue. */
    public function ignore(ChannelOrder $channelOrder)
    {
        if ($channelOrder->import_status === 'imported') {
            return response()->json(['message' => 'This order has already been imported.'], 422);
        }

        $channelOrder->update(['import_status' => 'ignored']);

        return new ChannelOrderResource($channelOrder->load('integration:id,name,platform'));
    }
}
