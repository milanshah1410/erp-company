<?php

declare(strict_types=1);

namespace App\Modules\Industry\Toys\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Industry\Toys\Http\Requests\SafetyCategoryRequest;
use App\Modules\Industry\Toys\Models\SafetyCategory;
use Illuminate\Http\Request;

/**
 * The safety classes the shop sorts its range into.
 *
 * One row is a statement about a kind of toy — what is dangerous about it, which
 * standard it is certified against, and the age below which it must not be sold.
 * It is written once, by whoever knows the range, and then read at every till in
 * the shop for as long as the article is stocked.
 *
 * The switch worth understanding on this screen is
 * `blocks_sale_without_certificate`. Ticked, the class stops being a label and
 * becomes a gate: an article carrying it cannot be sold while its certificate is
 * missing or lapsed. That is the correct setting for a mandatory-certification
 * toy and the wrong one for a shop whose register is still being captured, which
 * is why it is a decision and not a policy.
 */
class SafetyCategoryController extends Controller
{
    public function index(Request $request)
    {
        $query = SafetyCategory::query()->withCount('products');

        if ($search = $request->query('search')) {
            $query->where(fn ($q) => $q->where('code', 'like', "%{$search}%")
                ->orWhere('label', 'like', "%{$search}%"));
        }

        foreach (['standard'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->query($filter));
            }
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $query->orderBy('sort_order')->orderBy('label');

        return response()->json([
            'data' => $query->get()->map(fn (SafetyCategory $c) => $this->row($c))->all(),
            // The vocabulary, sent with the list so the editor does not need a
            // second call to know what a hazard may be.
            'standards' => SafetyCategory::STANDARDS,
            'hazards' => SafetyCategory::HAZARDS,
        ]);
    }

    public function show(SafetyCategory $category)
    {
        return response()->json(['data' => $this->row($category->loadCount('products'))]);
    }

    public function store(SafetyCategoryRequest $request)
    {
        $category = SafetyCategory::create($request->validated());

        return response()->json(['data' => $this->row($category->loadCount('products'))], 201);
    }

    public function update(SafetyCategoryRequest $request, SafetyCategory $category)
    {
        $category->update($request->validated());

        return response()->json(['data' => $this->row($category->loadCount('products'))]);
    }

    public function destroy(SafetyCategory $category)
    {
        // Same reasoning as the age bands: deleting a class in use would strip
        // the warning off every article carrying it, which is the one outcome
        // this register exists to prevent.
        if ($category->products()->exists()) {
            return response()->json([
                'message' => 'Articles are classified under this safety class. Deactivate it instead of deleting it.',
            ], 422);
        }

        $category->delete();

        return response()->json(['message' => 'Safety class removed.']);
    }

    private function row(SafetyCategory $c): array
    {
        return [
            'id' => $c->id,
            'code' => $c->code,
            'label' => $c->label,
            'standard' => $c->standard,
            'standard_label' => SafetyCategory::STANDARDS[$c->standard] ?? $c->standard,
            'hazards' => $c->hazards ?? [],
            'hazard_labels' => $c->hazardLabels(),
            'warning_text' => $c->warning_text,
            'warning' => $c->warning(),
            'min_age_months' => $c->min_age_months,
            'requires_certificate' => (bool) $c->requires_certificate,
            'blocks_sale_without_certificate' => (bool) $c->blocks_sale_without_certificate,
            'sort_order' => (int) $c->sort_order,
            'is_active' => (bool) $c->is_active,
            'products_count' => (int) ($c->products_count ?? 0),
        ];
    }
}
