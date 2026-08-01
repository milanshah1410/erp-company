<?php

declare(strict_types=1);

namespace App\Modules\Industry\Healthcare\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Industry\Healthcare\Http\Requests\LabOrderRequest;
use App\Modules\Industry\Healthcare\Models\LabOrder;
use App\Modules\Industry\Healthcare\Models\LabOrderItem;
use App\Modules\Industry\Healthcare\Models\LabOrderResult;
use App\Modules\Industry\Healthcare\Services\LabOrderService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Lab orders, from the registration desk to the released report.
 *
 * The list doubles as the lab's worklist — filter by status and the same screen
 * is the collection queue, the pending-results bench and the reports counter —
 * which is why the row carries the parameter progress rather than just a status
 * word.
 */
class LabOrderController extends Controller
{
    public function __construct(
        private readonly LabOrderService $lab,
    ) {}

    public function index(Request $request)
    {
        $query = LabOrder::query()
            ->with(['customer:id,name', 'items:id,lab_order_id,test_name,status'])
            ->withCount([
                'results',
                'results as pending_results_count' => fn ($q) => $q->where('flag', 'pending'),
                'results as abnormal_results_count' => fn ($q) => $q->whereIn('flag', ['low', 'high', 'abnormal']),
            ]);

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('order_number', 'like', "%{$search}%")
                    ->orWhere('patient_name', 'like', "%{$search}%")
                    ->orWhere('patient_phone', 'like', "%{$search}%")
                    ->orWhere('referring_doctor', 'like', "%{$search}%");
            });
        }
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($request->filled('priority')) {
            $query->where('priority', $request->query('priority'));
        }
        // The billing desk's view: reported work nobody has raised a bill for.
        if ($request->boolean('unbilled')) {
            $query->whereNull('invoice_id');
        }
        if ($from = $request->query('from')) {
            $query->whereDate('order_date', '>=', $from);
        }
        if ($to = $request->query('to')) {
            $query->whereDate('order_date', '<=', $to);
        }

        // Urgent first, then newest. CASE rather than MySQL's FIELD() so the
        // worklist orders the same way on any driver.
        $query->orderByRaw("CASE WHEN priority = 'urgent' THEN 0 ELSE 1 END")->orderByDesc('id');

        return response()->json(
            $query->paginate(min((int) $request->query('per_page', 25), 100))
                ->through(fn (LabOrder $o) => $this->row($o))->toArray()
            + ['summary' => $this->summary()],
        );
    }

    public function show(LabOrder $labOrder)
    {
        $labOrder->load([
            'customer:id,name,phone',
            'prescription:id,prescription_number,doctor_name',
            'invoice:id,invoice_number,total,status',
            'verifier:id,name',
            'items.test:id,code,name,is_panel',
            'results',
        ]);

        return response()->json(['data' => $this->row($labOrder, detailed: true)]);
    }

    public function store(LabOrderRequest $request)
    {
        $data = $request->validated();

        $order = DB::transaction(function () use ($request, $data) {
            $order = LabOrder::create([
                'order_number' => LabOrder::nextNumber(),
                ...collect($data)->except('items')->all(),
                'status' => 'registered',
                'created_by' => $request->user()?->id,
            ]);

            $this->lab->syncTests($order, $data['items']);

            return $order;
        });

        return response()->json(['data' => $this->row($order->fresh(['items', 'results']), detailed: true)], 201);
    }

    /**
     * Amend an order that has not been acted on yet.
     *
     * Refused the moment a sample exists: at that point the order describes a
     * needle that has already gone in, and adding a test to it would claim the
     * lab drew blood for something nobody had asked for at the time. The client
     * is told which of the two reasons applies, because "collected" and "billed"
     * need different remedies.
     */
    public function update(LabOrderRequest $request, LabOrder $labOrder)
    {
        if (! $labOrder->isEditable()) {
            return response()->json([
                'message' => $labOrder->invoice_id
                    ? 'This order has been billed and can no longer be changed.'
                    : 'Samples have already been collected against this order, so its tests are fixed.',
            ], 422);
        }

        $data = $request->validated();

        DB::transaction(function () use ($labOrder, $data) {
            $labOrder->update(collect($data)->except('items')->all());
            // Rewritten wholesale rather than diffed: nothing downstream of a
            // registered order exists yet, and matching parameter rows by test
            // would mangle an order that legitimately repeats one.
            $this->lab->syncTests($labOrder, $data['items']);
        });

        return response()->json(['data' => $this->row($labOrder->fresh(['items', 'results']), detailed: true)]);
    }

    public function destroy(LabOrder $labOrder)
    {
        if ($labOrder->invoice_id) {
            return response()->json([
                'message' => 'This order has been billed. Cancel it instead of deleting it.',
            ], 422);
        }

        $labOrder->delete();

        return response()->json(['message' => 'Lab order deleted.']);
    }

    /** Mark samples drawn — all pending lines, or the ones named. */
    public function collect(Request $request, LabOrder $labOrder)
    {
        $validated = $request->validate([
            'item_ids' => ['nullable', 'array'],
            'item_ids.*' => ['integer'],
        ]);

        $count = $this->lab->collect($labOrder, $validated['item_ids'] ?? null);

        return response()->json([
            'message' => $count > 0
                ? "{$count} sample(s) marked collected."
                : 'Nothing left to collect on this order.',
            'data' => $this->row($labOrder->fresh(['items', 'results']), detailed: true),
        ]);
    }

    /** Enter or amend values on the parameter sheet. */
    public function results(Request $request, LabOrder $labOrder)
    {
        if ($labOrder->status === 'cancelled') {
            return response()->json(['message' => 'This order is cancelled.'], 422);
        }

        $validated = $request->validate([
            'results' => ['required', 'array', 'min:1'],
            'results.*.id' => ['required', 'integer'],
            'results.*.result_value' => ['nullable', 'string', 'max:160'],
            'results.*.remarks' => ['nullable', 'string', 'max:255'],
        ]);

        $updated = $this->lab->saveResults($labOrder, $validated['results']);

        return response()->json([
            'message' => "{$updated} result(s) saved.",
            'data' => $this->row($labOrder->fresh(['items', 'results']), detailed: true),
        ]);
    }

    /**
     * Sign the report off. On the approve permission, not edit: entering a value
     * and standing behind it are different acts by different people.
     */
    public function verify(Request $request, LabOrder $labOrder)
    {
        try {
            $this->lab->verify($labOrder, $request->user()?->id);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Report released.',
            'data' => $this->row($labOrder->fresh(['items', 'results', 'verifier']), detailed: true),
        ]);
    }

    public function deliver(LabOrder $labOrder)
    {
        try {
            $this->lab->deliver($labOrder);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Report handed over.',
            'data' => $this->row($labOrder->fresh(['items', 'results']), detailed: true),
        ]);
    }

    /** Raise the sales order + invoice. Idempotent — see LabOrderService::bill(). */
    public function bill(Request $request, LabOrder $labOrder)
    {
        try {
            $invoice = DB::transaction(fn () => $this->lab->bill($labOrder, $request->user()?->id));
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => "Invoice {$invoice->invoice_number} raised.",
            'invoice' => [
                'id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'total' => (float) $invoice->total,
            ],
            'data' => $this->row($labOrder->fresh(['items', 'results', 'invoice']), detailed: true),
        ]);
    }

    public function cancel(Request $request, LabOrder $labOrder)
    {
        if ($labOrder->invoice_id) {
            return response()->json([
                'message' => 'This order has been billed. Credit the invoice rather than cancelling the order.',
            ], 422);
        }

        $labOrder->forceFill([
            'status' => 'cancelled',
            'notes' => trim(($labOrder->notes ?? '')."\nCancelled: ".(string) $request->input('reason')),
        ])->save();

        return response()->json([
            'message' => 'Lab order cancelled.',
            'data' => $this->row($labOrder->fresh(['items', 'results']), detailed: true),
        ]);
    }

    /**
     * The printable report: patient, prescriber, verified-by and the parameters
     * grouped the way the test master groups them.
     *
     * Refuses anything unverified. A result sheet printed before it is signed
     * looks exactly like a report to whoever is handed it, and that is the one
     * mistake in a lab worth making structurally impossible.
     */
    public function report(LabOrder $labOrder)
    {
        if (! in_array($labOrder->status, ['reported', 'delivered'], true)) {
            return response()->json([
                'message' => 'This report has not been verified yet.',
            ], 422);
        }

        $labOrder->load(['customer:id,name', 'verifier:id,name', 'items.test:id,name,category,method', 'results']);

        $byItem = $labOrder->results->groupBy('lab_order_item_id');

        return response()->json([
            'data' => [
                'order_number' => $labOrder->order_number,
                'order_date' => $labOrder->order_date?->toDateString(),
                'collected_at' => $labOrder->collected_at?->toDateTimeString(),
                'reported_at' => $labOrder->reported_at?->toDateTimeString(),
                'patient' => [
                    'name' => $labOrder->patient_name,
                    'age' => $labOrder->patient_age,
                    'gender' => $labOrder->patient_gender,
                    'phone' => $labOrder->patient_phone,
                ],
                'referring_doctor' => $labOrder->referring_doctor,
                'doctor_registration_no' => $labOrder->doctor_registration_no,
                'verified_by' => $labOrder->verifier?->name,
                'sections' => $labOrder->items->map(fn (LabOrderItem $item) => [
                    'test_name' => $item->test_name,
                    'category' => $item->test?->category,
                    'method' => $item->test?->method,
                    'parameters' => $byItem->get($item->id, collect())
                        ->map(fn (LabOrderResult $r) => $this->resultRow($r))->values(),
                ])->values(),
            ],
        ]);
    }

    /** Headline counts for the worklist. @return array<string, int|float> */
    private function summary(): array
    {
        $counts = LabOrder::query()
            ->selectRaw('status, COUNT(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status');

        return [
            'awaiting_collection' => (int) ($counts['registered'] ?? 0),
            'in_process' => (int) (($counts['collected'] ?? 0) + ($counts['processing'] ?? 0)),
            'reported' => (int) ($counts['reported'] ?? 0),
            // What the lab has done and not yet billed: the number that goes
            // missing when a report-first, bill-later workflow is left to memory.
            'unbilled_value' => (float) LabOrder::query()
                ->whereNull('invoice_id')
                ->whereNotIn('status', ['cancelled'])
                ->sum('total'),
        ];
    }

    /** @return array<string, mixed> */
    private function row(LabOrder $o, bool $detailed = false): array
    {
        $row = [
            'id' => $o->id,
            'order_number' => $o->order_number,
            'order_date' => $o->order_date?->toDateString(),
            'status' => $o->status,
            'priority' => $o->priority,
            'patient_name' => $o->patient_name,
            'patient_age' => $o->patient_age,
            'patient_gender' => $o->patient_gender,
            'patient_phone' => $o->patient_phone,
            'customer_id' => $o->customer_id,
            'customer' => $o->customer?->name,
            'referring_doctor' => $o->referring_doctor,
            'doctor_registration_no' => $o->doctor_registration_no,
            'prescription_id' => $o->prescription_id,
            'discount_type' => $o->discount_type,
            'discount_value' => (float) $o->discount_value,
            'subtotal' => (float) $o->subtotal,
            'discount_amount' => (float) $o->discount_amount,
            'tax_amount' => (float) $o->tax_amount,
            'total' => (float) $o->total,
            'invoice_id' => $o->invoice_id,
            'invoice_number' => $o->invoice?->invoice_number,
            'is_billed' => $o->invoice_id !== null,
            'is_editable' => $o->isEditable(),
            'collected_at' => $o->collected_at?->toDateTimeString(),
            'reported_at' => $o->reported_at?->toDateTimeString(),
            'delivered_at' => $o->delivered_at?->toDateTimeString(),
            'verified_by' => $o->verifier?->name,
            'notes' => $o->notes,
            'tests_count' => $o->items_count ?? $o->items->count(),
            'results_count' => $o->results_count ?? null,
            'pending_results_count' => $o->pending_results_count ?? null,
            'abnormal_results_count' => $o->abnormal_results_count ?? null,
            'tests' => $o->relationLoaded('items')
                ? $o->items->pluck('test_name')->implode(', ')
                : null,
        ];

        if (! $detailed) {
            return $row;
        }

        return $row + [
            'items' => $o->items->map(fn (LabOrderItem $i) => [
                'id' => $i->id,
                'lab_test_id' => $i->lab_test_id,
                'test_code' => $i->test_code,
                'test_name' => $i->test_name,
                'sample_type' => $i->sample_type,
                'is_panel' => (bool) ($i->test?->is_panel),
                'unit_price' => (float) $i->unit_price,
                'discount_type' => $i->discount_type,
                'discount_value' => (float) $i->discount_value,
                'discount_amount' => (float) $i->discount_amount,
                'tax_percent' => (float) $i->tax_percent,
                'tax_amount' => (float) $i->tax_amount,
                'line_total' => (float) $i->line_total,
                'status' => $i->status,
                'collected_at' => $i->collected_at?->toDateTimeString(),
            ])->values(),
            'results' => $o->results->map(fn (LabOrderResult $r) => $this->resultRow($r) + [
                'lab_order_item_id' => $r->lab_order_item_id,
            ])->values(),
        ];
    }

    /** @return array<string, mixed> */
    private function resultRow(LabOrderResult $r): array
    {
        return [
            'id' => $r->id,
            'parameter' => $r->parameter,
            'unit' => $r->unit,
            'reference_range' => $r->reference_range,
            'result_value' => $r->result_value,
            'numeric_value' => $r->numeric_value !== null ? (float) $r->numeric_value : null,
            'flag' => $r->flag,
            'is_abnormal' => $r->isAbnormal(),
            'remarks' => $r->remarks,
        ];
    }
}
