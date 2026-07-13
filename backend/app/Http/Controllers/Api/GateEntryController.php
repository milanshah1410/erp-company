<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GateEntry;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Inward/outward gate entry logging: records vehicles entering/leaving a
 * warehouse, optionally tagged against a GRN, sales order, transfer,
 * purchase order, or stock return. Pure logging — no stock impact.
 */
class GateEntryController extends Controller
{
    public function index(Request $request)
    {
        $query = GateEntry::query()->with(['warehouse:id,name']);

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('entry_number', 'like', "%{$search}%")
                    ->orWhere('vehicle_number', 'like', "%{$search}%");
            });
        }
        if ($request->filled('warehouse_id')) {
            $query->where('warehouse_id', $request->query('warehouse_id'));
        }
        if ($request->filled('direction')) {
            $query->where('direction', $request->query('direction'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        $query->orderByDesc('id');
        $perPage = min((int) $request->query('per_page', 15), 100);
        $page = $query->paginate($perPage);

        return response()->json([
            'data' => $page->getCollection()->map(fn ($g) => $this->payload($g)),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        $entry = GateEntry::create([
            'entry_number' => GateEntry::nextNumber(),
            'warehouse_id' => $data['warehouse_id'],
            'direction' => $data['direction'],
            'vehicle_number' => $data['vehicle_number'],
            'driver_name' => $data['driver_name'] ?? null,
            'driver_contact' => $data['driver_contact'] ?? null,
            'reference_type' => $data['reference_type'] ?? null,
            'reference_id' => $data['reference_id'] ?? null,
            'reference_number' => $data['reference_number'] ?? null,
            'purpose' => $data['purpose'] ?? null,
            'status' => 'open',
            'gate_in_at' => now(),
            'logged_by' => $request->user()?->id,
        ]);

        return response()->json(['data' => $this->payload($entry->load('warehouse'))], 201);
    }

    public function show(GateEntry $gateEntry)
    {
        return response()->json(['data' => $this->payload($gateEntry->load('warehouse'))]);
    }

    public function update(Request $request, GateEntry $gateEntry)
    {
        if ($gateEntry->status !== 'open') {
            return response()->json(['message' => 'Only open gate entries can be edited.'], 422);
        }
        $data = $this->validated($request);

        $gateEntry->update([
            'warehouse_id' => $data['warehouse_id'],
            'direction' => $data['direction'],
            'vehicle_number' => $data['vehicle_number'],
            'driver_name' => $data['driver_name'] ?? null,
            'driver_contact' => $data['driver_contact'] ?? null,
            'reference_type' => $data['reference_type'] ?? null,
            'reference_id' => $data['reference_id'] ?? null,
            'reference_number' => $data['reference_number'] ?? null,
            'purpose' => $data['purpose'] ?? null,
        ]);

        return response()->json(['data' => $this->payload($gateEntry->load('warehouse'))]);
    }

    public function destroy(GateEntry $gateEntry)
    {
        if ($gateEntry->status !== 'open') {
            return response()->json(['message' => 'Only open gate entries can be deleted.'], 422);
        }
        $gateEntry->delete();

        return response()->json(['message' => 'Gate entry deleted.']);
    }

    public function close(Request $request, GateEntry $gateEntry)
    {
        if ($gateEntry->status !== 'open') {
            return response()->json(['message' => 'This gate entry is already closed.'], 422);
        }

        $gateEntry->update([
            'status' => 'closed',
            'gate_out_at' => now(),
            'closed_by' => $request->user()?->id,
        ]);

        return response()->json(['data' => $this->payload($gateEntry->fresh()->load('warehouse'))]);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'warehouse_id' => ['required', 'exists:warehouses,id'],
            'direction' => ['required', Rule::in(['inward', 'outward'])],
            'vehicle_number' => ['required', 'string', 'max:50'],
            'driver_name' => ['nullable', 'string', 'max:100'],
            'driver_contact' => ['nullable', 'string', 'max:50'],
            'reference_type' => ['nullable', Rule::in([
                'goods_receipt', 'sales_order', 'stock_transfer', 'purchase_order', 'stock_return', 'other',
            ])],
            'reference_id' => ['nullable', 'integer'],
            'reference_number' => ['nullable', 'string', 'max:100'],
            'purpose' => ['nullable', 'string'],
        ]);
    }

    private function payload(GateEntry $g): array
    {
        return [
            'id' => $g->id,
            'entry_number' => $g->entry_number,
            'warehouse_id' => $g->warehouse_id,
            'warehouse' => $g->relationLoaded('warehouse') && $g->warehouse
                ? ['id' => $g->warehouse->id, 'name' => $g->warehouse->name] : null,
            'direction' => $g->direction,
            'vehicle_number' => $g->vehicle_number,
            'driver_name' => $g->driver_name,
            'driver_contact' => $g->driver_contact,
            'reference_type' => $g->reference_type,
            'reference_id' => $g->reference_id,
            'reference_number' => $g->reference_number,
            'purpose' => $g->purpose,
            'status' => $g->status,
            'gate_in_at' => $g->gate_in_at,
            'gate_out_at' => $g->gate_out_at,
            'created_at' => $g->created_at,
        ];
    }
}
