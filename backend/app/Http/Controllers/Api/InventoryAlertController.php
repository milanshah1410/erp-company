<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InventoryAlert;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

class InventoryAlertController extends Controller
{
    public function index(Request $request)
    {
        $query = InventoryAlert::query()->with(['product:id,sku,name', 'warehouse:id,name', 'batch:id,batch_number,expiry_date']);

        if ($request->filled('type')) {
            $query->where('type', $request->query('type'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        } else {
            $query->where('status', '!=', 'resolved');
        }

        $query->orderByDesc('severity')->orderByDesc('id');
        $perPage = min((int) $request->query('per_page', 20), 200);

        return $query->paginate($perPage);
    }

    public function acknowledge(Request $request, InventoryAlert $inventoryAlert)
    {
        $inventoryAlert->update([
            'status' => 'acknowledged',
            'acknowledged_by' => $request->user()?->id,
            'acknowledged_at' => now(),
        ]);

        return $inventoryAlert;
    }

    public function resolve(InventoryAlert $inventoryAlert)
    {
        $inventoryAlert->update(['status' => 'resolved', 'resolved_at' => now()]);

        return $inventoryAlert;
    }

    public function destroy(InventoryAlert $inventoryAlert)
    {
        $inventoryAlert->delete();

        return response()->json(['message' => 'Alert deleted.']);
    }

    /** Manually re-run the alert scan (also runs daily via the scheduler). */
    public function generate()
    {
        Artisan::call('inventory:generate-alerts');

        return response()->json(['message' => trim(Artisan::output())]);
    }
}
