<?php

declare(strict_types=1);

namespace App\Modules\Industry\Agriculture\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Industry\Agriculture\Http\Requests\LivestockFlockRequest;
use App\Modules\Industry\Agriculture\Models\FarmProductionLog;
use App\Modules\Industry\Agriculture\Models\LivestockFlock;
use Illuminate\Http\Request;

/**
 * Flock batches, read as a performance board.
 *
 * Every figure a poultry farm lives or dies by is a ratio over birds placed —
 * mortality, feed per bird, eggs per bird, lay percentage — so the list computes
 * them rather than making someone divide two columns in their head. The stored
 * counters behind them are moved only by FarmProductionService, which is why they
 * agree with the log history.
 */
class LivestockFlockController extends Controller
{
    /** Window for the lay-percentage column, in days. */
    private const LAY_WINDOW = 7;

    public function index(Request $request)
    {
        $query = LivestockFlock::query()->with(['warehouse:id,name', 'supplier:id,name']);

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('batch_code', 'like', "%{$search}%")
                    ->orWhere('breed', 'like', "%{$search}%");
            });
        }
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($type = $request->query('bird_type')) {
            $query->where('bird_type', $type);
        }

        $page = $query->orderByDesc('placed_on')->orderByDesc('id')
            ->paginate(min((int) $request->query('per_page', 50), 200));

        $recentEggs = $this->recentEggs($page->getCollection()->pluck('id')->all());

        return response()->json(
            $page->through(fn (LivestockFlock $f) => $this->row($f, $recentEggs[$f->id] ?? 0))->toArray()
            + ['summary' => $this->summary()],
        );
    }

    public function show(LivestockFlock $livestockFlock)
    {
        $livestockFlock->load(['warehouse:id,name', 'supplier:id,name']);

        return response()->json([
            'data' => $this->row($livestockFlock, $this->recentEggs([$livestockFlock->id])[$livestockFlock->id] ?? 0) + [
                'recent_logs' => FarmProductionLog::query()
                    ->where('flock_id', $livestockFlock->id)
                    ->with(['product:id,name', 'feedProduct:id,name'])
                    ->orderByDesc('log_date')->orderByDesc('id')
                    ->limit(30)
                    ->get()
                    ->map(fn (FarmProductionLog $l) => [
                        'id' => $l->id,
                        'log_date' => $l->log_date->toDateString(),
                        'session' => $l->session,
                        'product' => $l->product?->name,
                        'quantity' => (float) $l->quantity,
                        'feed' => $l->feedProduct?->name,
                        'feed_quantity' => (float) $l->feed_quantity,
                        'mortality_count' => (int) $l->mortality_count,
                        'culled_count' => (int) $l->culled_count,
                    ])->values(),
            ],
        ]);
    }

    public function store(LivestockFlockRequest $request)
    {
        $data = $request->validated();

        $flock = LivestockFlock::create($data + [
            // A new placement is all alive: birds start at what was placed, less
            // whatever the form already says has been sold.
            'current_birds' => max(0, (int) $data['opening_birds'] - (int) ($data['sold_birds'] ?? 0)),
            'created_by' => $request->user()?->id,
        ]);

        return response()->json(['data' => $this->row($flock, 0)], 201);
    }

    public function update(LivestockFlockRequest $request, LivestockFlock $livestockFlock)
    {
        $livestockFlock->update($request->validated());

        // Birds alive is derived, so re-settle it whenever the numbers it depends
        // on are edited — otherwise correcting a placement count leaves the shed
        // count stale until the next log posts.
        $livestockFlock->forceFill([
            'current_birds' => max(0, (int) $livestockFlock->opening_birds
                - (int) $livestockFlock->mortality_birds
                - (int) $livestockFlock->culled_birds
                - (int) $livestockFlock->sold_birds),
        ])->save();

        return response()->json(['data' => $this->row($livestockFlock->fresh(), $this->recentEggs([$livestockFlock->id])[$livestockFlock->id] ?? 0)]);
    }

    /**
     * Remove a batch. Closing one out is a status, not a deletion — this is for a
     * placement keyed by mistake, and its logs cascade with it.
     */
    public function destroy(LivestockFlock $livestockFlock)
    {
        $livestockFlock->delete();

        return response()->json(['message' => 'Flock batch deleted.']);
    }

    /**
     * Eggs collected per flock over the lay window, in one query.
     *
     * Counted from egg logs only — the same rule FarmProductionService applies
     * when rolling up, so the window figure and the lifetime figure cannot
     * disagree about what an egg is.
     *
     * @param  list<int>  $flockIds
     * @return array<int, int>
     */
    private function recentEggs(array $flockIds): array
    {
        if ($flockIds === []) {
            return [];
        }

        return FarmProductionLog::query()
            ->whereIn('flock_id', $flockIds)
            ->where('log_date', '>=', now()->subDays(self::LAY_WINDOW)->toDateString())
            ->whereHas('product', fn ($q) => $q->where('name', 'like', '%egg%'))
            ->groupBy('flock_id')
            ->selectRaw('flock_id, sum(quantity) as eggs')
            ->pluck('eggs', 'flock_id')
            ->map(fn ($eggs) => (int) round((float) $eggs))
            ->all();
    }

    private function summary(): array
    {
        $active = LivestockFlock::where('status', 'active')->get();

        return [
            'active_batches' => $active->count(),
            'birds' => (int) $active->sum('current_birds'),
            'placed' => (int) $active->sum('opening_birds'),
            'mortality' => (int) $active->sum('mortality_birds'),
            // Weighted across batches, which is the farm-level number; a plain
            // average of per-batch percentages would let a 200-bird batch swing
            // it as hard as a 20,000-bird one.
            'mortality_percent' => $active->sum('opening_birds') > 0
                ? round($active->sum('mortality_birds') / $active->sum('opening_birds') * 100, 2)
                : 0.0,
            'feed_kg' => round((float) $active->sum('feed_consumed_kg'), 3),
            'eggs' => (int) $active->sum('eggs_collected'),
            'lay_window_days' => self::LAY_WINDOW,
        ];
    }

    private function row(LivestockFlock $f, int $recentEggs): array
    {
        return [
            'id' => $f->id,
            'batch_code' => $f->batch_code,
            'bird_type' => $f->bird_type,
            'breed' => $f->breed,
            'placed_on' => $f->placed_on->toDateString(),
            'age_days' => $f->ageDays(),
            'opening_birds' => (int) $f->opening_birds,
            'current_birds' => (int) $f->current_birds,
            'mortality_birds' => (int) $f->mortality_birds,
            'culled_birds' => (int) $f->culled_birds,
            'sold_birds' => (int) $f->sold_birds,
            'chick_cost' => (float) $f->chick_cost,
            'cost_per_bird' => (int) $f->opening_birds > 0
                ? round((float) $f->chick_cost / (int) $f->opening_birds, 2)
                : null,
            'supplier_id' => $f->supplier_id,
            'supplier' => $f->supplier?->name,
            'warehouse_id' => $f->warehouse_id,
            'warehouse' => $f->warehouse?->name,
            'feed_consumed_kg' => (float) $f->feed_consumed_kg,
            'eggs_collected' => (int) $f->eggs_collected,
            // Derived performance. Nulls are honest: a broiler batch has no lay
            // percentage and no feed-per-dozen, and saying so beats printing a
            // number computed from the wrong denominator.
            'mortality_percent' => $f->mortalityPercent(),
            'feed_per_bird' => $f->feedPerBird(),
            'feed_per_dozen_eggs' => $f->feedPerDozenEggs(),
            'lay_percent' => $f->layPercent($recentEggs, self::LAY_WINDOW),
            'status' => $f->status,
            'closed_on' => $f->closed_on?->toDateString(),
            'notes' => $f->notes,
        ];
    }
}
