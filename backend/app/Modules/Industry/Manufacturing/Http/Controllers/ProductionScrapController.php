<?php

declare(strict_types=1);

namespace App\Modules\Industry\Manufacturing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Modules\Industry\Manufacturing\Http\Requests\ProductionScrapRequest;
use App\Modules\Industry\Manufacturing\Models\ProductionScrap;
use App\Modules\Industry\Manufacturing\Services\ShopFloorService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Scrap and rejection notes.
 *
 * Every write goes through ShopFloorService so the stock side stays true: a note
 * that took material out on Monday and is corrected on Tuesday reverses first
 * and re-posts, rather than trying to work out a delta. Reversing and re-posting
 * leaves two honest ledger rows; a delta leaves one row that matches neither
 * version of the note.
 */
class ProductionScrapController extends Controller
{
    public function __construct(private readonly ShopFloorService $shopFloor) {}

    public function index(Request $request)
    {
        $query = ProductionScrap::query()
            ->with([
                'product:id,sku,name',
                'warehouse:id,name',
                'manufacturingOrder:id,mo_number',
                'inspection:id,inspection_number',
            ]);

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('scrap_number', 'like', "%{$search}%")
                    ->orWhereHas('product', fn ($p) => $p
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('sku', 'like', "%{$search}%"));
            });
        }
        if ($reason = $request->query('reason')) {
            $query->where('reason', $reason);
        }
        if ($type = $request->query('scrap_type')) {
            $query->where('scrap_type', $type);
        }
        if ($moId = $request->query('manufacturing_order_id')) {
            $query->where('manufacturing_order_id', $moId);
        }
        if ($from = $request->query('from')) {
            $query->whereDate('scrap_date', '>=', $from);
        }
        if ($to = $request->query('to')) {
            $query->whereDate('scrap_date', '<=', $to);
        }

        $query->orderByDesc('scrap_date')->orderByDesc('id');

        return response()->json(
            $query->paginate(min((int) $request->query('per_page', 25), 100))
                ->through(fn (ProductionScrap $s) => $this->row($s))->toArray()
            + ['summary' => $this->summary($request), 'by_reason' => $this->byReason($request)],
        );
    }

    public function show(ProductionScrap $productionScrap)
    {
        $productionScrap->load([
            'product:id,sku,name', 'variant:id,sku', 'warehouse:id,name',
            'manufacturingOrder:id,mo_number', 'inspection:id,inspection_number',
        ]);

        return response()->json(['data' => $this->row($productionScrap)]);
    }

    public function store(ProductionScrapRequest $request)
    {
        $data = $request->validated();

        $scrap = DB::transaction(function () use ($request, $data) {
            $scrap = ProductionScrap::create($this->attributes($data) + [
                'scrap_number' => ProductionScrap::nextNumber(),
                'created_by' => $request->user()?->id,
            ]);

            $this->shopFloor->postScrap($scrap, $request->user()?->id);

            return $scrap;
        });

        return response()->json(['data' => $this->row($scrap->fresh(['product', 'warehouse']))], 201);
    }

    public function update(ProductionScrapRequest $request, ProductionScrap $productionScrap)
    {
        $data = $request->validated();

        DB::transaction(function () use ($request, $productionScrap, $data) {
            // Put back what the old version took, then post the new version.
            // Two ledger rows, both true, instead of one that matches neither.
            $this->shopFloor->reverseScrap($productionScrap, $request->user()?->id);
            $productionScrap->update($this->attributes($data));
            $this->shopFloor->postScrap($productionScrap->fresh(), $request->user()?->id);
        });

        return response()->json(['data' => $this->row($productionScrap->fresh(['product', 'warehouse']))]);
    }

    public function destroy(Request $request, ProductionScrap $productionScrap)
    {
        DB::transaction(function () use ($request, $productionScrap) {
            $this->shopFloor->reverseScrap($productionScrap, $request->user()?->id);
            $productionScrap->delete();
        });

        return response()->json(['message' => 'Scrap note deleted and stock returned.']);
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        $quantity = (float) $data['quantity'];
        // Falls back to the product's cost so a shop that does not price its
        // scrap still gets a loss figure rather than a row worth nothing.
        $unitCost = isset($data['unit_cost'])
            ? (float) $data['unit_cost']
            : (float) (Product::find($data['product_id'])?->cost_price ?? 0);

        $scrapType = $data['scrap_type'];

        return [
            'scrap_date' => $data['scrap_date'],
            'manufacturing_order_id' => $data['manufacturing_order_id'] ?? null,
            'quality_inspection_id' => $data['quality_inspection_id'] ?? null,
            'product_id' => $data['product_id'],
            'variant_id' => $data['variant_id'] ?? null,
            'warehouse_id' => $data['warehouse_id'],
            'quantity' => $quantity,
            'scrap_type' => $scrapType,
            'reason' => $data['reason'],
            'unit_cost' => $unitCost,
            'total_value' => round($quantity * $unitCost, 2),
            'recoverable_value' => (float) ($data['recoverable_value'] ?? 0),
            // Finished output was never received into stock, so it can never
            // move it — the flag is forced rather than trusted from the client.
            'affects_stock' => $scrapType === 'material'
                ? (bool) ($data['affects_stock'] ?? true)
                : false,
            'remarks' => $data['remarks'] ?? null,
        ];
    }

    private function summary(Request $request): array
    {
        $scope = $this->dateScope($request);

        $totals = (clone $scope)
            ->selectRaw('SUM(quantity) as qty, SUM(total_value) as value, SUM(recoverable_value) as recovery')
            ->first();

        $value = (float) ($totals->value ?? 0);
        $recovery = (float) ($totals->recovery ?? 0);

        return [
            'notes' => (clone $scope)->count(),
            'quantity' => round((float) ($totals->qty ?? 0), 3),
            'value' => round($value, 2),
            'recoverable_value' => round($recovery, 2),
            // What the factory is actually out of pocket. The figure the owner
            // asks for, and the reason recovery is tracked at all.
            'net_loss' => round($value - $recovery, 2),
        ];
    }

    /** Loss by reason — where the scrap is really coming from. */
    private function byReason(Request $request): array
    {
        return $this->dateScope($request)
            ->selectRaw('reason, SUM(quantity) as qty, SUM(total_value - recoverable_value) as net')
            ->groupBy('reason')
            ->orderByDesc('net')
            ->get()
            ->map(fn ($r) => [
                'reason' => $r->reason,
                'reason_label' => ProductionScrap::REASONS[$r->reason] ?? $r->reason,
                'quantity' => round((float) $r->qty, 3),
                'net_loss' => round((float) $r->net, 2),
            ])->values()->all();
    }

    private function dateScope(Request $request)
    {
        $scope = ProductionScrap::query();

        if ($from = $request->query('from')) {
            $scope->whereDate('scrap_date', '>=', $from);
        }
        if ($to = $request->query('to')) {
            $scope->whereDate('scrap_date', '<=', $to);
        }

        return $scope;
    }

    private function row(ProductionScrap $s): array
    {
        return [
            'id' => $s->id,
            'scrap_number' => $s->scrap_number,
            'scrap_date' => $s->scrap_date?->toDateString(),
            'manufacturing_order_id' => $s->manufacturing_order_id,
            'mo_number' => $s->manufacturingOrder?->mo_number,
            'quality_inspection_id' => $s->quality_inspection_id,
            'inspection_number' => $s->inspection?->inspection_number,
            'product_id' => $s->product_id,
            'product' => $s->product?->name,
            'sku' => $s->product?->sku,
            'variant_id' => $s->variant_id,
            'warehouse_id' => $s->warehouse_id,
            'warehouse' => $s->warehouse?->name,
            'quantity' => (float) $s->quantity,
            'scrap_type' => $s->scrap_type,
            'reason' => $s->reason,
            'reason_label' => ProductionScrap::REASONS[$s->reason] ?? $s->reason,
            'unit_cost' => (float) $s->unit_cost,
            'total_value' => (float) $s->total_value,
            'recoverable_value' => (float) $s->recoverable_value,
            'net_loss' => $s->netLoss(),
            'affects_stock' => (bool) $s->affects_stock,
            'is_posted' => (bool) $s->is_posted,
            'posted_at' => $s->posted_at?->toDateTimeString(),
            'remarks' => $s->remarks,
        ];
    }
}
