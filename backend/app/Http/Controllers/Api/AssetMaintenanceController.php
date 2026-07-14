<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\HandlesExpenseApproval;
use App\Http\Controllers\Controller;
use App\Http\Requests\AssetMaintenanceRequest;
use App\Http\Resources\AssetMaintenanceResource;
use App\Models\AssetMaintenance;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Asset Maintenance — servicing events on fixed assets (repair, preventive,
 * calibration, AMC…) on the shared approval workflow. Tracks the next-service
 * date so upcoming servicing can be surfaced.
 */
class AssetMaintenanceController extends Controller
{
    use HandlesExpenseApproval;

    public function index(Request $request)
    {
        $query = AssetMaintenance::query()->with(['asset:id,name,asset_code', 'branch:id,name']);

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('maintenance_number', 'like', "%{$search}%")
                    ->orWhere('performed_by', 'like', "%{$search}%");
            });
        }
        foreach (['status', 'asset_id', 'branch_id', 'maintenance_type'] as $f) {
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

        return AssetMaintenanceResource::collection($query->paginate($perPage));
    }

    public function store(AssetMaintenanceRequest $request)
    {
        $record = AssetMaintenance::create([
            ...$request->validated(),
            'maintenance_number' => AssetMaintenance::nextNumber(),
            'status' => 'draft',
            'created_by' => $request->user()?->id,
        ]);

        return (new AssetMaintenanceResource($record->load($this->relations())))
            ->response()->setStatusCode(201);
    }

    public function show(AssetMaintenance $assetMaintenance)
    {
        return new AssetMaintenanceResource($assetMaintenance->load($this->relations()));
    }

    public function update(AssetMaintenanceRequest $request, AssetMaintenance $assetMaintenance)
    {
        if (! $assetMaintenance->isEditable()) {
            return response()->json(['message' => 'Only draft or rejected records can be edited.'], 422);
        }
        $assetMaintenance->update([...$request->validated(), 'status' => 'draft', 'rejection_reason' => null]);

        return new AssetMaintenanceResource($assetMaintenance->load($this->relations()));
    }

    public function destroy(AssetMaintenance $assetMaintenance)
    {
        if (! $assetMaintenance->isEditable()) {
            return response()->json(['message' => 'Only draft or rejected records can be deleted.'], 422);
        }
        $this->deleteAttachment($assetMaintenance);
        $assetMaintenance->delete();

        return response()->json(['message' => 'Asset maintenance record deleted.']);
    }

    public function submit(AssetMaintenance $assetMaintenance)
    {
        return $this->doSubmit($assetMaintenance);
    }

    public function approve(Request $request, AssetMaintenance $assetMaintenance)
    {
        return $this->doApprove($request, $assetMaintenance);
    }

    public function reject(Request $request, AssetMaintenance $assetMaintenance)
    {
        return $this->doReject($request, $assetMaintenance);
    }

    public function markPaid(Request $request, AssetMaintenance $assetMaintenance)
    {
        return $this->doMarkPaid($request, $assetMaintenance);
    }

    public function uploadAttachment(Request $request, AssetMaintenance $assetMaintenance)
    {
        return $this->doUploadAttachment($request, $assetMaintenance);
    }

    /**
     * Upcoming servicing: the latest next_service_date recorded per asset that
     * falls on/after today, soonest first. Optional `within_days` caps the
     * horizon.
     */
    public function upcoming(Request $request)
    {
        $query = AssetMaintenance::query()
            ->with('asset:id,name,asset_code')
            ->whereNotNull('next_service_date')
            ->whereDate('next_service_date', '>=', now()->toDateString());

        if ($request->filled('within_days')) {
            $query->whereDate('next_service_date', '<=', now()->addDays((int) $request->query('within_days'))->toDateString());
        }

        // Keep only the most recent record per asset (its current due date).
        $rows = $query->orderByDesc('maintenance_date')->orderByDesc('id')->get()
            ->unique('asset_id')
            ->map(fn ($r) => [
                'id' => $r->id,
                'asset_id' => $r->asset_id,
                'asset' => $r->asset?->name,
                'asset_code' => $r->asset?->asset_code,
                'maintenance_type' => $r->maintenance_type,
                'last_service_date' => $r->maintenance_date?->toDateString(),
                'next_service_date' => $r->next_service_date?->toDateString(),
                'days_remaining' => (int) round(now()->startOfDay()->diffInDays($r->next_service_date, false)),
            ])
            ->sortBy('next_service_date')
            ->values();

        return response()->json(['data' => $rows]);
    }

    /** Per-asset approved maintenance cost history. */
    public function report(Request $request)
    {
        $query = AssetMaintenance::query()
            ->join('assets', 'assets.id', '=', 'asset_maintenances.asset_id')
            ->where('asset_maintenances.status', $request->query('status', 'approved'));

        if ($request->filled('date_from')) {
            $query->whereDate('asset_maintenances.maintenance_date', '>=', $request->query('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('asset_maintenances.maintenance_date', '<=', $request->query('date_to'));
        }

        $rows = $query
            ->selectRaw('asset_maintenances.asset_id, assets.asset_code, assets.name,
                COUNT(*) as count, SUM(asset_maintenances.amount) as total')
            ->groupBy('asset_maintenances.asset_id', 'assets.asset_code', 'assets.name')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($r) => [
                'asset_id' => $r->asset_id,
                'asset_code' => $r->asset_code,
                'asset' => $r->name,
                'count' => (int) $r->count,
                'total' => (float) $r->total,
            ]);

        return response()->json(['data' => $rows, 'total' => round($rows->sum('total'), 2)]);
    }

    protected function relations(): array
    {
        return ['asset', 'branch', 'creator', 'approver'];
    }

    protected function attachmentDir(): string
    {
        return 'asset-maintenance';
    }

    protected function resource(Model $model)
    {
        return new AssetMaintenanceResource($model);
    }
}
