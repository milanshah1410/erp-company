<?php

declare(strict_types=1);

namespace App\Modules\Industry\Sports\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Industry\Sports\Http\Requests\RentalAssetRequest;
use App\Modules\Industry\Sports\Models\RentalAsset;
use App\Modules\Industry\Sports\Models\RentalItem;
use App\Modules\Industry\Sports\Services\RentalAvailabilityService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The hire fleet.
 *
 * Opened for one of three reasons, and the endpoints follow them. Somebody is
 * adding kit, which is ordinary data entry; somebody is looking for something
 * free on Sunday, which is the counter's question and the reason `available`
 * exists as its own endpoint; or somebody is deciding what to buy and what to
 * sell off, which is `utilisation`.
 *
 * Whether a piece of kit is *out* is derived from the contracts on every read
 * rather than stored on the row — see RentalAsset. A fleet board that is wrong
 * about one bike is worse than no fleet board, and a stored flag goes wrong the
 * first time anything fails halfway through.
 */
class RentalAssetController extends Controller
{
    public function __construct(
        private readonly RentalAvailabilityService $availability,
    ) {}

    public function index(Request $request)
    {
        $query = RentalAsset::query()->with([
            'product:id,sku,name',
            'variant:id,name,attributes',
            'brand:id,name',
            'warehouse:id,name',
        ]);

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('asset_code', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhere('serial_no', 'like', "%{$search}%")
                    ->orWhere('category', 'like', "%{$search}%");
            });
        }

        foreach (['category', 'condition', 'warehouse_id', 'brand_id'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->query($filter));
            }
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $query->orderBy('category')->orderBy('asset_code');

        $page = $query->paginate(min((int) $request->query('per_page', 50), 200));

        /**
         * The open lines for this page in one query rather than one per row. The
         * derived status needs to know who has each asset, and a fifty-row fleet
         * board should not be fifty round trips to find out.
         */
        $openLines = $this->openLinesFor($page->getCollection()->pluck('id')->all());

        $rows = $page->through(fn (RentalAsset $a) => $this->row($a, $openLines->get($a->id)))->toArray();

        // Requested status is applied after derivation, because 'on_hire' is not a
        // column and cannot be filtered in SQL.
        if ($status = $request->query('status')) {
            $rows['data'] = array_values(array_filter(
                $rows['data'],
                static fn (array $row) => $row['status'] === $status,
            ));
        }

        return response()->json($rows + ['summary' => $this->summary()]);
    }

    public function show(RentalAsset $asset)
    {
        $asset->load(['product:id,sku,name', 'variant:id,name,attributes', 'brand:id,name', 'warehouse:id,name']);

        $line = $asset->openLine();

        return response()->json(['data' => $this->row($asset, $line, detailed: true)]);
    }

    public function store(RentalAssetRequest $request)
    {
        $asset = RentalAsset::create($request->validated() + ['created_by' => $request->user()?->id]);

        return response()->json(['data' => $this->row($asset->fresh(['product', 'variant', 'brand', 'warehouse']), null, true)], 201);
    }

    public function update(RentalAssetRequest $request, RentalAsset $asset)
    {
        /**
         * Editing the rate card does not reprice anything already on hire — the
         * contract snapshotted its rate when the line was written (see
         * RentalItem). That is the point of the snapshot, and it is why this is an
         * ordinary update rather than a guarded one.
         */
        $asset->update($request->validated());

        return response()->json(['data' => $this->row($asset->fresh(['product', 'variant', 'brand', 'warehouse']), $asset->openLine(), true)]);
    }

    public function destroy(RentalAsset $asset)
    {
        /**
         * History is the fleet's whole value — what it earned, how often it went
         * out, what condition it came back in — so kit that has ever been hired is
         * retired rather than deleted. Only a row that never left the shop can go
         * without losing anything.
         */
        if ($asset->rentalItems()->exists()) {
            return response()->json([
                'message' => "{$asset->asset_code} has been out on hire. Retire it instead — deleting it would take its earnings and its history with it.",
            ], 422);
        }

        $asset->delete();

        return response()->json(['message' => 'Removed from the fleet.']);
    }

    /**
     * What can actually go out over a window — the counter's question.
     *
     * A window rather than "right now", because the question is nearly always
     * about the future: somebody is standing at the desk on Thursday asking about
     * Sunday, and an answer about Thursday is no answer at all.
     */
    public function available(Request $request)
    {
        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['required', 'date'],
            'rate_basis' => ['nullable', 'in:hourly,daily,weekly,monthly'],
            'warehouse_id' => ['nullable', 'integer'],
            'ignore_rental_id' => ['nullable', 'integer'],
        ]);

        $from = isset($data['from']) ? Carbon::parse($data['from']) : now();
        $to = Carbon::parse($data['to']);
        $basis = $data['rate_basis'] ?? null;

        $assets = $this->availability->availableBetween(
            from: $from,
            to: $to,
            basis: $basis,
            warehouseId: $data['warehouse_id'] ?? null,
            ignoreRentalId: $data['ignore_rental_id'] ?? null,
        );

        return response()->json([
            'data' => $assets->map(fn (RentalAsset $a) => [
                'id' => $a->id,
                'asset_code' => $a->asset_code,
                'name' => $a->name,
                'label' => $a->label(),
                'category' => $a->category,
                'variant' => $a->variantLabel(),
                'brand' => $a->brand?->name,
                'condition' => $a->condition,
                'deposit_amount' => (float) $a->deposit_amount,
                'requires_id_proof' => (bool) $a->requires_id_proof,
                'late_fee_percent' => (int) $a->late_fee_percent,
                'rate' => $basis ? $a->rateFor($basis) : null,
                'rates' => $this->rates($a),
                'offered_bases' => $a->offeredBases(),
            ])->values(),
            'window' => ['from' => $from->toDateTimeString(), 'to' => $to->toDateTimeString()],
        ]);
    }

    /**
     * How hard the fleet is working, and whether it has paid for itself.
     *
     * The report a season's buying is decided from. Defaults to the last ninety
     * days, which is about one season in this trade.
     */
    public function utilisation(Request $request)
    {
        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'warehouse_id' => ['nullable', 'integer'],
        ]);

        $to = isset($data['to']) ? Carbon::parse($data['to']) : now();
        $from = isset($data['from']) ? Carbon::parse($data['from']) : $to->copy()->subDays(90);

        return response()->json([
            'data' => $this->availability->utilisation($from, $to, $data['warehouse_id'] ?? null),
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
        ]);
    }

    /**
     * The open line for each of these assets, keyed by asset id.
     *
     * @param  list<int>  $assetIds
     */
    private function openLinesFor(array $assetIds)
    {
        if ($assetIds === []) {
            return collect();
        }

        return RentalItem::query()
            ->whereIn('asset_id', $assetIds)
            ->whereNull('returned_at')
            ->whereHas('rental', fn ($q) => $q->whereIn('status', RentalAsset::HOLDING_STATUSES))
            ->with('rental:id,rental_number,status,customer_id,due_at,issued_at')
            ->with('rental.customer:id,name')
            ->get()
            ->keyBy('asset_id');
    }

    private function summary(): array
    {
        $active = RentalAsset::query()->where('is_active', true);

        $out = RentalItem::query()
            ->whereNull('returned_at')
            ->whereHas('rental', fn ($q) => $q->where('status', 'out'))
            ->distinct()
            ->count('asset_id');

        $booked = RentalItem::query()
            ->whereNull('returned_at')
            ->whereHas('rental', fn ($q) => $q->where('status', 'booked'))
            ->distinct()
            ->count('asset_id');

        $hireable = (clone $active)->where('status', 'available')->count();

        return [
            'fleet' => (clone $active)->count(),
            'on_hire' => $out,
            'booked' => $booked,
            // What a walk-in could take away this minute. The number the counter
            // actually wants, and the one nothing else on the screen shows.
            'free_now' => max(0, $hireable - $out - $booked),
            'on_bench' => (clone $active)->where('status', 'maintenance')->count(),
            'retired' => RentalAsset::query()->where('status', 'retired')->orWhere('is_active', false)->count(),
        ];
    }

    private function row(RentalAsset $asset, ?RentalItem $openLine, bool $detailed = false): array
    {
        $status = $asset->derivedStatus($openLine);
        $rental = $openLine?->rental;

        $base = [
            'id' => $asset->id,
            'asset_code' => $asset->asset_code,
            'name' => $asset->name,
            'category' => $asset->category,
            'brand' => $asset->brand?->name,
            'variant' => $asset->variantLabel(),
            'warehouse' => $asset->warehouse?->name,
            'condition' => $asset->condition,
            'status' => $status,
            'deposit_amount' => (float) $asset->deposit_amount,
            'rates' => $this->rates($asset),
            'offered_bases' => $asset->offeredBases(),
            'requires_id_proof' => (bool) $asset->requires_id_proof,
            'is_active' => (bool) $asset->is_active,
            // Who has it and when it is promised back — the two things a fleet
            // board is read for, and useless as a boolean.
            'held_by' => $rental?->customer?->name,
            'held_on' => $rental?->rental_number,
            'due_back' => $rental?->due_at?->toDateTimeString(),
        ];

        if (! $detailed) {
            return $base;
        }

        return $base + [
            'product_id' => $asset->product_id,
            'variant_id' => $asset->variant_id,
            'brand_id' => $asset->brand_id,
            'warehouse_id' => $asset->warehouse_id,
            'serial_no' => $asset->serial_no,
            'late_fee_percent' => (int) $asset->late_fee_percent,
            'purchase_cost' => $asset->purchase_cost !== null ? (float) $asset->purchase_cost : null,
            'acquired_on' => $asset->acquired_on?->toDateString(),
            'stored_status' => $asset->status,
            'notes' => $asset->notes,
            'unavailable_reason' => $asset->unavailableReason(),
        ];
    }

    /** @return array<string, float|null> */
    private function rates(RentalAsset $asset): array
    {
        return [
            'hourly' => $asset->rateFor('hourly'),
            'daily' => $asset->rateFor('daily'),
            'weekly' => $asset->rateFor('weekly'),
            'monthly' => $asset->rateFor('monthly'),
        ];
    }
}
