<?php

declare(strict_types=1);

namespace App\Modules\Industry\Healthcare\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Industry\Healthcare\Http\Requests\LabTestRequest;
use App\Modules\Industry\Healthcare\Models\LabTest;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The test master: what the lab offers, what it costs, and what normal is.
 *
 * Shaped to be drivable by the generic CrudView (flat rows, `is_active`,
 * `search`) while still carrying the panel's component list, which no generic
 * screen can express — the Lab Orders screen reads it to expand a panel.
 */
class LabTestController extends Controller
{
    public function index(Request $request)
    {
        $query = LabTest::query()->with('components:id,code,name,unit');

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('category', 'like', "%{$search}%");
            });
        }
        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }
        if ($request->filled('is_panel')) {
            $query->where('is_panel', $request->boolean('is_panel'));
        }
        if ($category = $request->query('category')) {
            $query->where('category', $category);
        }

        $query->orderBy('category')->orderBy('name');

        return response()->json(
            $query->paginate(min((int) $request->query('per_page', 25), 200))
                ->through(fn (LabTest $t) => $this->row($t))->toArray()
            + ['categories' => $this->categories()],
        );
    }

    public function show(LabTest $labTest)
    {
        return response()->json(['data' => $this->row($labTest->load('components:id,code,name,unit'))]);
    }

    public function store(LabTestRequest $request)
    {
        $test = DB::transaction(function () use ($request) {
            $test = LabTest::create($this->attributes($request));
            $this->syncComponents($test, $request);

            return $test;
        });

        return response()->json(['data' => $this->row($test->load('components'))], 201);
    }

    public function update(LabTestRequest $request, LabTest $labTest)
    {
        DB::transaction(function () use ($request, $labTest) {
            $labTest->update($this->attributes($request));
            $this->syncComponents($labTest, $request);
        });

        return response()->json(['data' => $this->row($labTest->fresh('components'))]);
    }

    /**
     * Deleting a test that has been ordered is refused by the database
     * (restrictOnDelete on lab_order_items): a report already handed to a
     * patient names it, and nothing should be able to make that dangle.
     * Deactivating is the way to retire a test.
     */
    public function destroy(LabTest $labTest)
    {
        if ($labTest->panels()->exists()) {
            return response()->json([
                'message' => 'This test is part of a panel. Remove it from the panel first.',
            ], 422);
        }

        try {
            $labTest->delete();
        } catch (QueryException) {
            return response()->json([
                'message' => 'This test has been ordered before and cannot be deleted. Mark it inactive instead.',
            ], 422);
        }

        return response()->json(['message' => 'Test deleted.']);
    }

    /** @return array<string, mixed> */
    private function attributes(LabTestRequest $request): array
    {
        $data = $request->validated();
        unset($data['components']);

        return $data;
    }

    private function syncComponents(LabTest $test, LabTestRequest $request): void
    {
        if (! $test->is_panel) {
            // Demoting a panel to a plain test drops its membership rather than
            // leaving orphan rows that would silently return if it were promoted
            // again months later.
            $test->components()->detach();

            return;
        }

        if (! $request->has('components')) {
            return;
        }

        $ids = array_values(array_unique(array_map('intval', $request->input('components', []))));

        $test->components()->sync(
            collect($ids)->mapWithKeys(fn (int $id, int $i) => [$id => ['sort_order' => $i]])->all(),
        );
    }

    /** Distinct categories, for the master's filter dropdown. @return list<string> */
    private function categories(): array
    {
        return LabTest::query()
            ->whereNotNull('category')
            ->distinct()
            ->orderBy('category')
            ->pluck('category')
            ->all();
    }

    /** @return array<string, mixed> */
    private function row(LabTest $t): array
    {
        return [
            'id' => $t->id,
            'code' => $t->code,
            'name' => $t->name,
            'category' => $t->category,
            'sample_type' => $t->sample_type,
            'container' => $t->container,
            'method' => $t->method,
            'unit' => $t->unit,
            'reference_range' => $t->reference_range,
            'ref_low' => $t->ref_low !== null ? (float) $t->ref_low : null,
            'ref_high' => $t->ref_high !== null ? (float) $t->ref_high : null,
            'price' => (float) $t->price,
            'tax_percent' => (float) $t->tax_percent,
            'tat_hours' => $t->tat_hours,
            'is_panel' => (bool) $t->is_panel,
            'is_active' => (bool) $t->is_active,
            'notes' => $t->notes,
            'components' => $t->relationLoaded('components')
                ? $t->components->map(fn (LabTest $c) => [
                    'id' => $c->id,
                    'code' => $c->code,
                    'name' => $c->name,
                    'unit' => $c->unit,
                ])->values()
                : [],
            // Flat count so a list can show "Lipid Profile · 5 parameters"
            // without the client walking the nested array.
            'components_count' => $t->relationLoaded('components') ? $t->components->count() : 0,
        ];
    }
}
