<?php

declare(strict_types=1);

namespace App\Modules\Industry\Cosmetics\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Industry\Cosmetics\Http\Requests\OpenUnitRequest;
use App\Modules\Industry\Cosmetics\Models\OpenUnit;
use App\Modules\Industry\Cosmetics\Services\OpenUnitService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * The tester register.
 *
 * Opening a unit is a stock movement, so the write paths go through
 * OpenUnitService rather than saving a row here — see the note there for why a
 * tester leaves stock as `sample` and why closing one posts nothing.
 */
class OpenUnitController extends Controller
{
    public function __construct(private readonly OpenUnitService $units) {}

    public function index(Request $request)
    {
        $rows = $this->units->register([
            'status' => $request->query('status'),
            'purpose' => $request->query('purpose'),
            'warehouse_id' => $request->query('warehouse_id'),
            'product_id' => $request->query('product_id'),
        ])->paginate(min((int) $request->query('per_page', 50), 200));

        return response()->json([
            'data' => collect($rows->items())->map(fn (OpenUnit $u) => $this->row($u))->all(),
            'meta' => [
                'current_page' => $rows->currentPage(),
                'last_page' => $rows->lastPage(),
                'per_page' => $rows->perPage(),
                'total' => $rows->total(),
                'purposes' => OpenUnit::PURPOSES,
                'statuses' => OpenUnit::STATUSES,
                // The one figure a counter manager is asked for: how many testers
                // should have gone in the bin already.
                'overdue' => OpenUnit::query()->overdue()->count(),
            ],
        ]);
    }

    public function show(OpenUnit $unit)
    {
        return response()->json(['data' => $this->row($unit->load([
            'product:id,name,sku', 'variant:id,name,sku', 'warehouse:id,name', 'openedBy:id,name', 'batch:id,batch_number,expiry_date',
        ]))]);
    }

    public function store(OpenUnitRequest $request)
    {
        try {
            $unit = $this->units->open($request->validated(), $request->user()?->id);
        } catch (RuntimeException $e) {
            // Almost always "there is nothing at that warehouse to open" from
            // StockService, which is a counter-level message and not a 500.
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->row($unit->load([
            'product:id,name,sku', 'variant:id,name,sku', 'warehouse:id,name', 'openedBy:id,name',
        ]))], 201);
    }

    /**
     * End a unit's life on the counter — used up, or binned past its PAO.
     *
     * A POST rather than a DELETE, and the row survives: what a tester cost and
     * how long it lasted is the only evidence a shop has when it decides how many
     * to open next season. Deleting it would erase the answer along with the row.
     */
    public function close(Request $request, OpenUnit $unit)
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['finished', 'discarded'])],
            'closed_on' => ['nullable', 'date', 'before_or_equal:today'],
            'notes' => ['nullable', 'string'],
        ]);

        try {
            $this->units->close($unit, $data['status'], $data['closed_on'] ?? null, $data['notes'] ?? null);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => $data['status'] === 'finished' ? 'Tester marked as used up.' : 'Tester written off.',
            'data' => $this->row($unit->fresh()->load(['product:id,name,sku', 'warehouse:id,name'])),
        ]);
    }

    public function destroy(OpenUnit $unit)
    {
        /**
         * Only ever a mis-keyed row, and only while it is still open: the stock
         * write-off that opened it stays on the ledger either way, so deleting a
         * closed unit would leave a movement with nothing behind it and lose the
         * one record of what the counter spent on testers. Closing is the way to
         * end a real unit.
         */
        if ($unit->status !== 'open') {
            return response()->json([
                'message' => 'A closed unit is the record of what a tester cost. It cannot be deleted.',
            ], 422);
        }

        $unit->delete();

        return response()->json(['message' => 'Entry removed. The stock write-off it made stays on the ledger.']);
    }

    private function row(OpenUnit $unit): array
    {
        return [
            'id' => $unit->id,
            'product_id' => $unit->product_id,
            'product_name' => $unit->product?->name,
            'product_sku' => $unit->product?->sku,
            'variant_id' => $unit->variant_id,
            'variant_name' => $unit->variant?->name,
            'batch_id' => $unit->batch_id,
            'batch_number' => $unit->batch?->batch_number,
            'warehouse_id' => $unit->warehouse_id,
            'warehouse_name' => $unit->warehouse?->name,
            'purpose' => $unit->purpose,
            'quantity' => (float) $unit->quantity,
            'opened_on' => $unit->opened_on?->toDateString(),
            'pao_months' => $unit->pao_months,
            'expires_on' => $unit->expires_on?->toDateString(),
            'days_left' => $unit->daysLeft(),
            'is_overdue' => $unit->isOverdue(),
            'status' => $unit->status,
            'closed_on' => $unit->closed_on?->toDateString(),
            'opened_by' => $unit->openedBy?->name,
            'notes' => $unit->notes,
        ];
    }
}
