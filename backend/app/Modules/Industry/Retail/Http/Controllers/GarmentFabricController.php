<?php

declare(strict_types=1);

namespace App\Modules\Industry\Retail\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Industry\Retail\Http\Requests\GarmentFabricRequest;
use App\Modules\Industry\Retail\Models\GarmentFabric;
use Illuminate\Http\Request;

/**
 * The fabric register — what the shop's garments are made of.
 *
 * Small on purpose. A showroom needs composition, width, GSM and a care line;
 * the roll-level machinery (inspection, shrinkage, cutting) is the Textile
 * module's and belongs to a mill.
 */
class GarmentFabricController extends Controller
{
    public function index(Request $request)
    {
        $query = GarmentFabric::query()->with('supplier:id,name');

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('composition', 'like', "%{$search}%");
            });
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        if ($request->filled('supplier_id')) {
            $query->where('supplier_id', $request->query('supplier_id'));
        }

        $query->orderBy('name');

        return response()->json(
            $query->paginate(min((int) $request->query('per_page', 25), 100))
                ->through(fn (GarmentFabric $f) => $this->row($f))->toArray(),
        );
    }

    public function store(GarmentFabricRequest $request)
    {
        $fabric = GarmentFabric::create($request->validated());

        return response()->json(['data' => $this->row($fabric->fresh('supplier'))], 201);
    }

    public function show(GarmentFabric $fabric)
    {
        return response()->json(['data' => $this->row($fabric->load('supplier:id,name'))]);
    }

    public function update(GarmentFabricRequest $request, GarmentFabric $fabric)
    {
        $fabric->update($request->validated());

        return response()->json(['data' => $this->row($fabric->fresh('supplier'))]);
    }

    public function destroy(GarmentFabric $fabric)
    {
        // Styles name their fabric on the label and in the reorder sheet.
        // Removing it underneath them would leave a showroom unable to say what
        // it is selling, so the fabric is deactivated instead.
        if ($fabric->styles()->exists()) {
            return response()->json([
                'message' => 'Styles are cut from this fabric. Mark it inactive instead of deleting it.',
            ], 422);
        }

        $fabric->delete();

        return response()->json(['message' => 'Fabric removed.']);
    }

    private function row(GarmentFabric $f): array
    {
        return [
            'id' => $f->id,
            'code' => $f->code,
            'name' => $f->name,
            'composition' => $f->composition,
            'weave' => $f->weave,
            'gsm' => $f->gsm,
            'width_inch' => $f->width_inch === null ? null : (float) $f->width_inch,
            'shrinkage_percent' => $f->shrinkage_percent === null ? null : (float) $f->shrinkage_percent,
            'care' => $f->care,
            'rate' => (float) $f->rate,
            'supplier_id' => $f->supplier_id,
            'supplier_name' => $f->supplier?->name,
            'styles_count' => $f->styles()->count(),
            'notes' => $f->notes,
            'is_active' => (bool) $f->is_active,
        ];
    }
}
