<?php

declare(strict_types=1);

namespace App\Modules\Industry\Cosmetics\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Industry\Cosmetics\Http\Requests\ShadeRequest;
use App\Modules\Industry\Cosmetics\Models\CosmeticShade;
use Illuminate\Http\Request;

/**
 * The shade master.
 *
 * Small register, and everything else in the vertical reads it: the card
 * generates variants from it, the shelf label prints off it, and the answer to
 * "have you got this in something warmer?" is a query against it.
 */
class ShadeController extends Controller
{
    public function index(Request $request)
    {
        $query = CosmeticShade::query()
            ->with('brand:id,name')
            ->withCount(['products', 'variants']);

        if ($search = $request->query('search')) {
            $query->where(fn ($q) => $q->where('code', 'like', "%{$search}%")
                ->orWhere('name', 'like', "%{$search}%")
                ->orWhere('family', 'like', "%{$search}%"));
        }

        foreach (['brand_id', 'family', 'finish', 'undertone'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->query($filter));
            }
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $query->cardOrder();

        return response()->json([
            'data' => $query->get()->map(fn (CosmeticShade $s) => $this->row($s))->all(),
            /**
             * The vocabularies ride along with the list so the form, the filters
             * and the card picker all render from one source. They are closed
             * sets in the schema; shipping them separately would mean three
             * places to update when one changes.
             */
            'meta' => [
                'finishes' => CosmeticShade::FINISHES,
                'undertones' => CosmeticShade::UNDERTONES,
                // Families are the shop's own words, so the list is whatever the
                // shop has actually used — a picker, not a vocabulary.
                'families' => CosmeticShade::query()
                    ->whereNotNull('family')->where('family', '!=', '')
                    ->distinct()->orderBy('family')->pluck('family')->all(),
            ],
        ]);
    }

    public function show(CosmeticShade $shade)
    {
        return response()->json(['data' => $this->row($shade->load('brand:id,name')->loadCount(['products', 'variants']))]);
    }

    public function store(ShadeRequest $request)
    {
        $shade = CosmeticShade::create($request->validated());

        return response()->json(['data' => $this->row($shade->load('brand:id,name')->loadCount(['products', 'variants']))], 201);
    }

    public function update(ShadeRequest $request, CosmeticShade $shade)
    {
        $shade->update($request->validated());

        return response()->json(['data' => $this->row($shade->load('brand:id,name')->loadCount(['products', 'variants']))]);
    }

    public function destroy(CosmeticShade $shade)
    {
        /**
         * Deleting a shade in use would null it on every article and variant
         * carrying it — turning a shade card back into a list of anonymous SKUs
         * nobody can match to a customer. Deactivating takes it out of the picker
         * and leaves the range identified, which is what "we don't carry that
         * shade any more" actually means.
         */
        if ($shade->products()->exists() || $shade->variants()->exists()) {
            return response()->json([
                'message' => 'Articles are keyed to this shade. Deactivate it instead of deleting it.',
            ], 422);
        }

        $shade->delete();

        return response()->json(['message' => 'Shade removed.']);
    }

    private function row(CosmeticShade $shade): array
    {
        return [
            'id' => $shade->id,
            'code' => $shade->code,
            'name' => $shade->name,
            'display_name' => $shade->displayName(),
            'brand_id' => $shade->brand_id,
            'brand_name' => $shade->brand?->name,
            'family' => $shade->family,
            'finish' => $shade->finish,
            'undertone' => $shade->undertone,
            'depth_level' => $shade->depth_level,
            'hex' => $shade->hex,
            'descriptor' => $shade->descriptor(),
            'description' => $shade->description,
            'sort_order' => (int) $shade->sort_order,
            'is_active' => (bool) $shade->is_active,
            // Both counts, because the two shop styles put the shade in different
            // places (see the migration) and a buyer needs to see either.
            'products_count' => (int) ($shade->products_count ?? 0),
            'variants_count' => (int) ($shade->variants_count ?? 0),
        ];
    }
}
