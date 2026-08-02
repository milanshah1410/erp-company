<?php

declare(strict_types=1);

namespace App\Modules\Industry\Retail\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Industry\Retail\Models\GarmentColour;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The colour master — the rows of the matrix.
 *
 * One row per colour rather than a set (the way sizes are held), because colours
 * are picked individually per style, carry a swatch, and accumulate over a
 * season: nobody orders "the colour run", they add Mustard in August.
 */
class GarmentColourController extends Controller
{
    public function index(Request $request)
    {
        $query = GarmentColour::query();

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%");
            });
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $query->orderBy('sort_order')->orderBy('name');

        return response()->json(
            $query->paginate(min((int) $request->query('per_page', 100), 200))
                ->through(fn (GarmentColour $c) => $this->row($c))->toArray()
                + ['starters' => $this->starters()],
        );
    }

    public function store(Request $request)
    {
        $colour = GarmentColour::create($this->validated($request));

        return response()->json(['data' => $this->row($colour)], 201);
    }

    public function show(GarmentColour $colour)
    {
        return response()->json(['data' => $this->row($colour)]);
    }

    public function update(Request $request, GarmentColour $colour)
    {
        $colour->update($this->validated($request, $colour->id));

        return response()->json(['data' => $this->row($colour->fresh())]);
    }

    public function destroy(GarmentColour $colour)
    {
        /**
         * Safe to delete outright, unlike a fabric or a size run. The colour of
         * a variant is copied onto the variant when the matrix generates it —
         * the grid is reconstructed from `product_variants.attributes`, not by
         * joining back here — so removing the master row cannot orphan stock.
         */
        $colour->delete();

        return response()->json(['message' => 'Colour removed.']);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?int $id = null): array
    {
        $tenantId = app(TenantContext::class)->id();

        return $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'code' => [
                'required', 'string', 'max:12',
                Rule::unique('garment_colours', 'code')->where('tenant_id', $tenantId)->ignore($id),
            ],
            // A swatch, and nothing depends on it — which is why it is optional.
            // Nobody should be blocked from adding "Mehendi" for want of a hex.
            'hex' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['nullable', 'boolean'],
        ]);
    }

    /**
     * The starting palette, minus anything the shop already has — so the "add
     * the basics" button never offers a duplicate that would fail on the unique
     * code.
     *
     * @return list<array{code: string, name: string, hex: string}>
     */
    private function starters(): array
    {
        $existing = GarmentColour::pluck('code')->all();

        $out = [];
        foreach (GarmentColour::STARTERS as $code => [$name, $hex]) {
            if (! in_array($code, $existing, true)) {
                $out[] = ['code' => $code, 'name' => $name, 'hex' => $hex];
            }
        }

        return $out;
    }

    private function row(GarmentColour $c): array
    {
        return [
            'id' => $c->id,
            'name' => $c->name,
            'code' => $c->code,
            'hex' => $c->hex,
            'sort_order' => (int) $c->sort_order,
            'is_active' => (bool) $c->is_active,
        ];
    }
}
