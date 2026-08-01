<?php

declare(strict_types=1);

namespace App\Modules\Industry\Agriculture\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Industry\Agriculture\Http\Requests\LivestockAnimalRequest;
use App\Modules\Industry\Agriculture\Models\FarmProductionLog;
use App\Modules\Industry\Agriculture\Models\LivestockAnimal;
use Illuminate\Http\Request;

/**
 * The herd register.
 *
 * The list is a yield sheet, not a card index. A dairy keeps animal records to
 * answer one question — which animals are earning their feed — so each row
 * carries its recent output alongside its particulars, computed in one grouped
 * query rather than per row.
 */
class LivestockAnimalController extends Controller
{
    /** Window for the per-animal yield column, in days. */
    private const YIELD_WINDOW = 30;

    public function index(Request $request)
    {
        $query = LivestockAnimal::query()->with(['warehouse:id,name', 'supplier:id,name']);

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('tag_number', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhere('breed', 'like', "%{$search}%");
            });
        }
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($species = $request->query('species')) {
            $query->where('species', $species);
        }
        if ($request->boolean('on_farm')) {
            $query->whereIn('status', LivestockAnimal::ON_FARM);
        }

        $page = $query->orderBy('tag_number')
            ->paginate(min((int) $request->query('per_page', 50), 200));

        $yields = $this->recentYields($page->getCollection()->pluck('id')->all());

        return response()->json(
            $page->through(fn (LivestockAnimal $a) => $this->row($a, $yields[$a->id] ?? null))->toArray()
            + ['summary' => $this->summary()],
        );
    }

    public function show(LivestockAnimal $livestockAnimal)
    {
        $livestockAnimal->load(['warehouse:id,name', 'supplier:id,name']);

        $yields = $this->recentYields([$livestockAnimal->id]);

        return response()->json([
            'data' => $this->row($livestockAnimal, $yields[$livestockAnimal->id] ?? null) + [
                // The animal's own recent book, so a yield that looks wrong can be
                // traced to the session it came from.
                'recent_logs' => FarmProductionLog::query()
                    ->where('animal_id', $livestockAnimal->id)
                    ->with('product:id,name')
                    ->orderByDesc('log_date')->orderByDesc('id')
                    ->limit(30)
                    ->get()
                    ->map(fn (FarmProductionLog $l) => [
                        'id' => $l->id,
                        'log_date' => $l->log_date->toDateString(),
                        'session' => $l->session,
                        'product' => $l->product?->name,
                        'quantity' => (float) $l->quantity,
                        'fat_percent' => $l->fat_percent === null ? null : (float) $l->fat_percent,
                        'snf_percent' => $l->snf_percent === null ? null : (float) $l->snf_percent,
                    ])->values(),
            ],
        ]);
    }

    public function store(LivestockAnimalRequest $request)
    {
        $animal = LivestockAnimal::create($request->validated() + ['created_by' => $request->user()?->id]);

        return response()->json(['data' => $this->row($animal, null)], 201);
    }

    public function update(LivestockAnimalRequest $request, LivestockAnimal $livestockAnimal)
    {
        $livestockAnimal->update($request->validated());

        return response()->json(['data' => $this->row($livestockAnimal->fresh(), null)]);
    }

    /**
     * Remove an animal from the register.
     *
     * Deliberately not the way an animal leaves the herd: a sale or a death is a
     * status with a date, and past yields must stay attributable. Deleting is for
     * a row keyed by mistake — and the production log's cascade means a mistaken
     * animal takes its mistaken entries with it. Any that were posted have
     * already moved stock, which stays as ledger history because it is what
     * physically happened.
     */
    public function destroy(LivestockAnimal $livestockAnimal)
    {
        $livestockAnimal->delete();

        return response()->json(['message' => 'Animal removed from the register.']);
    }

    /**
     * Output per animal over the recent window, in one query.
     *
     * @param  list<int>  $animalIds
     * @return array<int, array{quantity: float, sessions: int, avg_fat: float|null, last_log: string|null}>
     */
    private function recentYields(array $animalIds): array
    {
        if ($animalIds === []) {
            return [];
        }

        return FarmProductionLog::query()
            ->whereIn('animal_id', $animalIds)
            ->where('log_date', '>=', now()->subDays(self::YIELD_WINDOW)->toDateString())
            ->groupBy('animal_id')
            ->selectRaw('animal_id, sum(quantity) as quantity, count(*) as sessions, avg(fat_percent) as avg_fat, max(log_date) as last_log')
            ->get()
            ->keyBy('animal_id')
            ->map(fn ($r) => [
                'quantity' => round((float) $r->quantity, 3),
                'sessions' => (int) $r->sessions,
                'avg_fat' => $r->avg_fat === null ? null : round((float) $r->avg_fat, 2),
                'last_log' => $r->last_log ? (string) $r->last_log : null,
            ])
            ->all();
    }

    /** Herd composition and what it is currently producing. */
    private function summary(): array
    {
        $byStatus = LivestockAnimal::query()
            ->groupBy('status')
            ->selectRaw('status, count(*) as total')
            ->pluck('total', 'status');

        $window = FarmProductionLog::query()
            ->whereNotNull('animal_id')
            ->where('log_date', '>=', now()->subDays(self::YIELD_WINDOW)->toDateString())
            ->selectRaw('sum(quantity) as quantity, count(distinct log_date) as days, avg(fat_percent) as avg_fat')
            ->first();

        $days = max(1, (int) ($window->days ?? 0));

        return [
            'total' => (int) $byStatus->sum(),
            'milking' => (int) ($byStatus['active'] ?? 0),
            'dry' => (int) ($byStatus['dry'] ?? 0),
            'exited' => (int) (($byStatus['sold'] ?? 0) + ($byStatus['dead'] ?? 0) + ($byStatus['culled'] ?? 0)),
            'window_days' => self::YIELD_WINDOW,
            'window_quantity' => round((float) ($window->quantity ?? 0), 3),
            // Average per *recorded* day rather than per calendar day, so a farm
            // that started keeping records last week is not shown as producing a
            // seventh of what it does.
            'daily_average' => round((float) ($window->quantity ?? 0) / $days, 3),
            'avg_fat' => $window?->avg_fat === null ? null : round((float) $window->avg_fat, 2),
        ];
    }

    /** @param  array<string, mixed>|null  $yield */
    private function row(LivestockAnimal $a, ?array $yield): array
    {
        $sessions = (int) ($yield['sessions'] ?? 0);
        $quantity = (float) ($yield['quantity'] ?? 0);

        return [
            'id' => $a->id,
            'tag_number' => $a->tag_number,
            'name' => $a->name,
            'species' => $a->species,
            'breed' => $a->breed,
            'gender' => $a->gender,
            'date_of_birth' => $a->date_of_birth?->toDateString(),
            'age_months' => $a->ageMonths(),
            'acquired_on' => $a->acquired_on?->toDateString(),
            'acquisition_type' => $a->acquisition_type,
            'acquisition_cost' => (float) $a->acquisition_cost,
            'supplier_id' => $a->supplier_id,
            'supplier' => $a->supplier?->name,
            'warehouse_id' => $a->warehouse_id,
            'warehouse' => $a->warehouse?->name,
            'dam_tag' => $a->dam_tag,
            'sire_tag' => $a->sire_tag,
            'lactation_number' => $a->lactation_number,
            'status' => $a->status,
            'exited_on' => $a->exited_on?->toDateString(),
            'notes' => $a->notes,
            // Recent performance. Null-vs-zero matters here: an animal nobody has
            // logged is not an animal that produced nothing.
            'window_days' => self::YIELD_WINDOW,
            'window_quantity' => $sessions > 0 ? $quantity : null,
            'per_session' => $sessions > 0 ? round($quantity / $sessions, 3) : null,
            'avg_fat' => $yield['avg_fat'] ?? null,
            'last_log' => $yield['last_log'] ?? null,
        ];
    }
}
