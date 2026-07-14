<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\HandlesExpenseApproval;
use App\Http\Controllers\Controller;
use App\Http\Requests\FactoryMaintenanceRequest;
use App\Http\Resources\FactoryMaintenanceResource;
use App\Models\FactoryMaintenance;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Factory Maintenance — plant / machine / premises upkeep on the shared
 * approval workflow, capturing downtime. The report rolls up cost and downtime
 * by maintenance type.
 */
class FactoryMaintenanceController extends Controller
{
    use HandlesExpenseApproval;

    public function index(Request $request)
    {
        $query = FactoryMaintenance::query()->with(['branch:id,name', 'asset:id,name,asset_code']);

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('maintenance_number', 'like', "%{$search}%")
                    ->orWhere('area', 'like', "%{$search}%")
                    ->orWhere('performed_by', 'like', "%{$search}%");
            });
        }
        foreach (['status', 'branch_id', 'asset_id', 'maintenance_type'] as $f) {
            if ($request->filled($f)) {
                $query->where($f, $request->query($f));
            }
        }
        if ($request->filled('date_from')) {
            $query->whereDate('maintenance_date', '>=', $request->query('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('maintenance_date', '<=', $request->query('date_to'));
        }

        $query->orderByDesc('maintenance_date')->orderByDesc('id');
        $perPage = min((int) $request->query('per_page', 15), 100);

        return FactoryMaintenanceResource::collection($query->paginate($perPage));
    }

    public function store(FactoryMaintenanceRequest $request)
    {
        $record = FactoryMaintenance::create([
            ...$request->validated(),
            'maintenance_number' => FactoryMaintenance::nextNumber(),
            'status' => 'draft',
            'created_by' => $request->user()?->id,
        ]);

        return (new FactoryMaintenanceResource($record->load($this->relations())))
            ->response()->setStatusCode(201);
    }

    public function show(FactoryMaintenance $factoryMaintenance)
    {
        return new FactoryMaintenanceResource($factoryMaintenance->load($this->relations()));
    }

    public function update(FactoryMaintenanceRequest $request, FactoryMaintenance $factoryMaintenance)
    {
        if (! $factoryMaintenance->isEditable()) {
            return response()->json(['message' => 'Only draft or rejected records can be edited.'], 422);
        }
        $factoryMaintenance->update([...$request->validated(), 'status' => 'draft', 'rejection_reason' => null]);

        return new FactoryMaintenanceResource($factoryMaintenance->load($this->relations()));
    }

    public function destroy(FactoryMaintenance $factoryMaintenance)
    {
        if (! $factoryMaintenance->isEditable()) {
            return response()->json(['message' => 'Only draft or rejected records can be deleted.'], 422);
        }
        $this->deleteAttachment($factoryMaintenance);
        $factoryMaintenance->delete();

        return response()->json(['message' => 'Factory maintenance record deleted.']);
    }

    public function submit(FactoryMaintenance $factoryMaintenance)
    {
        return $this->doSubmit($factoryMaintenance);
    }

    public function approve(Request $request, FactoryMaintenance $factoryMaintenance)
    {
        return $this->doApprove($request, $factoryMaintenance);
    }

    public function reject(Request $request, FactoryMaintenance $factoryMaintenance)
    {
        return $this->doReject($request, $factoryMaintenance);
    }

    public function markPaid(Request $request, FactoryMaintenance $factoryMaintenance)
    {
        return $this->doMarkPaid($request, $factoryMaintenance);
    }

    public function uploadAttachment(Request $request, FactoryMaintenance $factoryMaintenance)
    {
        return $this->doUploadAttachment($request, $factoryMaintenance);
    }

    /** Approved cost and downtime grouped by maintenance type. */
    public function report(Request $request)
    {
        $query = FactoryMaintenance::query()
            ->where('status', $request->query('status', 'approved'));

        if ($request->filled('date_from')) {
            $query->whereDate('maintenance_date', '>=', $request->query('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('maintenance_date', '<=', $request->query('date_to'));
        }
        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->query('branch_id'));
        }

        $rows = $query
            ->selectRaw('maintenance_type, COUNT(*) as count,
                SUM(downtime_hours) as downtime, SUM(amount) as total')
            ->groupBy('maintenance_type')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($r) => [
                'maintenance_type' => $r->maintenance_type,
                'count' => (int) $r->count,
                'downtime_hours' => round((float) $r->downtime, 2),
                'total' => (float) $r->total,
            ]);

        return response()->json(['data' => $rows, 'total' => round($rows->sum('total'), 2)]);
    }

    protected function relations(): array
    {
        return ['branch', 'asset', 'creator', 'approver'];
    }

    protected function attachmentDir(): string
    {
        return 'factory-maintenance';
    }

    protected function resource(Model $model)
    {
        return new FactoryMaintenanceResource($model);
    }
}
