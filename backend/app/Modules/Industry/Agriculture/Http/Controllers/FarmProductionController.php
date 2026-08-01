<?php

declare(strict_types=1);

namespace App\Modules\Industry\Agriculture\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Modules\Industry\Agriculture\Http\Requests\FarmProductionLogRequest;
use App\Modules\Industry\Agriculture\Models\FarmProductionLog;
use App\Modules\Industry\Agriculture\Services\FarmProductionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The daily book: what the farm produced, what it ate, what it lost.
 *
 * Entries post their stock movements as they are saved rather than sitting as
 * drafts. A milking is not a proposal — the milk is in the can and the feed is
 * gone — and a farm that had to remember a second "post" step would end each
 * month with half its output invisible to inventory.
 *
 * That makes correction the interesting path, and it is handled the same way
 * everywhere here: unpost, change, post again, inside one transaction. Editing a
 * posted row without reversing it would leave the ledger describing a day that
 * never happened.
 */
class FarmProductionController extends Controller
{
    public function __construct(private readonly FarmProductionService $production) {}

    public function index(Request $request)
    {
        $query = FarmProductionLog::query()->with([
            'animal:id,tag_number,name',
            'flock:id,batch_code,bird_type',
            'product:id,name',
            'feedProduct:id,name',
            'warehouse:id,name',
        ]);

        if ($from = $request->query('from')) {
            $query->where('log_date', '>=', $from);
        }
        if ($to = $request->query('to')) {
            $query->where('log_date', '<=', $to);
        }
        if ($animalId = $request->query('animal_id')) {
            $query->where('animal_id', $animalId);
        }
        if ($flockId = $request->query('flock_id')) {
            $query->where('flock_id', $flockId);
        }
        if ($productId = $request->query('product_id')) {
            $query->where('product_id', $productId);
        }
        if ($source = $request->query('source')) {
            // 'animal' | 'flock' — the two halves of the book read separately,
            // because a dairy and a poultry shed are usually different people.
            $source === 'animal'
                ? $query->whereNotNull('animal_id')
                : $query->whereNotNull('flock_id');
        }

        $page = $query->orderByDesc('log_date')->orderByDesc('id')
            ->paginate(min((int) $request->query('per_page', 50), 200));

        return response()->json(
            $page->through(fn (FarmProductionLog $l) => $this->row($l))->toArray()
            + ['summary' => $this->summary($request)],
        );
    }

    public function show(FarmProductionLog $farmProductionLog)
    {
        $farmProductionLog->load(['animal', 'flock', 'product:id,name', 'feedProduct:id,name', 'warehouse:id,name']);

        return response()->json(['data' => $this->row($farmProductionLog)]);
    }

    public function store(FarmProductionLogRequest $request)
    {
        $data = $this->prepare($request->validated());

        $log = DB::transaction(function () use ($data, $request) {
            $log = FarmProductionLog::create($data + ['created_by' => $request->user()?->id]);

            // Inside the transaction on purpose: a log that saved but failed to
            // move stock would be a silent hole in both inventory and feed cost.
            $this->production->post($log, $request->user()?->id);

            return $log;
        });

        return response()->json(['data' => $this->row($log->fresh(['animal', 'flock', 'product', 'feedProduct']))], 201);
    }

    public function update(FarmProductionLogRequest $request, FarmProductionLog $farmProductionLog)
    {
        $data = $this->prepare($request->validated());

        DB::transaction(function () use ($farmProductionLog, $data, $request) {
            $userId = $request->user()?->id;

            // Reverse against the *old* values, then re-post against the new
            // ones. Doing it in this order is what makes an edited quantity or a
            // changed feed product end up correct rather than double-counted.
            $this->production->unpost($farmProductionLog, $userId);
            $farmProductionLog->update($data);
            $this->production->post($farmProductionLog->refresh(), $userId);
        });

        return response()->json([
            'data' => $this->row($farmProductionLog->fresh(['animal', 'flock', 'product', 'feedProduct'])),
        ]);
    }

    public function destroy(Request $request, FarmProductionLog $farmProductionLog)
    {
        DB::transaction(function () use ($farmProductionLog, $request) {
            // Wind the movements and the flock counters back before the row that
            // explains them disappears.
            $this->production->unpost($farmProductionLog, $request->user()?->id);
            $farmProductionLog->delete();
        });

        return response()->json(['message' => 'Production entry deleted and its stock movements reversed.']);
    }

    /**
     * Fill in what the shed should not have to type.
     *
     * The cost produce enters stock at defaults to the product's cost price:
     * farm costing is a season-long argument, and asking a milker for a rupee
     * figure at 5am guarantees a worse number than the master already holds.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function prepare(array $data): array
    {
        if (! isset($data['unit_cost']) || $data['unit_cost'] === null) {
            $data['unit_cost'] = (float) (Product::find($data['product_id'])?->cost_price ?? 0);
        }

        $data['feed_quantity'] = $data['feed_quantity'] ?? 0;
        $data['mortality_count'] = $data['mortality_count'] ?? 0;
        $data['culled_count'] = $data['culled_count'] ?? 0;

        return $data;
    }

    /**
     * Totals over the filtered range, per product.
     *
     * Per product rather than one grand total: adding litres of milk to dozens of
     * eggs produces a number that means nothing, and a farm that keeps both needs
     * to see them apart.
     */
    private function summary(Request $request): array
    {
        $from = $request->query('from') ?: now()->subDays(30)->toDateString();
        $to = $request->query('to') ?: now()->toDateString();

        $base = fn () => FarmProductionLog::query()
            ->where('log_date', '>=', $from)
            ->where('log_date', '<=', $to);

        $byProduct = $base()
            ->join('products', 'products.id', '=', 'farm_production_logs.product_id')
            ->groupBy('farm_production_logs.product_id', 'products.name')
            ->selectRaw('products.name as product, sum(farm_production_logs.quantity) as quantity, count(*) as entries')
            ->orderByDesc('quantity')
            ->get()
            ->map(fn ($r) => [
                'product' => $r->product,
                'quantity' => round((float) $r->quantity, 3),
                'entries' => (int) $r->entries,
            ])->values();

        $totals = $base()->selectRaw(
            'sum(feed_quantity) as feed, sum(mortality_count) as mortality, sum(culled_count) as culled, count(distinct log_date) as days'
        )->first();

        return [
            'from' => $from,
            'to' => $to,
            'days_recorded' => (int) ($totals->days ?? 0),
            'by_product' => $byProduct,
            'feed_quantity' => round((float) ($totals->feed ?? 0), 3),
            'mortality' => (int) ($totals->mortality ?? 0),
            'culled' => (int) ($totals->culled ?? 0),
        ];
    }

    private function row(FarmProductionLog $l): array
    {
        return [
            'id' => $l->id,
            'log_date' => $l->log_date->toDateString(),
            'session' => $l->session,
            'animal_id' => $l->animal_id,
            'flock_id' => $l->flock_id,
            'source' => $l->sourceLabel(),
            'source_type' => $l->animal_id ? 'animal' : 'flock',
            'animal_name' => $l->animal?->name,
            'bird_type' => $l->flock?->bird_type,
            'product_id' => $l->product_id,
            'product' => $l->product?->name,
            'warehouse_id' => $l->warehouse_id,
            'warehouse' => $l->warehouse?->name,
            'quantity' => (float) $l->quantity,
            'unit_cost' => (float) $l->unit_cost,
            'fat_percent' => $l->fat_percent === null ? null : (float) $l->fat_percent,
            'snf_percent' => $l->snf_percent === null ? null : (float) $l->snf_percent,
            'kg_fat' => $l->kgFat(),
            'feed_product_id' => $l->feed_product_id,
            'feed_product' => $l->feedProduct?->name,
            'feed_quantity' => (float) $l->feed_quantity,
            'mortality_count' => (int) $l->mortality_count,
            'culled_count' => (int) $l->culled_count,
            // Stated on the row because it is the difference between an entry
            // that moved stock and one that did not.
            'posted_at' => $l->posted_at,
            'remarks' => $l->remarks,
        ];
    }
}
