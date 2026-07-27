<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ShipmentExpenseResource;
use App\Models\Shipment;
use App\Models\ShipmentExpense;
use App\Support\TradeCatalogue;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Logistics Expenses (SRS §24) — every cost booked against a shipment: ocean or
 * air freight, terminal handling, CHA charges, duty, inland transport, detention,
 * bank charges.
 *
 * These rows are the cost side of the Shipment Profit board and the vendor half
 * of Pending Payments, so the money columns are derived server-side
 * (ShipmentExpense::booted keeps base_amount and payment_status in step) and a
 * client can never post an inconsistent set.
 */
class ShipmentExpenseController extends Controller
{
    public function index(Request $request)
    {
        $query = ShipmentExpense::query()
            ->with(['shipment:id,shipment_number,direction', 'creator:id,name']);

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('vendor_name', 'like', "%{$search}%")
                    ->orWhere('invoice_number', 'like', "%{$search}%")
                    ->orWhereHas('shipment', fn ($s) => $s->where('shipment_number', 'like', "%{$search}%"));
            });
        }
        foreach (['shipment_id', 'expense_type', 'payment_status', 'vendor_type', 'vendor_id'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->query($filter));
            }
        }
        if ($request->filled('direction')) {
            $query->whereHas('shipment', fn ($s) => $s->where('direction', $request->query('direction')));
        }
        if ($request->filled('date_from')) {
            $query->whereDate('expense_date', '>=', $request->query('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('expense_date', '<=', $request->query('date_to'));
        }
        if ($request->boolean('unsettled_only')) {
            $query->unsettled();
        }

        $query->orderByDesc('expense_date')->orderByDesc('id');
        $perPage = min((int) $request->query('per_page', 15), 100);

        return ShipmentExpenseResource::collection($query->paginate($perPage));
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $shipment = Shipment::findOrFail($data['shipment_id']);

        $expense = $shipment->expenses()->create($this->defaults($data) + [
            'created_by' => $request->user()?->id,
        ]);

        return (new ShipmentExpenseResource($expense->load(['shipment:id,shipment_number,direction'])))
            ->response()->setStatusCode(201);
    }

    public function show(ShipmentExpense $shipmentExpense)
    {
        return new ShipmentExpenseResource(
            $shipmentExpense->load(['shipment:id,shipment_number,direction', 'creator:id,name'])
        );
    }

    public function update(Request $request, ShipmentExpense $shipmentExpense)
    {
        $data = $this->validated($request, $shipmentExpense);
        unset($data['shipment_id']);

        $shipmentExpense->update($this->defaults($data, $shipmentExpense));

        return new ShipmentExpenseResource(
            $shipmentExpense->load(['shipment:id,shipment_number,direction', 'creator:id,name'])
        );
    }

    public function destroy(ShipmentExpense $shipmentExpense)
    {
        $shipmentExpense->delete();

        return response()->json(['message' => 'Expense removed.']);
    }

    /**
     * Settle (or part-settle) a vendor bill. `paid_amount` is absolute, not a
     * delta, so re-posting the same figure is idempotent; payment_status follows
     * from it in the model.
     */
    public function pay(Request $request, ShipmentExpense $shipmentExpense)
    {
        $data = $request->validate([
            'paid_amount' => ['required', 'numeric', 'min:0'],
            'remarks' => ['nullable', 'string'],
        ]);

        $shipmentExpense->update([
            'paid_amount' => min((float) $data['paid_amount'], (float) $shipmentExpense->base_amount),
            'remarks' => $data['remarks'] ?? $shipmentExpense->remarks,
        ]);

        return new ShipmentExpenseResource(
            $shipmentExpense->load(['shipment:id,shipment_number,direction'])
        );
    }

    /**
     * Cost totals by head, for the screen's summary strip and the dashboard's
     * expense breakdown.
     */
    public function summary(Request $request)
    {
        $query = ShipmentExpense::query()
            ->when($request->filled('shipment_id'), fn ($q) => $q->where('shipment_id', $request->query('shipment_id')))
            ->when($request->filled('direction'), fn ($q) => $q->whereHas(
                'shipment',
                fn ($s) => $s->where('direction', $request->query('direction')),
            ))
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate('expense_date', '>=', $request->query('date_from')))
            ->when($request->filled('date_to'), fn ($q) => $q->whereDate('expense_date', '<=', $request->query('date_to')));

        $rows = (clone $query)
            ->selectRaw('expense_type, count(*) as entries, sum(base_amount) as total, sum(paid_amount) as paid')
            ->groupBy('expense_type')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => [
                'expense_type' => $row->expense_type,
                'label' => TradeCatalogue::expenseLabel($row->expense_type),
                'entries' => (int) $row->entries,
                'total' => round((float) $row->total, 2),
                'paid' => round((float) $row->paid, 2),
                'outstanding' => round((float) $row->total - (float) $row->paid, 2),
            ]);

        return response()->json([
            'by_type' => $rows,
            'total' => round((float) $rows->sum('total'), 2),
            'paid' => round((float) $rows->sum('paid'), 2),
            'outstanding' => round((float) $rows->sum('outstanding'), 2),
        ]);
    }

    /** Fill in the money defaults; base_amount itself is computed in the model. */
    private function defaults(array $data, ?ShipmentExpense $existing = null): array
    {
        $data['currency'] = $data['currency'] ?? $existing?->currency ?? 'INR';
        $data['exchange_rate'] = $data['exchange_rate'] ?? $existing?->exchange_rate ?? 1;
        $data['amount'] = $data['amount'] ?? 0;
        $data['paid_amount'] = $data['paid_amount'] ?? $existing?->paid_amount ?? 0;
        $data['is_billable'] = (bool) ($data['is_billable'] ?? $existing?->is_billable ?? false);

        return $this->resolveVendor($data);
    }

    /**
     * Check the vendor really exists on its master and take its name from there.
     * The lookup runs through the model, so the tenant scope applies and an id
     * belonging to another workspace is simply not found.
     */
    private function resolveVendor(array $data): array
    {
        $type = $data['vendor_type'] ?? null;
        $id = $data['vendor_id'] ?? null;

        if (! $type || $type === 'other') {
            $data['vendor_id'] = null;

            return $data;
        }
        if (! $id) {
            throw ValidationException::withMessages([
                'vendor_id' => 'Pick the '.TradeCatalogue::VENDOR_TYPES[$type].' this cost is billed by.',
            ]);
        }

        $vendor = ShipmentExpense::VENDOR_MODELS[$type]::find($id);
        if (! $vendor) {
            throw ValidationException::withMessages([
                'vendor_id' => 'The selected vendor no longer exists.',
            ]);
        }
        $data['vendor_name'] = $vendor->name;

        return $data;
    }

    private function validated(Request $request, ?ShipmentExpense $existing = null): array
    {
        return $request->validate([
            'shipment_id' => [$existing ? 'nullable' : 'required', 'exists:shipments,id'],
            'expense_type' => ['required', Rule::in(array_keys(TradeCatalogue::EXPENSE_TYPES))],
            'expense_date' => ['required', 'date'],
            // The biller: a master (type + id) or just a typed-in name.
            'vendor_type' => ['nullable', Rule::in(array_keys(TradeCatalogue::VENDOR_TYPES))],
            'vendor_id' => ['nullable', 'integer', 'min:1'],
            'vendor_name' => ['nullable', 'string', 'max:255'],
            'invoice_number' => ['nullable', 'string', 'max:255'],
            'currency' => ['nullable', 'string', 'size:3'],
            'exchange_rate' => ['nullable', 'numeric', 'min:0.000001'],
            'amount' => ['required', 'numeric', 'min:0'],
            'paid_amount' => ['nullable', 'numeric', 'min:0'],
            'due_date' => ['nullable', 'date'],
            'is_billable' => ['nullable', 'boolean'],
            'remarks' => ['nullable', 'string'],
        ]);
    }
}
