<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MetalRate;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The shop's daily rate card.
 *
 * Quoting a rate is the first thing a jewellery counter does each morning —
 * nothing can be priced until it happens — so this is deliberately a small,
 * fast screen rather than a master buried under settings.
 */
class MetalRateController extends Controller
{
    public function index(Request $request)
    {
        $query = MetalRate::query()->with('creator:id,name');

        foreach (['metal', 'purity'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->query($filter));
            }
        }
        if ($request->filled('on')) {
            $query->where('effective_on', '<=', $request->query('on'));
        }

        $query->orderByDesc('effective_on')->orderBy('metal')->orderByDesc('fineness');
        $perPage = min((int) $request->query('per_page', 50), 200);

        return response()->json([
            'data' => $query->paginate($perPage)->through(fn (MetalRate $r) => $this->row($r)),
            'today' => $this->today(),
        ]);
    }

    /**
     * Quote a rate. Re-quoting the same metal, purity and day updates it rather
     * than stacking a second row — a shop that corrects a typo at 11am has one
     * rate for the day, not two the pricing would race on.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'metal' => ['required', Rule::in(['gold', 'silver', 'platinum'])],
            'purity' => ['required', 'string', 'max:10'],
            'fineness' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'rate_per_gram' => ['required', 'numeric', 'min:0.01'],
            'buy_rate_per_gram' => ['nullable', 'numeric', 'min:0'],
            'effective_on' => ['nullable', 'date'],
        ]);

        $purity = strtoupper($data['purity']);
        // Fineness is derivable for every purity the trade quotes by name, so
        // the counter only types it for something unusual.
        $fineness = $data['fineness'] ?? MetalRate::finenessFor($purity);

        if (! $fineness) {
            return response()->json([
                'message' => "Fineness is required for purity {$purity} — it is not a standard one.",
                'errors' => ['fineness' => ['Enter the parts-per-thousand for this purity.']],
            ], 422);
        }

        $attributes = [
            'fineness' => $fineness,
            'rate_per_gram' => $data['rate_per_gram'],
            'created_by' => $request->user()?->id,
        ];

        // Only touch the buy rate when one was actually sent. Re-quoting the
        // selling rate mid-morning is routine and almost never restates what
        // the shop pays for scrap — blanking it here would quietly start
        // valuing old gold at the full selling rate, losing money on every
        // exchange until someone noticed.
        if (array_key_exists('buy_rate_per_gram', $data)) {
            $attributes['buy_rate_per_gram'] = $data['buy_rate_per_gram'];
        }

        $rate = MetalRate::updateOrCreate(
            [
                'metal' => $data['metal'],
                'purity' => $purity,
                'effective_on' => $data['effective_on'] ?? now()->toDateString(),
            ],
            $attributes,
        );

        return response()->json(['data' => $this->row($rate->fresh())], 201);
    }

    public function destroy(MetalRate $metalRate)
    {
        // Rates are snapshotted onto the sale lines they priced, so removing a
        // stale row cannot disturb an invoice already raised.
        $metalRate->delete();

        return response()->json(['message' => 'Rate removed.']);
    }

    /** Today's effective card — what the POS and the rate screen both read. */
    public function today()
    {
        $rows = MetalRate::query()
            ->where('effective_on', '<=', now()->toDateString())
            ->orderByDesc('effective_on')
            ->orderByDesc('id')
            ->get();

        // The card is "latest row per metal+purity", which is what forDate()
        // resolves per lookup; grouping here gives the same answer in one query.
        return $rows
            ->unique(fn (MetalRate $r) => $r->metal.':'.$r->purity)
            ->sortBy([['metal', 'asc'], ['fineness', 'desc']])
            ->values()
            ->map(fn (MetalRate $r) => $this->row($r));
    }

    private function row(MetalRate $rate): array
    {
        return [
            'id' => $rate->id,
            'metal' => $rate->metal,
            'purity' => $rate->purity,
            'fineness' => $rate->fineness,
            'rate_per_gram' => (float) $rate->rate_per_gram,
            'buy_rate_per_gram' => $rate->buy_rate_per_gram !== null ? (float) $rate->buy_rate_per_gram : null,
            'effective_buy_rate' => $rate->buyRate(),
            'effective_on' => $rate->effective_on?->toDateString(),
            'is_today' => $rate->effective_on?->isToday() ?? false,
            'quoted_by' => $rate->relationLoaded('creator') ? $rate->creator?->name : null,
        ];
    }
}
