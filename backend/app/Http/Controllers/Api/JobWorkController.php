<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\RendersDocumentPdf;
use App\Http\Controllers\Controller;
use App\Http\Requests\JobWorkRequest;
use App\Http\Resources\JobWorkResource;
use App\Models\JobWork;
use App\Models\JobWorkItem;
use App\Services\StockService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Job Work — outside-vendor subcontracting.
 *
 * Lifecycle: draft → issued (material leaves the warehouse to the vendor) →
 * partial / completed (finished goods received back). Each receipt reconciles
 * a material line into received / damage / shortage; the remainder is pending
 * at the vendor. Issue posts job_work_out; each received quantity posts
 * job_work_in for the finished product. Damage and shortage are reconciliation
 * only — the material already left stock at issue, so no further movement.
 */
class JobWorkController extends Controller
{
    use RendersDocumentPdf;

    public function __construct(private readonly StockService $stock) {}

    public function index(Request $request)
    {
        $query = JobWork::query()
            ->with(['supplier:id,name', 'warehouse:id,name'])
            ->withCount('items');

        if ($search = $request->query('search')) {
            $query->where('job_work_number', 'like', "%{$search}%");
        }
        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }
        if ($request->filled('supplier_id')) {
            $query->where('supplier_id', $request->query('supplier_id'));
        }

        $query->orderByDesc('id');
        $perPage = min((int) $request->query('per_page', 15), 100);

        return JobWorkResource::collection($query->paginate($perPage));
    }

    public function store(JobWorkRequest $request)
    {
        $data = $request->validated();

        $jobWork = DB::transaction(function () use ($request, $data) {
            $jobWork = JobWork::create([
                'job_work_number' => JobWork::nextNumber(),
                'supplier_id' => $data['supplier_id'],
                'warehouse_id' => $data['warehouse_id'],
                'status' => 'draft',
                'job_type' => $data['job_type'] ?? null,
                'job_work_date' => $data['job_work_date'],
                'expected_date' => $data['expected_date'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $request->user()?->id,
            ]);
            $this->syncItems($jobWork, $data['items']);

            return $jobWork;
        });

        return (new JobWorkResource($jobWork->load($this->relations())))
            ->response()->setStatusCode(201);
    }

    public function show(JobWork $jobWork)
    {
        return new JobWorkResource($jobWork->load($this->relations()));
    }

    /** Stream the job work order as a printable job-work challan. */
    public function pdf(Request $request, JobWork $jobWork)
    {
        $jobWork->load([
            'supplier', 'warehouse:id,name',
            'items.product:id,name,sku', 'items.variant:id,name,sku',
            'items.finishedProduct:id,name,sku',
        ]);

        return $this->renderDocument(
            $request,
            'documents.job-work-challan',
            ['jobWork' => $jobWork],
            "job-work-challan-{$jobWork->job_work_number}.pdf",
        );
    }

    public function update(JobWorkRequest $request, JobWork $jobWork)
    {
        if ($jobWork->status !== 'draft') {
            return response()->json(['message' => 'Only draft job works can be edited.'], 422);
        }
        $data = $request->validated();

        DB::transaction(function () use ($jobWork, $data) {
            $jobWork->update([
                'supplier_id' => $data['supplier_id'],
                'warehouse_id' => $data['warehouse_id'],
                'job_type' => $data['job_type'] ?? null,
                'job_work_date' => $data['job_work_date'],
                'expected_date' => $data['expected_date'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);
            $jobWork->items()->delete();
            $this->syncItems($jobWork, $data['items']);
        });

        return new JobWorkResource($jobWork->load($this->relations()));
    }

    public function destroy(JobWork $jobWork)
    {
        if ($jobWork->status !== 'draft') {
            return response()->json(['message' => 'Only draft job works can be deleted.'], 422);
        }
        $jobWork->delete();

        return response()->json(['message' => 'Job work deleted.']);
    }

    /**
     * Issue: raw material leaves the warehouse to the vendor (job_work_out).
     * The method-resolved issue cost is snapshotted per line so received
     * finished goods can inherit it as their default valuation.
     */
    public function issue(Request $request, JobWork $jobWork)
    {
        if ($jobWork->status !== 'draft') {
            return response()->json(['message' => 'Only draft job works can be issued.'], 422);
        }

        $jobWork->load('items');

        try {
            DB::transaction(function () use ($jobWork, $request) {
                foreach ($jobWork->items as $item) {
                    $ledger = $this->stock->outward(
                        warehouseId: $jobWork->warehouse_id,
                        productId: $item->product_id,
                        variantId: $item->variant_id,
                        quantity: (float) $item->issued_qty,
                        type: 'job_work_out',
                        referenceType: JobWork::class,
                        referenceId: $jobWork->id,
                        referenceNumber: $jobWork->job_work_number,
                        userId: $request->user()?->id,
                    );

                    // Snapshot the issue cost so received goods default to it.
                    $item->update([
                        'unit_cost' => (float) $ledger->unit_cost,
                        'finished_unit_cost' => (float) $ledger->unit_cost,
                    ]);
                }

                $jobWork->update([
                    'status' => 'issued',
                    'issued_date' => now()->toDateString(),
                    'issued_by' => $request->user()?->id,
                ]);
            });
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['items' => [$e->getMessage()]]);
        }

        return new JobWorkResource($jobWork->fresh()->load($this->relations()));
    }

    /**
     * Receive: record this receipt's received / damage / shortage per line and
     * post the received finished goods into the warehouse (job_work_in). May be
     * called repeatedly for partial receipts. Guards against reconciling more
     * than the quantity still pending on a line.
     */
    public function receive(Request $request, JobWork $jobWork)
    {
        if (! in_array($jobWork->status, ['issued', 'partial'], true)) {
            return response()->json(['message' => 'Only issued job works can receive material.'], 422);
        }

        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.item_id' => ['required', 'integer'],
            'items.*.received_qty' => ['nullable', 'numeric', 'min:0'],
            'items.*.damage_qty' => ['nullable', 'numeric', 'min:0'],
            'items.*.shortage_qty' => ['nullable', 'numeric', 'min:0'],
            'items.*.finished_product_id' => ['nullable', 'exists:products,id'],
            'items.*.finished_variant_id' => ['nullable', 'exists:product_variants,id'],
            'items.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
        ]);

        $itemsById = $jobWork->items()->get()->keyBy('id');
        $lines = collect($data['items']);

        // At least one non-zero quantity across the whole receipt.
        $hasQty = $lines->contains(fn ($l) => ((float) ($l['received_qty'] ?? 0) + (float) ($l['damage_qty'] ?? 0) + (float) ($l['shortage_qty'] ?? 0)) > 0);
        if (! $hasQty) {
            throw ValidationException::withMessages(['items' => ['Enter at least one received, damage or shortage quantity.']]);
        }

        // Validate every line before posting any stock.
        foreach ($lines as $line) {
            $item = $itemsById->get($line['item_id']);
            if (! $item) {
                throw ValidationException::withMessages(['items' => ['A line does not belong to this job work.']]);
            }
            $delta = (float) ($line['received_qty'] ?? 0) + (float) ($line['damage_qty'] ?? 0) + (float) ($line['shortage_qty'] ?? 0);
            if ($delta - (float) $item->pending_qty > 0.0001) {
                throw ValidationException::withMessages([
                    'items' => ["{$item->product?->name}: received + damage + shortage ({$delta}) exceeds the pending quantity ({$item->pending_qty})."],
                ]);
            }
        }

        DB::transaction(function () use ($jobWork, $lines, $itemsById, $request) {
            foreach ($lines as $line) {
                /** @var JobWorkItem $item */
                $item = $itemsById->get($line['item_id']);
                $received = round((float) ($line['received_qty'] ?? 0), 3);
                $damage = round((float) ($line['damage_qty'] ?? 0), 3);
                $shortage = round((float) ($line['shortage_qty'] ?? 0), 3);

                if ($received + $damage + $shortage <= 0) {
                    continue;
                }

                $finishedProductId = $line['finished_product_id'] ?? $item->finished_product_id ?? $item->product_id;
                $finishedVariantId = $line['finished_variant_id'] ?? $item->finished_variant_id ?? $item->variant_id;
                $unitCost = isset($line['unit_cost']) ? (float) $line['unit_cost']
                    : (float) ($item->finished_unit_cost ?: $item->unit_cost);

                if ($received > 0) {
                    $this->stock->inward(
                        warehouseId: $jobWork->warehouse_id,
                        productId: $finishedProductId,
                        variantId: $finishedVariantId,
                        quantity: $received,
                        unitCost: $unitCost,
                        type: 'job_work_in',
                        referenceType: JobWork::class,
                        referenceId: $jobWork->id,
                        referenceNumber: $jobWork->job_work_number,
                        userId: $request->user()?->id,
                    );
                }

                $item->update([
                    'received_qty' => round((float) $item->received_qty + $received, 3),
                    'damage_qty' => round((float) $item->damage_qty + $damage, 3),
                    'shortage_qty' => round((float) $item->shortage_qty + $shortage, 3),
                    'finished_product_id' => $finishedProductId,
                    'finished_variant_id' => $finishedVariantId,
                    'finished_unit_cost' => $unitCost,
                ]);
            }

            $jobWork->load('items');
            $jobWork->refreshStatus();
        });

        return new JobWorkResource($jobWork->fresh()->load($this->relations()));
    }

    /** Close: write off any remaining pending quantity (no stock movement). */
    public function close(Request $request, JobWork $jobWork)
    {
        if (! in_array($jobWork->status, ['issued', 'partial'], true)) {
            return response()->json(['message' => 'Only issued or partially received job works can be closed.'], 422);
        }
        $jobWork->update([
            'status' => 'closed',
            'closed_by' => $request->user()?->id,
            'completed_date' => now()->toDateString(),
        ]);

        return new JobWorkResource($jobWork->load($this->relations()));
    }

    public function cancel(JobWork $jobWork)
    {
        if ($jobWork->status !== 'draft') {
            return response()->json(['message' => 'Only draft job works can be cancelled (issued material must be received or closed).'], 422);
        }
        $jobWork->update(['status' => 'cancelled']);

        return new JobWorkResource($jobWork->load($this->relations()));
    }

    /** Material still out at vendors: issued but not yet reconciled. */
    public function pendingReport(Request $request)
    {
        return $this->reportRows($request, fn ($q) => $q
            ->whereRaw('issued_qty > received_qty + damage_qty + shortage_qty')
            ->whereHas('jobWork', fn ($j) => $j->whereIn('status', ['issued', 'partial'])));
    }

    /** Lines with damaged material recorded against them. */
    public function damageReport(Request $request)
    {
        return $this->reportRows($request, fn ($q) => $q->where('damage_qty', '>', 0));
    }

    /** Lines with short (lost / not returned) material recorded against them. */
    public function shortageReport(Request $request)
    {
        return $this->reportRows($request, fn ($q) => $q->where('shortage_qty', '>', 0));
    }

    /** Shared shaping for the pending / damage / shortage reports. */
    private function reportRows(Request $request, callable $filter)
    {
        $query = JobWorkItem::query()
            ->with(['product:id,sku,name', 'jobWork:id,job_work_number,supplier_id,status,job_work_date', 'jobWork.supplier:id,name'])
            ->whereHas('jobWork', fn ($j) => $j->whereNotIn('status', ['draft', 'cancelled']));

        $filter($query);

        if ($request->filled('supplier_id')) {
            $query->whereHas('jobWork', fn ($j) => $j->where('supplier_id', $request->query('supplier_id')));
        }

        $rows = $query->orderByDesc('job_work_id')->get()->map(fn ($i) => [
            'id' => $i->id,
            'job_work_id' => $i->job_work_id,
            'job_work_number' => $i->jobWork?->job_work_number,
            'supplier' => $i->jobWork?->supplier?->name,
            'status' => $i->jobWork?->status,
            'job_work_date' => $i->jobWork?->job_work_date?->toDateString(),
            'product' => $i->product ? ['sku' => $i->product->sku, 'name' => $i->product->name] : null,
            'issued_qty' => $i->issued_qty,
            'received_qty' => $i->received_qty,
            'damage_qty' => $i->damage_qty,
            'shortage_qty' => $i->shortage_qty,
            'pending_qty' => $i->pending_qty,
        ]);

        return response()->json(['data' => $rows]);
    }

    private function syncItems(JobWork $jobWork, array $items): void
    {
        foreach ($items as $item) {
            $jobWork->items()->create([
                'product_id' => $item['product_id'],
                'variant_id' => $item['variant_id'] ?? null,
                'finished_product_id' => $item['finished_product_id'] ?? null,
                'finished_variant_id' => $item['finished_variant_id'] ?? null,
                'issued_qty' => $item['issued_qty'],
                'unit_cost' => 0, // snapshotted at issue
                'finished_unit_cost' => 0,
            ]);
        }
    }

    private function relations(): array
    {
        return ['supplier', 'warehouse', 'creator', 'items.product', 'items.finishedProduct'];
    }
}
