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
    private const RELATIONS = [
        'tailor:id,name', 'product:id,name,sku', 'work:id,work_number',
        // The retail half of the document — see the note on the model.
        'customer:id,name,phone', 'invoice:id,invoice_number', 'variant:id,sku,name',
    ];

    public function index(Request $request)
    {
        $query = TailorAlteration::query()->with(self::RELATIONS);

        foreach (['tailor_id', 'status', 'customer_id', 'invoice_id'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->query($filter));
            }
        }
        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('alteration_number', 'like', "%{$search}%")
                    ->orWhere('reason', 'like', "%{$search}%")
                    ->orWhereHas('tailor', fn ($t) => $t->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%"));
            });
        }

        /**
         * The counter's chase list: promised and not delivered. Filtered in SQL
         * rather than after paging, or page 2 would quietly hold late jobs
         * nobody sees.
         */
        if ($request->boolean('late')) {
            $query->where('status', '!=', 'done')
                ->whereNotNull('promised_date')
                ->whereDate('promised_date', '<', now()->toDateString());
        }

        /**
         * Open work first, soonest promise at the top. This screen is worked
         * rather than browsed — what is due today is what matters — and rows with
         * no promise date fall behind those that have one rather than sorting to
         * the top as NULLs otherwise would.
         */
        $query->orderByRaw("CASE WHEN status = 'done' THEN 1 ELSE 0 END")
            ->orderByRaw('promised_date IS NULL')
            ->orderBy('promised_date')
            ->orderByDesc('id');

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
