<?php

declare(strict_types=1);

namespace App\Modules\Industry\Retail\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Industry\Retail\Models\GarmentSizeSet;
use App\Modules\Industry\Retail\Models\GarmentStyle;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Size runs — the columns of the matrix.
 *
 * A set is stored and edited whole (`sizes` is an ordered JSON list) because
 * that is how it is used: nobody adds "L" to a shop, they define the run a kind
 * of garment is cut in. The order is load-bearing — it is the column order of
 * every grid and size-wise report, and sorting it alphabetically is the classic
 * way to make one unreadable.
 */
class GarmentSizeSetController extends Controller
{
    public function index(Request $request)
    {
        $query = GarmentSizeSet::query();

        if ($search = $request->query('search')) {
            $query->where('name', 'like', "%{$search}%");
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $query->orderBy('name');

        return response()->json(
            $query->paginate(min((int) $request->query('per_page', 50), 100))
                ->through(fn (GarmentSizeSet $s) => $this->row($s))->toArray()
                // The runs a shop that has defined none of its own can start
                // from. Offered, never auto-created: a shop that sells only
                // free-size kurtis should not inherit a collar run it will spend
                // a year ignoring.
                + ['starters' => GarmentSizeSet::STARTERS],
        );
    }

    public function store(Request $request)
    {
        $set = GarmentSizeSet::create($this->validated($request));

        return response()->json(['data' => $this->row($set)], 201);
    }

    public function show(GarmentSizeSet $sizeSet)
    {
        return response()->json(['data' => $this->row($sizeSet)]);
    }

    public function update(Request $request, GarmentSizeSet $sizeSet)
    {
        $sizeSet->update($this->validated($request, $sizeSet->id));

        return response()->json(['data' => $this->row($sizeSet->fresh())]);
    }

    public function destroy(GarmentSizeSet $sizeSet)
    {
        // Only the *default* grid for a style, so removing it costs nothing that
        // has already been generated — but a style pointing at a deleted set
        // would open the matrix screen with no columns, so it is refused.
        if (GarmentStyle::where('size_set_id', $sizeSet->id)->exists()) {
            return response()->json([
                'message' => 'Styles are cut to this size run. Mark it inactive instead of deleting it.',
            ], 422);
        }

        $sizeSet->delete();

        return response()->json(['message' => 'Size run removed.']);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?int $id = null): array
    {
        $tenantId = app(TenantContext::class)->id();

        $data = $request->validate([
            'name' => [
                'required', 'string', 'max:80',
                Rule::unique('garment_size_sets', 'name')->where('tenant_id', $tenantId)->ignore($id),
            ],
            'sizes' => ['required', 'array', 'min:1', 'max:40'],
            'sizes.*' => ['required', 'string', 'max:12'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        // Normalised here rather than on the model so the stored order is the
        // order the screen showed, with blanks and repeats already gone.
        $data['sizes'] = (new GarmentSizeSet(['sizes' => $data['sizes']]))->sizeList();

        return $data;
    }

    private function row(GarmentSizeSet $s): array
    {
        $sizes = $s->sizeList();

        return [
            'id' => $s->id,
            'name' => $s->name,
            'sizes' => $sizes,
            'size_count' => count($sizes),
            // Rendered as the run itself in a list column — "XS · S · M · L" says
            // more at a glance than "7 sizes".
            'summary' => implode(' · ', $sizes),
            'is_active' => (bool) $s->is_active,
        ];
    }
}
