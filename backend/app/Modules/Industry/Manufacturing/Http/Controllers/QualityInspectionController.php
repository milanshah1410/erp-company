<?php

declare(strict_types=1);

namespace App\Modules\Industry\Manufacturing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\ManufacturingOrder;
use App\Modules\Industry\Manufacturing\Http\Requests\QualityInspectionRequest;
use App\Modules\Industry\Manufacturing\Models\ProductionScrap;
use App\Modules\Industry\Manufacturing\Models\QualityInspection;
use App\Modules\Industry\Manufacturing\Models\QualityInspectionDefect;
use App\Modules\Industry\Manufacturing\Services\ShopFloorService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Quality inspections.
 *
 * The list is a rejection report first and a document register second: what a
 * factory reads this screen for is the rejection rate and which defects are
 * driving it, so both lead rather than sitting behind a drill-down.
 *
 * Every write re-syncs the linked run's rejected quantity (ShopFloorService)
 * rather than incrementing it, so an edited or deleted inspection can never
 * leave a run claiming rejections that nothing supports.
 */
class QualityInspectionController extends Controller
{
    public function __construct(private readonly ShopFloorService $shopFloor) {}

    public function index(Request $request)
    {
        $query = QualityInspection::query()
            ->with([
                'product:id,sku,name',
                'manufacturingOrder:id,mo_number',
                'goodsReceipt:id,grn_number',
                'inspector:id,first_name,last_name',
            ])
            ->withCount('defects');

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('inspection_number', 'like', "%{$search}%")
                    ->orWhereHas('product', fn ($p) => $p
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('sku', 'like', "%{$search}%"));
            });
        }
        if ($stage = $request->query('stage')) {
            $query->where('stage', $stage);
        }
        if ($result = $request->query('result')) {
            $query->where('result', $result);
        }
        if ($moId = $request->query('manufacturing_order_id')) {
            $query->where('manufacturing_order_id', $moId);
        }
        if ($from = $request->query('from')) {
            $query->whereDate('inspected_on', '>=', $from);
        }
        if ($to = $request->query('to')) {
            $query->whereDate('inspected_on', '<=', $to);
        }

        $query->orderByDesc('inspected_on')->orderByDesc('id');

        return response()->json(
            $query->paginate(min((int) $request->query('per_page', 25), 100))
                ->through(fn (QualityInspection $i) => $this->row($i))->toArray()
            + ['summary' => $this->summary($request)],
        );
    }

    public function show(QualityInspection $qualityInspection)
    {
        $qualityInspection->load([
            'product:id,sku,name', 'variant:id,sku', 'batch:id,batch_number',
            'manufacturingOrder:id,mo_number', 'goodsReceipt:id,grn_number',
            'inspector:id,first_name,last_name', 'defects', 'scraps:id,quality_inspection_id,scrap_number,quantity',
        ]);

        return response()->json(['data' => $this->row($qualityInspection, withDefects: true)]);
    }

    public function store(QualityInspectionRequest $request)
    {
        $data = $request->validated();

        $inspection = DB::transaction(function () use ($request, $data) {
            $inspection = QualityInspection::create($this->attributes($data) + [
                'inspection_number' => QualityInspection::nextNumber(),
                'created_by' => $request->user()?->id,
            ]);

            $this->syncDefects($inspection, $data['defects'] ?? []);
            $this->shopFloor->syncOrderRejections($inspection->manufacturingOrder);

            return $inspection;
        });

        return response()->json(['data' => $this->row($inspection->fresh(['product', 'defects']), withDefects: true)], 201);
    }

    public function update(QualityInspectionRequest $request, QualityInspection $qualityInspection)
    {
        $data = $request->validated();
        // Held before the update: moving an inspection from one run to another
        // has to correct both, and the old link is gone once it is overwritten.
        $previousOrder = $qualityInspection->manufacturingOrder;

        DB::transaction(function () use ($qualityInspection, $data, $previousOrder) {
            $qualityInspection->update($this->attributes($data));

            // Rewritten wholesale rather than diffed: defect lines are a short
            // list an inspector retypes when the count changes, and matching by
            // name would mangle a lot that legitimately lists one defect at two
            // severities.
            $qualityInspection->defects()->delete();
            $this->syncDefects($qualityInspection, $data['defects'] ?? []);

            $this->shopFloor->syncOrderRejections($previousOrder);
            $this->shopFloor->syncOrderRejections($qualityInspection->fresh()->manufacturingOrder);
        });

        return response()->json([
            'data' => $this->row($qualityInspection->fresh(['product', 'defects']), withDefects: true),
        ]);
    }

    public function destroy(QualityInspection $qualityInspection)
    {
        /**
         * Refused once scrap has been raised from it. The scrap note is the
         * document that took stock out and carries the loss; deleting the
         * inspection under it would leave a write-off whose justification no
         * longer exists.
         */
        if ($qualityInspection->scraps()->exists()) {
            return response()->json([
                'message' => 'Scrap has been raised from this inspection. Delete the scrap note first.',
            ], 422);
        }

        $order = $qualityInspection->manufacturingOrder;

        DB::transaction(function () use ($qualityInspection, $order) {
            $qualityInspection->delete();
            $this->shopFloor->syncOrderRejections($order);
        });

        return response()->json(['message' => 'Inspection deleted.']);
    }

    /**
     * Raise a scrap note for what this inspection rejected.
     *
     * Offered as an action rather than done automatically on failure: not every
     * rejection is scrapped. Fabric rejected here goes back to the supplier,
     * a moulding is reground, a batch is reprocessed — and a system that wrote
     * off stock the moment an inspector typed a number would be wrong more often
     * than it was right.
     */
    public function raiseScrap(Request $request, QualityInspection $qualityInspection)
    {
        $data = $request->validate([
            'warehouse_id' => ['required', 'exists:warehouses,id'],
            'quantity' => ['nullable', 'numeric', 'min:0.001'],
            'unit_cost' => ['nullable', 'numeric', 'min:0'],
            'recoverable_value' => ['nullable', 'numeric', 'min:0'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        $rejected = (float) $qualityInspection->rejected_quantity;
        if ($rejected <= 0) {
            return response()->json(['message' => 'This inspection rejected nothing.'], 422);
        }

        $quantity = (float) ($data['quantity'] ?? $rejected);
        if ($quantity > $rejected) {
            return response()->json(['message' => 'Cannot scrap more than was rejected.'], 422);
        }

        $unitCost = (float) ($data['unit_cost'] ?? $qualityInspection->product?->cost_price ?? 0);

        /**
         * Incoming QC rejects material that is in the warehouse; every other
         * stage rejects output that was never received into it. That is exactly
         * the material/finished distinction the scrap note needs, and deriving
         * it from the stage means the inspector does not have to know it.
         */
        $scrapType = $qualityInspection->stage === 'incoming' ? 'material' : 'finished';

        $scrap = DB::transaction(function () use ($qualityInspection, $data, $quantity, $unitCost, $scrapType, $request) {
            $scrap = ProductionScrap::create([
                'scrap_number' => ProductionScrap::nextNumber(),
                'scrap_date' => now()->toDateString(),
                'manufacturing_order_id' => $qualityInspection->manufacturing_order_id,
                'quality_inspection_id' => $qualityInspection->id,
                'product_id' => $qualityInspection->product_id,
                'variant_id' => $qualityInspection->variant_id,
                'warehouse_id' => $data['warehouse_id'],
                'quantity' => $quantity,
                'scrap_type' => $scrapType,
                'reason' => 'rejection',
                'unit_cost' => $unitCost,
                'total_value' => round($quantity * $unitCost, 2),
                'recoverable_value' => (float) ($data['recoverable_value'] ?? 0),
                'affects_stock' => $scrapType === 'material',
                'remarks' => $data['remarks'] ?? "Raised from inspection {$qualityInspection->inspection_number}",
                'created_by' => $request->user()?->id,
            ]);

            $this->shopFloor->postScrap($scrap, $request->user()?->id);

            return $scrap;
        });

        return response()->json([
            'message' => 'Scrap note '.$scrap->scrap_number.' raised.',
            'data' => ['id' => $scrap->id, 'scrap_number' => $scrap->scrap_number],
        ], 201);
    }

    /**
     * Defect Pareto over a window: which reasons account for the rejections.
     *
     * The single most useful thing this module produces. A rejection rate says
     * there is a problem; this says where it is, and the top two or three lines
     * are almost always most of the loss.
     */
    public function defectAnalysis(Request $request)
    {
        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $rows = QualityInspectionDefect::query()
            ->selectRaw('defect, severity, SUM(quantity) as qty, COUNT(*) as occurrences')
            ->whereHas('inspection', function ($q) use ($data) {
                if (! empty($data['from'])) {
                    $q->whereDate('inspected_on', '>=', $data['from']);
                }
                if (! empty($data['to'])) {
                    $q->whereDate('inspected_on', '<=', $data['to']);
                }
            })
            ->groupBy('defect', 'severity')
            ->orderByDesc('qty')
            ->limit(50)
            ->get();

        $total = (float) $rows->sum('qty');
        $running = 0.0;

        return response()->json([
            'data' => $rows->map(function ($r) use ($total, &$running) {
                $qty = (float) $r->qty;
                $running += $qty;

                return [
                    'defect' => $r->defect,
                    'severity' => $r->severity,
                    'quantity' => round($qty, 3),
                    'occurrences' => (int) $r->occurrences,
                    'share_percent' => $total > 0 ? round($qty / $total * 100, 1) : null,
                    // The Pareto line: how much of all rejection this row and
                    // everything above it accounts for.
                    'cumulative_percent' => $total > 0 ? round($running / $total * 100, 1) : null,
                ];
            })->values(),
            'meta' => ['total_quantity' => round($total, 3)],
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * Map validated input onto the model's columns, defaulting the result from
     * the quantities when the inspector did not state one (see
     * QualityInspection::impliedResult).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        $attributes = [
            'stage' => $data['stage'],
            'inspected_on' => $data['inspected_on'],
            'manufacturing_order_id' => $data['manufacturing_order_id'] ?? null,
            'goods_receipt_id' => $data['goods_receipt_id'] ?? null,
            'product_id' => $data['product_id'],
            'variant_id' => $data['variant_id'] ?? null,
            'batch_id' => $data['batch_id'] ?? null,
            'lot_quantity' => $data['lot_quantity'],
            'sample_size' => $data['sample_size'] ?? 0,
            'accepted_quantity' => $data['accepted_quantity'] ?? 0,
            'rejected_quantity' => $data['rejected_quantity'] ?? 0,
            'rework_quantity' => $data['rework_quantity'] ?? 0,
            'inspector_id' => $data['inspector_id'] ?? null,
            'remarks' => $data['remarks'] ?? null,
        ];

        $result = $data['result'] ?? null;
        if ($result === null || $result === 'pending') {
            $probe = new QualityInspection($attributes);
            // 'pending' is preserved only when nothing has been judged yet —
            // otherwise an inspection with numbers on it would sit unresolved.
            $judged = (float) $probe->accepted_quantity + (float) $probe->rejected_quantity + (float) $probe->rework_quantity;
            $result = $judged > 0 ? $probe->impliedResult() : 'pending';
        }

        return $attributes + ['result' => $result];
    }

    /** @param  array<int, array<string, mixed>>  $defects */
    private function syncDefects(QualityInspection $inspection, array $defects): void
    {
        foreach ($defects as $line) {
            if (($line['defect'] ?? '') === '') {
                continue;
            }

            $inspection->defects()->create([
                'defect' => $line['defect'],
                'quantity' => (float) ($line['quantity'] ?? 0),
                'severity' => $line['severity'] ?? 'minor',
                'remarks' => $line['remarks'] ?? null,
            ]);
        }
    }

    private function summary(Request $request): array
    {
        $scope = QualityInspection::query();
        if ($from = $request->query('from')) {
            $scope->whereDate('inspected_on', '>=', $from);
        }
        if ($to = $request->query('to')) {
            $scope->whereDate('inspected_on', '<=', $to);
        }

        $totals = (clone $scope)
            ->selectRaw('SUM(lot_quantity) as lot, SUM(rejected_quantity) as rejected, SUM(rework_quantity) as rework')
            ->first();

        $lot = (float) ($totals->lot ?? 0);
        $rejected = (float) ($totals->rejected ?? 0);

        return [
            'inspections' => (clone $scope)->count(),
            'failed' => (clone $scope)->where('result', 'failed')->count(),
            'pending' => (clone $scope)->where('result', 'pending')->count(),
            'lot_quantity' => round($lot, 3),
            'rejected_quantity' => round($rejected, 3),
            'rework_quantity' => round((float) ($totals->rework ?? 0), 3),
            // Null rather than zero when nothing has been inspected: a 0%
            // rejection rate on no inspections reads as a perfect month.
            'rejection_percent' => $lot > 0 ? round($rejected / $lot * 100, 2) : null,
        ];
    }

    private function row(QualityInspection $i, bool $withDefects = false): array
    {
        return [
            'id' => $i->id,
            'inspection_number' => $i->inspection_number,
            'stage' => $i->stage,
            'stage_label' => QualityInspection::STAGES[$i->stage] ?? $i->stage,
            'inspected_on' => $i->inspected_on?->toDateString(),
            'manufacturing_order_id' => $i->manufacturing_order_id,
            'mo_number' => $i->manufacturingOrder?->mo_number,
            'goods_receipt_id' => $i->goods_receipt_id,
            'grn_number' => $i->goodsReceipt?->grn_number,
            'product_id' => $i->product_id,
            'product' => $i->product?->name,
            'sku' => $i->product?->sku,
            'variant_id' => $i->variant_id,
            'batch_id' => $i->batch_id,
            'batch_number' => $i->batch?->batch_number,
            'lot_quantity' => (float) $i->lot_quantity,
            'sample_size' => (float) $i->sample_size,
            'accepted_quantity' => (float) $i->accepted_quantity,
            'rejected_quantity' => (float) $i->rejected_quantity,
            'rework_quantity' => (float) $i->rework_quantity,
            'rejection_percent' => $i->rejectionPercent(),
            'result' => $i->result,
            'inspector_id' => $i->inspector_id,
            'inspector' => $i->inspector
                ? trim($i->inspector->first_name.' '.($i->inspector->last_name ?? ''))
                : null,
            'remarks' => $i->remarks,
            'defects_count' => $i->defects_count ?? $i->defects->count(),
            'scrapped' => $withDefects ? $i->scraps->isNotEmpty() : null,
            'defects' => $withDefects ? $i->defects->map(fn (QualityInspectionDefect $d) => [
                'id' => $d->id,
                'defect' => $d->defect,
                'quantity' => (float) $d->quantity,
                'severity' => $d->severity,
                'remarks' => $d->remarks,
            ])->values() : null,
        ];
    }

    /** Runs a QC screen can be booked against — open and recently closed. */
    public function orderOptions()
    {
        return response()->json([
            'data' => ManufacturingOrder::query()
                ->whereIn('status', ['in_progress', 'completed'])
                ->orderByDesc('id')
                ->limit(200)
                ->get(['id', 'mo_number', 'product_id', 'quantity_to_produce', 'quantity_produced'])
                ->map(fn (ManufacturingOrder $o) => [
                    'id' => $o->id,
                    'mo_number' => $o->mo_number,
                    'product_id' => $o->product_id,
                    'quantity' => (float) ($o->quantity_produced ?: $o->quantity_to_produce),
                ])->values(),
        ]);
    }
}
