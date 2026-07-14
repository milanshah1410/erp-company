<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\TailorAlterationRequest;
use App\Http\Resources\TailorAlterationResource;
use App\Models\TailorAlteration;
use Illuminate\Http\Request;

/**
 * Alteration tracking: pieces sent back for adjustment after delivery. Each
 * alteration moves pending → in_progress → done; completing it stamps the date.
 */
class TailorAlterationController extends Controller
{
    private const RELATIONS = ['tailor:id,name', 'product:id,name,sku', 'work:id,work_number'];

    public function index(Request $request)
    {
        $query = TailorAlteration::query()->with(self::RELATIONS);

        if ($request->filled('tailor_id')) {
            $query->where('tailor_id', $request->query('tailor_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }
        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('alteration_number', 'like', "%{$search}%")
                    ->orWhere('reason', 'like', "%{$search}%")
                    ->orWhereHas('tailor', fn ($t) => $t->where('name', 'like', "%{$search}%"));
            });
        }

        $query->orderByDesc('id');

        $perPage = min((int) $request->query('per_page', 15), 100);

        return TailorAlterationResource::collection($query->paginate($perPage));
    }

    public function store(TailorAlterationRequest $request)
    {
        $data = $request->validated();
        $data['alteration_number'] = TailorAlteration::nextNumber();
        $data['reported_date'] = $data['reported_date'] ?? now()->toDateString();
        $data['status'] = $data['status'] ?? 'pending';
        $data['created_by'] = $request->user()?->id;
        // Keep completed_date in step with a status set directly to done.
        if (($data['status'] ?? null) === 'done' && empty($data['completed_date'])) {
            $data['completed_date'] = now()->toDateString();
        }

        $alteration = TailorAlteration::create($data);

        return (new TailorAlterationResource($alteration->load(self::RELATIONS)))
            ->response()
            ->setStatusCode(201);
    }

    public function show(TailorAlteration $tailorAlteration)
    {
        return new TailorAlterationResource($tailorAlteration->load(self::RELATIONS));
    }

    public function update(TailorAlterationRequest $request, TailorAlteration $tailorAlteration)
    {
        $data = $request->validated();
        // Stamp/clear the completion date to follow the status.
        if (($data['status'] ?? $tailorAlteration->status) === 'done' && empty($data['completed_date']) && ! $tailorAlteration->completed_date) {
            $data['completed_date'] = now()->toDateString();
        } elseif (($data['status'] ?? null) && $data['status'] !== 'done') {
            $data['completed_date'] = null;
        }

        $tailorAlteration->update($data);

        return new TailorAlterationResource($tailorAlteration->load(self::RELATIONS));
    }

    public function destroy(TailorAlteration $tailorAlteration)
    {
        $tailorAlteration->delete();

        return response()->json(['message' => 'Alteration deleted.']);
    }

    /** Mark an alteration done, stamping the completion date. */
    public function complete(Request $request, TailorAlteration $tailorAlteration)
    {
        $tailorAlteration->update([
            'status' => 'done',
            'completed_date' => $request->input('completed_date', now()->toDateString()),
        ]);

        return new TailorAlterationResource($tailorAlteration->load(self::RELATIONS));
    }
}
