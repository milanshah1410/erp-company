<?php

declare(strict_types=1);

namespace App\Modules\Industry\Toys\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Industry\Toys\Http\Requests\AgeGradeRequest;
use App\Modules\Industry\Toys\Models\AgeGrade;
use Illuminate\Http\Request;

/**
 * The shop's age scale.
 *
 * Small register, disproportionate consequences: the band on an article decides
 * what the shelf label says, what the counter is prompted with, and — read
 * against the safety class — whether the shop has quietly put a choking hazard
 * on the toddler shelf. Everything else in the vertical reads it.
 */
class AgeGradeController extends Controller
{
    public function index(Request $request)
    {
        $query = AgeGrade::query()->withCount('products');

        if ($search = $request->query('search')) {
            $query->where(fn ($q) => $q->where('code', 'like', "%{$search}%")
                ->orWhere('label', 'like', "%{$search}%"));
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        // Sorted the way a shelf is, youngest first, because that is the order
        // the bands are read in and the order a picker should offer them.
        $query->orderBy('sort_order')->orderBy('min_months');

        return response()->json([
            'data' => $query->get()->map(fn (AgeGrade $g) => $this->row($g))->all(),
        ]);
    }

    public function show(AgeGrade $grade)
    {
        return response()->json(['data' => $this->row($grade->loadCount('products'))]);
    }

    public function store(AgeGradeRequest $request)
    {
        $grade = AgeGrade::create($request->validated());

        return response()->json(['data' => $this->row($grade->loadCount('products'))], 201);
    }

    public function update(AgeGradeRequest $request, AgeGrade $grade)
    {
        $grade->update($request->validated());

        return response()->json(['data' => $this->row($grade->loadCount('products'))]);
    }

    public function destroy(AgeGrade $grade)
    {
        /**
         * Deleting a band in use would null the grade on every article carrying
         * it — silently un-labelling a shelf full of toys. Deactivating keeps
         * them graded and takes the band out of the picker, which is what
         * "we don't use that band any more" actually means.
         */
        if ($grade->products()->exists()) {
            return response()->json([
                'message' => 'Articles are graded into this band. Deactivate it instead of deleting it.',
            ], 422);
        }

        $grade->delete();

        return response()->json(['message' => 'Age band removed.']);
    }

    private function row(AgeGrade $grade): array
    {
        return [
            'id' => $grade->id,
            'code' => $grade->code,
            'label' => $grade->label,
            'min_months' => (int) $grade->min_months,
            'max_months' => $grade->max_months,
            'range' => $grade->range(),
            'warning' => $grade->warning,
            'requires_supervision' => (bool) $grade->requires_supervision,
            'description' => $grade->description,
            'sort_order' => (int) $grade->sort_order,
            'is_active' => (bool) $grade->is_active,
            'products_count' => (int) ($grade->products_count ?? 0),
        ];
    }
}
