<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\EmbroideryReceiptRequest;
use App\Http\Requests\EmbroideryWorkRequest;
use App\Http\Resources\EmbroideryWorkResource;
use App\Models\EmbroideryWork;
use App\Services\EmbroideryWorkService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class EmbroideryWorkController extends Controller
{
    public function __construct(private EmbroideryWorkService $service)
    {
    }

    public function index(Request $request)
    {
        $query = EmbroideryWork::query()
            ->with(['vendor:id,name,code', 'product:id,name,sku']);

        // The "Pending Dispatch" screen passes status=pending (open dispatches);
        // the "Received" screen passes status=completed.
        $status = $request->query('status');
        if ($status === 'pending') {
            $query->whereIn('status', ['pending', 'partial']);
        } elseif ($status === 'completed') {
            $query->where('status', 'completed');
        } elseif ($status) {
            $query->where('status', $status);
        }

        if ($request->filled('embroidery_vendor_id')) {
            $query->where('embroidery_vendor_id', $request->query('embroidery_vendor_id'));
        }

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('work_number', 'like', "%{$search}%")
                    ->orWhere('design_number', 'like', "%{$search}%")
                    ->orWhereHas('vendor', fn ($v) => $v->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('product', fn ($p) => $p->where('name', 'like', "%{$search}%"));
            });
        }

        $query->orderByDesc('id');

        $perPage = min((int) $request->query('per_page', 15), 100);

        return EmbroideryWorkResource::collection($query->paginate($perPage));
    }

    public function store(EmbroideryWorkRequest $request)
    {
        $data = $request->validated();
        $data['cost'] = round((float) $data['cost'], 2);
        $data['work_number'] = EmbroideryWork::nextNumber();
        $data['dispatch_date'] = $data['dispatch_date'] ?? now()->toDateString();
        $data['created_by'] = $request->user()?->id;

        $work = EmbroideryWork::create($data);

        return (new EmbroideryWorkResource($work->load(['vendor', 'product'])))
            ->response()
            ->setStatusCode(201);
    }

    public function show(EmbroideryWork $embroideryWork)
    {
        return new EmbroideryWorkResource($embroideryWork->load(['vendor', 'product']));
    }

    public function update(EmbroideryWorkRequest $request, EmbroideryWork $embroideryWork)
    {
        if ($embroideryWork->received_qty > 0) {
            return response()->json([
                'message' => 'A dispatch with recorded receipts cannot be edited.',
            ], 422);
        }

        $data = $request->validated();
        $data['cost'] = round((float) $data['cost'], 2);

        $embroideryWork->update($data);

        return new EmbroideryWorkResource($embroideryWork->load(['vendor', 'product']));
    }

    public function destroy(EmbroideryWork $embroideryWork)
    {
        if ($embroideryWork->receipts()->exists()) {
            return response()->json([
                'message' => 'A dispatch with recorded receipts cannot be deleted. Cancel it instead.',
            ], 422);
        }
        $embroideryWork->delete();

        return response()->json(['message' => 'Dispatch deleted.']);
    }

    /** Record a receipt (full or partial) against a dispatch. */
    public function receive(EmbroideryReceiptRequest $request, EmbroideryWork $embroideryWork)
    {
        if ($embroideryWork->status === 'cancelled') {
            throw ValidationException::withMessages(['qty' => 'This dispatch is cancelled.']);
        }

        $qty = round((float) $request->validated()['qty'], 2);
        if ($qty > $embroideryWork->pendingQty() + 0.005) {
            throw ValidationException::withMessages([
                'qty' => "Cannot receive more than the pending {$embroideryWork->pendingQty()} pieces.",
            ]);
        }

        $data = $request->validated();
        $data['created_by'] = $request->user()?->id;

        $this->service->receive($embroideryWork, $data);

        return new EmbroideryWorkResource($embroideryWork->fresh()->load(['vendor', 'product']));
    }
}
