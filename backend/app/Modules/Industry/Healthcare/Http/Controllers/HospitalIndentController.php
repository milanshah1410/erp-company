<?php

declare(strict_types=1);

namespace App\Modules\Industry\Healthcare\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\WarehouseStock;
use App\Modules\Industry\Healthcare\Http\Requests\HospitalIndentRequest;
use App\Modules\Industry\Healthcare\Models\HospitalIndent;
use App\Modules\Industry\Healthcare\Models\HospitalIndentItem;
use App\Modules\Industry\Healthcare\Services\HospitalStoreService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Ward indents on the central store: raise, approve, issue.
 *
 * The lifecycle is the point of the screen. A ward raises and submits; the store
 * approves, trimming quantities it cannot spare; the counter issues, possibly
 * over several days. Each step is a separate endpoint on a separate permission
 * because in a hospital they are separate people.
 */
class HospitalIndentController extends Controller
{
    public function __construct(
        private readonly HospitalStoreService $store,
    ) {}

    public function index(Request $request)
    {
        $query = HospitalIndent::query()
            ->with(['department:id,code,name', 'warehouse:id,name', 'items'])
            ->withCount('items');

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('indent_number', 'like', "%{$search}%")
                    ->orWhereHas('department', fn ($d) => $d->where('name', 'like', "%{$search}%"));
            });
        }
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($request->filled('department_id')) {
            $query->where('department_id', (int) $request->query('department_id'));
        }
        if ($request->filled('warehouse_id')) {
            $query->where('warehouse_id', (int) $request->query('warehouse_id'));
        }
        // The issue counter's view: everything the store still owes a ward.
        if ($request->boolean('pending_issue')) {
            $query->whereIn('status', ['approved', 'partially_issued']);
        }
        if ($from = $request->query('from')) {
            $query->whereDate('indent_date', '>=', $from);
        }
        if ($to = $request->query('to')) {
            $query->whereDate('indent_date', '<=', $to);
        }

        // Emergency first — an indent raised for a theatre in progress cannot be
        // three pages down. CASE keeps the ordering driver-independent.
        $query->orderByRaw("CASE priority WHEN 'emergency' THEN 0 WHEN 'urgent' THEN 1 ELSE 2 END")
            ->orderByDesc('id');

        return response()->json(
            $query->paginate(min((int) $request->query('per_page', 25), 100))
                ->through(fn (HospitalIndent $i) => $this->row($i))->toArray()
            + ['summary' => $this->summary()],
        );
    }

    public function show(HospitalIndent $hospitalIndent)
    {
        $hospitalIndent->load([
            'department:id,code,name', 'warehouse:id,name',
            'requester:id,name', 'approver:id,name',
            'items.product:id,sku,name', 'items.variant:id,sku', 'items.batch:id,batch_number,expiry_date',
        ]);

        return response()->json(['data' => $this->row($hospitalIndent, detailed: true)]);
    }

    public function store(HospitalIndentRequest $request)
    {
        $data = $request->validated();

        $indent = DB::transaction(function () use ($request, $data) {
            $indent = HospitalIndent::create([
                'indent_number' => HospitalIndent::nextNumber(),
                ...collect($data)->except('items')->all(),
                'status' => $request->boolean('submit') ? 'submitted' : 'draft',
                'requested_by' => $request->user()?->id,
                'created_by' => $request->user()?->id,
            ]);

            foreach ($data['items'] as $line) {
                $indent->items()->create([
                    'product_id' => $line['product_id'],
                    'variant_id' => $line['variant_id'] ?? null,
                    'batch_id' => $line['batch_id'] ?? null,
                    'requested_quantity' => $line['requested_quantity'],
                    'remarks' => $line['remarks'] ?? null,
                ]);
            }

            return $indent;
        });

        return response()->json(['data' => $this->row($indent->fresh(['items.product']), detailed: true)], 201);
    }

    /**
     * Amend an indent the store has not committed to yet.
     *
     * Lines are matched by id and never wholesale-replaced. The edit form
     * resends every line it displays, so re-creating them would double the
     * ward's request; and once anything has been issued a line describes goods
     * that have physically left the store. A line that comes back without its id
     * on an indent that has some is refused rather than guessed at — the same
     * rule ServiceJobController applies.
     */
    public function update(HospitalIndentRequest $request, HospitalIndent $hospitalIndent)
    {
        if (! $hospitalIndent->isEditable()) {
            return response()->json([
                'message' => "This indent is {$hospitalIndent->status} and its lines can no longer be changed.",
            ], 422);
        }

        $data = $request->validated();
        $existing = $hospitalIndent->items()->pluck('id')->all();

        DB::transaction(function () use ($hospitalIndent, $data, $existing) {
            $hospitalIndent->update(collect($data)->except('items')->all());

            $seen = [];

            foreach ($data['items'] as $line) {
                $id = $line['id'] ?? null;

                if ($id !== null) {
                    if (! in_array((int) $id, $existing, true)) {
                        throw ValidationException::withMessages([
                            'items' => ['One of the lines does not belong to this indent. Reload and try again.'],
                        ]);
                    }

                    $item = $hospitalIndent->items()->findOrFail($id);
                    $item->update([
                        'product_id' => $line['product_id'],
                        'variant_id' => $line['variant_id'] ?? null,
                        'batch_id' => $line['batch_id'] ?? null,
                        'requested_quantity' => $line['requested_quantity'],
                        'remarks' => $line['remarks'] ?? null,
                    ]);
                    $seen[] = (int) $item->id;

                    continue;
                }

                $item = $hospitalIndent->items()->create([
                    'product_id' => $line['product_id'],
                    'variant_id' => $line['variant_id'] ?? null,
                    'batch_id' => $line['batch_id'] ?? null,
                    'requested_quantity' => $line['requested_quantity'],
                    'remarks' => $line['remarks'] ?? null,
                ]);
                $seen[] = (int) $item->id;
            }

            // Lines the form no longer shows were deleted by the user. Safe here
            // because nothing has been issued against a draft.
            $hospitalIndent->items()->whereNotIn('id', $seen)->delete();
        });

        return response()->json(['data' => $this->row($hospitalIndent->fresh(['items.product']), detailed: true)]);
    }

    public function destroy(HospitalIndent $hospitalIndent)
    {
        if ($hospitalIndent->items()->where('issued_quantity', '>', 0)->exists()) {
            return response()->json([
                'message' => 'Stock has already been issued against this indent, so it cannot be deleted.',
            ], 422);
        }

        $hospitalIndent->delete();

        return response()->json(['message' => 'Indent deleted.']);
    }

    /** Send a draft to the store. */
    public function submit(HospitalIndent $hospitalIndent)
    {
        if ($hospitalIndent->status !== 'draft') {
            return response()->json(['message' => "This indent is already {$hospitalIndent->status}."], 422);
        }

        $hospitalIndent->forceFill(['status' => 'submitted'])->save();

        return response()->json([
            'message' => 'Indent submitted to the store.',
            'data' => $this->row($hospitalIndent->fresh(['items.product']), detailed: true),
        ]);
    }

    /**
     * The store commits to what it will release. Quantities may be cut here and
     * only here — after this the lines are what was agreed.
     */
    public function approve(Request $request, HospitalIndent $hospitalIndent)
    {
        if (! in_array($hospitalIndent->status, ['draft', 'submitted'], true)) {
            return response()->json(['message' => "This indent is already {$hospitalIndent->status}."], 422);
        }

        $validated = $request->validate([
            'items' => ['nullable', 'array'],
            'items.*.id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'numeric', 'min:0'],
        ]);

        $hospitalIndent->load('items');

        DB::transaction(function () use ($request, $hospitalIndent, $validated) {
            $this->store->applyApprovedQuantities($hospitalIndent, $validated['items'] ?? []);

            $hospitalIndent->forceFill([
                'status' => 'approved',
                'approved_by' => $request->user()?->id,
                'approved_at' => now(),
            ])->save();
        });

        return response()->json([
            'message' => 'Indent approved.',
            'data' => $this->row($hospitalIndent->fresh(['items.product']), detailed: true),
        ]);
    }

    public function reject(Request $request, HospitalIndent $hospitalIndent)
    {
        if (! in_array($hospitalIndent->status, ['draft', 'submitted'], true)) {
            return response()->json(['message' => "This indent is already {$hospitalIndent->status}."], 422);
        }

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $hospitalIndent->forceFill([
            'status' => 'rejected',
            'rejection_reason' => $validated['reason'],
            'approved_by' => $request->user()?->id,
            'approved_at' => now(),
        ])->save();

        return response()->json([
            'message' => 'Indent rejected.',
            'data' => $this->row($hospitalIndent->fresh(['items.product']), detailed: true),
        ]);
    }

    /**
     * Release stock to the ward.
     *
     * With no `items` payload this issues everything still pending, which is the
     * common case at the counter. Partial issues are ordinary — see
     * HospitalStoreService.
     */
    public function issue(Request $request, HospitalIndent $hospitalIndent)
    {
        $validated = $request->validate([
            'items' => ['nullable', 'array'],
            'items.*.id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'numeric', 'min:0'],
        ]);

        $hospitalIndent->load('items.product');

        $lines = $validated['items'] ?? $hospitalIndent->items
            ->filter(fn (HospitalIndentItem $i) => $i->pendingQuantity() > 0)
            ->map(fn (HospitalIndentItem $i) => ['id' => $i->id, 'quantity' => $i->pendingQuantity()])
            ->values()
            ->all();

        if ($lines === []) {
            return response()->json(['message' => 'Nothing left to issue on this indent.'], 422);
        }

        try {
            $result = $this->store->issue($hospitalIndent, $lines, $request->user()?->id);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => "{$result['issued']} line(s) issued.",
            'issue_value' => $result['value'],
            'data' => $this->row($hospitalIndent->fresh(['items.product']), detailed: true),
        ]);
    }

    /** @return array<string, mixed> */
    private function summary(): array
    {
        $counts = HospitalIndent::query()
            ->selectRaw('status, COUNT(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status');

        return [
            'awaiting_approval' => (int) ($counts['submitted'] ?? 0),
            'pending_issue' => (int) (($counts['approved'] ?? 0) + ($counts['partially_issued'] ?? 0)),
            'issued' => (int) ($counts['issued'] ?? 0),
        ];
    }

    /** @return array<string, mixed> */
    private function row(HospitalIndent $i, bool $detailed = false): array
    {
        $items = $i->relationLoaded('items') ? $i->items : collect();

        $row = [
            'id' => $i->id,
            'indent_number' => $i->indent_number,
            'indent_date' => $i->indent_date?->toDateString(),
            'required_by' => $i->required_by?->toDateString(),
            'priority' => $i->priority,
            'status' => $i->status,
            'department_id' => $i->department_id,
            'department' => $i->department?->name,
            'warehouse_id' => $i->warehouse_id,
            'warehouse' => $i->warehouse?->name,
            'items_count' => $i->items_count ?? $items->count(),
            'requested_total' => round((float) $items->sum(fn (HospitalIndentItem $x) => (float) $x->requested_quantity), 3),
            'issued_total' => round((float) $items->sum(fn (HospitalIndentItem $x) => (float) $x->issued_quantity), 3),
            // What the ward has actually consumed, at the cost the ledger
            // charged it out at.
            'issue_value' => round((float) $items->sum(fn (HospitalIndentItem $x) => (float) $x->issue_value), 2),
            'is_editable' => $i->isEditable(),
            'is_issuable' => $i->isIssuable(),
            'rejection_reason' => $i->rejection_reason,
            'notes' => $i->notes,
            'approved_at' => $i->approved_at?->toDateTimeString(),
            'issued_at' => $i->issued_at?->toDateTimeString(),
        ];

        if (! $detailed) {
            return $row;
        }

        // On-hand at the issuing store, so whoever is releasing can see the
        // shortfall before they try — the alternative is discovering it from a
        // 422 with a nurse waiting.
        $onHand = WarehouseStock::query()
            ->where('warehouse_id', $i->warehouse_id)
            ->whereIn('product_id', $items->pluck('product_id')->all())
            ->get(['product_id', 'variant_id', 'quantity'])
            ->keyBy(fn ($s) => $s->product_id.':'.($s->variant_id ?? ''));

        return $row + [
            'requested_by' => $i->requester?->name,
            'approved_by' => $i->approver?->name,
            'items' => $items->map(fn (HospitalIndentItem $x) => [
                'id' => $x->id,
                'product_id' => $x->product_id,
                'product' => $x->product?->name,
                'sku' => $x->product?->sku,
                'variant_id' => $x->variant_id,
                'batch_id' => $x->batch_id,
                'batch_number' => $x->batch?->batch_number,
                'expiry_date' => $x->batch?->expiry_date?->toDateString(),
                'requested_quantity' => (float) $x->requested_quantity,
                'approved_quantity' => $x->approved_quantity !== null ? (float) $x->approved_quantity : null,
                'issued_quantity' => (float) $x->issued_quantity,
                'pending_quantity' => $x->pendingQuantity(),
                'available_quantity' => (float) ($onHand->get($x->product_id.':'.($x->variant_id ?? ''))?->quantity ?? 0),
                'unit_cost' => (float) $x->unit_cost,
                'issue_value' => (float) $x->issue_value,
                'remarks' => $x->remarks,
            ])->values(),
        ];
    }
}
