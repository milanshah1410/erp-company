<?php

declare(strict_types=1);

namespace App\Modules\Industry\FoodBeverage\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use App\Modules\Industry\FoodBeverage\Http\Requests\MilkCollectionRequest;
use App\Modules\Industry\FoodBeverage\Models\MilkCollection;
use App\Modules\Industry\FoodBeverage\Models\MilkRateChart;
use App\Modules\Industry\FoodBeverage\Services\MilkCollectionService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * The collection counter, and the rate chart on the wall behind it.
 *
 * Both are here because they are used together: the counter keys a can, the chart
 * prices it, and the person doing the keying is the person who needs to see what
 * rate is in force. A separate rate-chart screen would be a second nav entry for
 * one number.
 *
 * `preview` exists so the entry screen can show the rate and the amount while the
 * counter is still typing, using the same arithmetic that will be stored — a
 * preview computed differently from the slip is worse than no preview.
 */
class MilkCollectionController extends Controller
{
    public function __construct(private readonly MilkCollectionService $milk) {}

    /*
    |--------------------------------------------------------------------------
    | Collections
    |--------------------------------------------------------------------------
    */

    public function index(Request $request)
    {
        $query = MilkCollection::query()
            ->with(['supplier:id,code,name,phone', 'route:id,name', 'rateChart:id,name,basis']);

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('collection_number', 'like', "%{$search}%")
                    ->orWhereHas('supplier', fn ($s) => $s->where('name', 'like', "%{$search}%")
                        ->orWhere('code', 'like', "%{$search}%"));
            });
        }

        foreach (['supplier_id', 'shift', 'milk_type', 'delivery_route_id'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->query($filter));
            }
        }

        // Defaults to today: a collection centre opens this screen mid-round.
        $from = $request->query('from', now()->toDateString());
        $to = $request->query('to', $from);
        $query->whereBetween('collection_date', [$from, $to]);

        if ($request->boolean('unsettled')) {
            $query->unsettled();
        }

        $query->orderByDesc('collection_date')->orderBy('shift')->orderByDesc('id');

        return response()->json(
            $query->paginate(min((int) $request->query('per_page', 50), 200))
                ->through(fn (MilkCollection $c) => $this->row($c))->toArray()
            + ['summary' => $this->summary($from, $to)],
        );
    }

    public function store(MilkCollectionRequest $request)
    {
        try {
            $collection = $this->milk->record($request->validated(), $request->user()?->id);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => $this->row($collection->fresh(['supplier', 'route', 'rateChart'])),
        ], 201);
    }

    public function destroy(MilkCollection $milkCollection)
    {
        /**
         * A settled can cannot be deleted: the farmer has been billed for it, and
         * removing it would leave a purchase invoice whose lines no longer add up.
         *
         * The stock the collection brought in is deliberately NOT reversed here.
         * That is a real gap and it is the honest one: unwinding an inward
         * movement needs the same care a stock adjustment gets, so a deletion is
         * refused once the milk has been billed and otherwise leaves an
         * adjustment for the dairy to make deliberately.
         */
        if ($milkCollection->purchase_invoice_id) {
            return response()->json([
                'message' => 'This collection has been settled and cannot be deleted.',
            ], 422);
        }

        $milkCollection->delete();

        return response()->json([
            'message' => 'Collection deleted. Adjust the milk stock it brought in if it was keyed in error.',
        ]);
    }

    /** Rate and amount for a reading, before anything is saved. */
    public function preview(Request $request)
    {
        $data = $request->validate([
            'milk_type' => ['required', Rule::in(['cow', 'buffalo', 'mixed'])],
            'quantity_litres' => ['required', 'numeric', 'min:0'],
            'fat_percent' => ['nullable', 'numeric', 'min:0', 'max:15'],
            'snf_percent' => ['nullable', 'numeric', 'min:0', 'max:15'],
            'collection_date' => ['nullable', 'date'],
            'milk_rate_chart_id' => ['nullable', 'exists:milk_rate_charts,id'],
        ]);

        $chart = isset($data['milk_rate_chart_id'])
            ? MilkRateChart::find($data['milk_rate_chart_id'])
            : MilkRateChart::inForce($data['milk_type'], $data['collection_date'] ?? null);

        if (! $chart) {
            return response()->json([
                'data' => [
                    'chart' => null,
                    'rate_per_litre' => 0,
                    'amount' => 0,
                    'in_band' => true,
                    'message' => 'No rate chart is in force for this milk type — key the rate by hand.',
                ],
            ]);
        }

        $fat = (float) ($data['fat_percent'] ?? 0);
        $rate = $chart->rateFor($fat, (float) ($data['snf_percent'] ?? 0));

        return response()->json([
            'data' => [
                'chart' => ['id' => $chart->id, 'name' => $chart->name, 'basis' => $chart->basis],
                'rate_per_litre' => $rate,
                'amount' => round((float) $data['quantity_litres'] * $rate, 2),
                // Reported, never enforced: rejecting a farmer's can is a decision
                // taken at the counter by a person.
                'in_band' => $chart->acceptsFat($fat),
            ],
        ]);
    }

    /** Farmer-wise litres, average quality and payable for a period. */
    public function farmerSummary(Request $request)
    {
        $data = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
        ]);

        return response()->json(['data' => $this->milk->farmerSummary($data['from'], $data['to'])]);
    }

    /** Settle a farmer's period into one purchase invoice. */
    public function settle(Request $request)
    {
        $data = $request->validate([
            'supplier_id' => ['required', 'exists:suppliers,id'],
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
        ]);

        $invoice = $this->milk->settle(
            Supplier::findOrFail($data['supplier_id']),
            $data['from'],
            $data['to'],
            $request->user()?->id,
        );

        if (! $invoice) {
            return response()->json([
                'message' => 'Nothing unsettled for this farmer in that period.',
            ], 422);
        }

        return response()->json([
            'message' => "Settled as purchase invoice {$invoice->invoice_number}.",
            'data' => [
                'purchase_invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'total' => (float) $invoice->total,
            ],
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Rate charts
    |--------------------------------------------------------------------------
    */

    public function charts(Request $request)
    {
        $charts = MilkRateChart::query()
            ->when($request->filled('is_active'), fn ($q) => $q->where('is_active', $request->boolean('is_active')))
            ->orderByDesc('effective_from')
            ->orderBy('milk_type')
            ->get();

        return response()->json([
            'data' => $charts->map(fn (MilkRateChart $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'milk_type' => $c->milk_type,
                'basis' => $c->basis,
                'flat_rate' => (float) $c->flat_rate,
                'rate_per_fat_point' => (float) $c->rate_per_fat_point,
                'rate_per_snf_point' => (float) $c->rate_per_snf_point,
                'min_fat' => (float) $c->min_fat,
                'max_fat' => (float) $c->max_fat,
                'adjustment_per_litre' => (float) $c->adjustment_per_litre,
                'effective_from' => $c->effective_from?->toDateString(),
                'is_active' => (bool) $c->is_active,
                // What a typical can would fetch on this chart, so an owner can
                // sanity-check a chart before it prices a morning.
                'example_rate_at_6_fat' => $c->rateFor(6.0, 8.5),
            ])->values(),
        ]);
    }

    public function storeChart(Request $request)
    {
        return response()->json(['data' => MilkRateChart::create(
            $this->validateChart($request) + ['created_by' => $request->user()?->id],
        )], 201);
    }

    public function updateChart(Request $request, MilkRateChart $milkRateChart)
    {
        $milkRateChart->update($this->validateChart($request));

        return response()->json(['data' => $milkRateChart->fresh()]);
    }

    public function destroyChart(MilkRateChart $milkRateChart)
    {
        /**
         * A chart that has priced milk is deactivated, not deleted. The rate is
         * stored on each collection so history would survive either way, but the
         * chart is what a farmer is shown when they query a slip.
         */
        if ($milkRateChart->collections()->exists()) {
            $milkRateChart->update(['is_active' => false]);

            return response()->json([
                'message' => 'This chart has priced collections, so it has been deactivated instead of deleted.',
            ]);
        }

        $milkRateChart->delete();

        return response()->json(['message' => 'Rate chart deleted.']);
    }

    /** @return array<string, mixed> */
    private function validateChart(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'milk_type' => ['required', Rule::in(['cow', 'buffalo', 'mixed'])],
            'basis' => ['required', Rule::in(['flat', 'fat', 'fat_snf'])],
            'flat_rate' => ['nullable', 'numeric', 'min:0'],
            'rate_per_fat_point' => ['nullable', 'numeric', 'min:0'],
            'rate_per_snf_point' => ['nullable', 'numeric', 'min:0'],
            'min_fat' => ['nullable', 'numeric', 'min:0', 'max:15'],
            'max_fat' => ['nullable', 'numeric', 'min:0', 'max:15'],
            // Signed: a deduction is as normal as an incentive.
            'adjustment_per_litre' => ['nullable', 'numeric'],
            'effective_from' => ['required', 'date'],
            'is_active' => ['nullable', 'boolean'],
        ]);
    }

    private function summary(string $from, string $to): array
    {
        $rows = MilkCollection::query()->whereBetween('collection_date', [$from, $to])->get();
        $litres = round((float) $rows->sum('quantity_litres'), 3);

        return [
            'collections' => $rows->count(),
            'farmers' => $rows->pluck('supplier_id')->unique()->count(),
            'litres' => $litres,
            'amount' => round((float) $rows->sum('amount'), 2),
            'unsettled_amount' => round((float) $rows->whereNull('purchase_invoice_id')->sum('amount'), 2),
            // Litre-weighted, for the reason spelled out in the service.
            'avg_fat' => $litres > 0 ? round($rows->sum(
                fn (MilkCollection $c) => (float) $c->quantity_litres * (float) $c->fat_percent,
            ) / $litres, 2) : 0.0,
            'morning_litres' => round((float) $rows->where('shift', 'morning')->sum('quantity_litres'), 3),
            'evening_litres' => round((float) $rows->where('shift', 'evening')->sum('quantity_litres'), 3),
        ];
    }

    private function row(MilkCollection $c): array
    {
        return [
            'id' => $c->id,
            'collection_number' => $c->collection_number,
            'supplier_id' => $c->supplier_id,
            'farmer' => $c->supplier?->name,
            'farmer_code' => $c->supplier?->code,
            'collection_date' => $c->collection_date?->toDateString(),
            'shift' => $c->shift,
            'milk_type' => $c->milk_type,
            'quantity_litres' => (float) $c->quantity_litres,
            'fat_percent' => (float) $c->fat_percent,
            'snf_percent' => (float) $c->snf_percent,
            'clr' => (float) $c->clr,
            'fat_kg' => $c->fatKg(),
            'rate_per_litre' => (float) $c->rate_per_litre,
            'amount' => (float) $c->amount,
            'chart' => $c->rateChart?->name,
            'route' => $c->route?->name,
            'is_settled' => $c->purchase_invoice_id !== null,
            'purchase_invoice_id' => $c->purchase_invoice_id,
            'remarks' => $c->remarks,
        ];
    }
}
