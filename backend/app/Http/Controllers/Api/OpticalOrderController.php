<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EyePrescription;
use App\Models\OpticalOrder;
use App\Models\Product;
use App\Services\OpticalService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Spectacle orders: frame + lenses, ground to the powers on the order.
 *
 * The workshop flow (draft → ordered → at_lab → received → fitted → delivered)
 * moves forward only, through its own endpoint rather than a writable status
 * field — each step stamps a time the shop chases the lab on, and delivering
 * bills the order.
 */
class OpticalOrderController extends Controller
{
    public function __construct(private readonly OpticalService $optical) {}

    public function index(Request $request)
    {
        $query = OpticalOrder::query()->with([
            'customer:id,name,phone',
            'frame:id,sku,name',
            'lens:id,sku,name,lens_type',
            'prescription:id,prescription_number,patient_name',
        ]);

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('order_number', 'like', "%{$search}%")
                    ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$search}%"));
            });
        }
        foreach (['status', 'customer_id'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->query($filter));
            }
        }
        if ($request->boolean('open_only')) {
            $query->whereNotIn('status', ['delivered', 'cancelled']);
        }
        if ($request->boolean('overdue_only')) {
            $query->whereNotNull('promised_on')
                ->where('promised_on', '<', now()->toDateString())
                ->whereNotIn('status', ['delivered', 'cancelled']);
        }

        $query->orderByDesc('id');
        $perPage = min((int) $request->query('per_page', 15), 100);

        return response()->json(
            $query->paginate($perPage)->through(fn (OpticalOrder $o) => $this->row($o))->toArray()
            + ['summary' => $this->summary()],
        );
    }

    public function store(Request $request)
    {
        $data = $this->validatePayload($request);

        $order = DB::transaction(function () use ($request, $data) {
            $order = new OpticalOrder($data + [
                'order_number' => OpticalOrder::nextNumber(),
                'status' => 'draft',
                'created_by' => $request->user()?->id,
            ]);
            $order->save();
            $this->optical->reprice($order);

            return $order;
        });

        return response()->json(['data' => $this->row($order->load($this->relations()))], 201);
    }

    public function show(OpticalOrder $opticalOrder)
    {
        return response()->json(['data' => $this->row($opticalOrder->load($this->relations()))]);
    }

    public function update(Request $request, OpticalOrder $opticalOrder)
    {
        if ($opticalOrder->isClosed()) {
            return response()->json(['message' => 'A delivered or cancelled order can no longer be edited.'], 422);
        }
        if ($opticalOrder->invoice_id) {
            return response()->json(['message' => 'This order is already invoiced and can no longer be edited.'], 422);
        }
        // Once the lab has the docket, changing the powers means the lenses
        // being ground are wrong. Cancel and re-order instead — that leaves a
        // trail the lab bill can be reconciled against.
        if (in_array($opticalOrder->status, ['at_lab', 'received', 'fitted'], true)) {
            return response()->json([
                'message' => 'This order is already with the lab. Cancel it and raise a new one to change the powers.',
            ], 422);
        }

        $data = $this->validatePayload($request);

        DB::transaction(function () use ($opticalOrder, $data) {
            $opticalOrder->update($data);
            $this->optical->reprice($opticalOrder);
        });

        return response()->json(['data' => $this->row($opticalOrder->load($this->relations()))]);
    }

    public function destroy(OpticalOrder $opticalOrder)
    {
        if ($opticalOrder->invoice_id) {
            return response()->json(['message' => 'An invoiced order cannot be deleted. Cancel it instead.'], 422);
        }
        if ($opticalOrder->status !== 'draft') {
            return response()->json(['message' => 'Only a draft order can be deleted. Cancel it instead.'], 422);
        }

        $opticalOrder->delete();

        return response()->json(['message' => 'Order deleted.']);
    }

    /**
     * Move the order along the workshop flow. Delivering bills it, which is why
     * the whole transition sits behind one endpoint rather than a status field.
     */
    public function transition(Request $request, OpticalOrder $opticalOrder)
    {
        $data = $request->validate([
            'status' => ['required', Rule::in([...OpticalOrder::FLOW, 'cancelled'])],
            'lab_notes' => ['nullable', 'string'],
        ]);

        $status = $data['status'];

        if (! $opticalOrder->canMoveTo($status)) {
            return response()->json([
                'message' => "An order at '{$opticalOrder->status}' cannot move to '{$status}'. The workshop flow only runs forwards.",
            ], 422);
        }
        if ($status === 'cancelled' && $opticalOrder->invoice_id) {
            return response()->json([
                'message' => 'This order is invoiced. Raise a credit note against the invoice instead.',
            ], 422);
        }

        $invoice = DB::transaction(function () use ($request, $opticalOrder, $status, $data) {
            $opticalOrder->forceFill(array_filter([
                'status' => $status,
                'lab_notes' => $data['lab_notes'] ?? $opticalOrder->lab_notes,
                'ordered_at' => $opticalOrder->ordered_at ?? ($status === 'ordered' ? now() : null),
                'received_at' => $opticalOrder->received_at ?? ($status === 'received' ? now() : null),
                'delivered_at' => $status === 'delivered' ? now() : $opticalOrder->delivered_at,
            ], fn ($v) => $v !== null))->save();

            return $status === 'delivered'
                ? $this->optical->bill($opticalOrder->refresh(), $request->user()?->id)
                : null;
        });

        return response()->json([
            'data' => $this->row($opticalOrder->load($this->relations())),
            'invoice_raised' => (bool) $invoice,
        ]);
    }

    /**
     * Validate the order payload, then check the powers are dispensable and the
     * chosen lens can actually be made in them.
     *
     * @return array<string, mixed>
     */
    private function validatePayload(Request $request): array
    {
        $data = $request->validate([
            'customer_id' => ['required', 'exists:customers,id'],
            'eye_prescription_id' => ['nullable', 'exists:eye_prescriptions,id'],
            'warehouse_id' => ['nullable', 'exists:warehouses,id'],
            'frame_product_id' => ['nullable', 'exists:products,id'],
            'lens_product_id' => ['nullable', 'exists:products,id'],
            'vision_type' => ['required', Rule::in(['distance', 'near', 'bifocal', 'progressive'])],

            'right_sph' => ['nullable', 'numeric', 'between:-30,30'],
            'right_cyl' => ['nullable', 'numeric', 'between:-12,12'],
            'right_axis' => ['nullable', 'integer', 'between:0,180'],
            'right_add' => ['nullable', 'numeric', 'between:0,4'],
            'left_sph' => ['nullable', 'numeric', 'between:-30,30'],
            'left_cyl' => ['nullable', 'numeric', 'between:-12,12'],
            'left_axis' => ['nullable', 'integer', 'between:0,180'],
            'left_add' => ['nullable', 'numeric', 'between:0,4'],
            'pd' => ['nullable', 'numeric', 'between:40,85'],

            'frame_price' => ['nullable', 'numeric', 'min:0'],
            'lens_price' => ['nullable', 'numeric', 'min:0'],
            'fitting_charge' => ['nullable', 'numeric', 'min:0'],
            'discount_type' => ['nullable', Rule::in(['percent', 'flat'])],
            'discount_value' => ['nullable', 'numeric', 'min:0'],
            'tax_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],

            'promised_on' => ['nullable', 'date'],
            'lab_notes' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
        ]);

        // Copying the powers off the prescription is the common case; the order
        // still stores its own, so a later re-test cannot restate what was made.
        if (! empty($data['eye_prescription_id']) && ! $request->filled('right_sph') && ! $request->filled('left_sph')) {
            $data = array_merge($data, $this->powersFrom((int) $data['eye_prescription_id']));
        }

        $errors = $this->optical->validatePrescription($data);

        if (! empty($data['lens_product_id'])) {
            $lens = Product::find($data['lens_product_id']);
            if ($lens) {
                $errors = array_merge($errors, $this->optical->checkLensFits($lens, $data));
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages(['powers' => $errors]);
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function powersFrom(int $prescriptionId): array
    {
        $rx = EyePrescription::find($prescriptionId);

        if (! $rx) {
            return [];
        }

        return [
            'vision_type' => $rx->vision_type,
            'right_sph' => $rx->right_sph, 'right_cyl' => $rx->right_cyl,
            'right_axis' => $rx->right_axis, 'right_add' => $rx->right_add,
            'left_sph' => $rx->left_sph, 'left_cyl' => $rx->left_cyl,
            'left_axis' => $rx->left_axis, 'left_add' => $rx->left_add,
            'pd' => $rx->pd,
        ];
    }

    private function summary(): array
    {
        $byStatus = OpticalOrder::query()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return [
            'by_status' => $byStatus,
            'open' => (int) $byStatus->except(['delivered', 'cancelled'])->sum(),
            'at_lab' => (int) ($byStatus['at_lab'] ?? 0),
            'overdue' => OpticalOrder::whereNotNull('promised_on')
                ->where('promised_on', '<', now()->toDateString())
                ->whereNotIn('status', ['delivered', 'cancelled'])
                ->count(),
        ];
    }

    private function row(OpticalOrder $o): array
    {
        return [
            'id' => $o->id,
            'order_number' => $o->order_number,
            'customer_id' => $o->customer_id,
            'customer' => $o->relationLoaded('customer') && $o->customer
                ? ['id' => $o->customer->id, 'name' => $o->customer->name, 'phone' => $o->customer->phone]
                : null,
            'eye_prescription_id' => $o->eye_prescription_id,
            'prescription' => $o->relationLoaded('prescription') && $o->prescription
                ? ['id' => $o->prescription->id, 'number' => $o->prescription->prescription_number,
                    'patient_name' => $o->prescription->patient_name]
                : null,
            'frame' => $o->relationLoaded('frame') && $o->frame
                ? ['id' => $o->frame->id, 'sku' => $o->frame->sku, 'name' => $o->frame->name]
                : null,
            'lens' => $o->relationLoaded('lens') && $o->lens
                ? ['id' => $o->lens->id, 'sku' => $o->lens->sku, 'name' => $o->lens->name,
                    'lens_type' => $o->lens->lens_type]
                : null,

            'status' => $o->status,
            'vision_type' => $o->vision_type,
            'is_overdue' => $o->isOverdue(),

            'right' => [
                'sph' => $o->right_sph !== null ? (float) $o->right_sph : null,
                'cyl' => $o->right_cyl !== null ? (float) $o->right_cyl : null,
                'axis' => $o->right_axis,
                'add' => $o->right_add !== null ? (float) $o->right_add : null,
            ],
            'left' => [
                'sph' => $o->left_sph !== null ? (float) $o->left_sph : null,
                'cyl' => $o->left_cyl !== null ? (float) $o->left_cyl : null,
                'axis' => $o->left_axis,
                'add' => $o->left_add !== null ? (float) $o->left_add : null,
            ],
            'pd' => $o->pd !== null ? (float) $o->pd : null,

            'frame_price' => (float) $o->frame_price,
            'lens_price' => (float) $o->lens_price,
            'fitting_charge' => (float) $o->fitting_charge,
            'discount_type' => $o->discount_type,
            'discount_value' => (float) $o->discount_value,
            'discount_amount' => (float) $o->discount_amount,
            'tax_percent' => (float) $o->tax_percent,
            'tax_amount' => (float) $o->tax_amount,
            'total' => (float) $o->total,

            'promised_on' => $o->promised_on?->toDateString(),
            'ordered_at' => $o->ordered_at?->toIso8601String(),
            'received_at' => $o->received_at?->toIso8601String(),
            'delivered_at' => $o->delivered_at?->toIso8601String(),
            'invoice_id' => $o->invoice_id,
            'invoice' => $o->relationLoaded('invoice') ? $o->invoice?->invoice_number : null,
            'lab_notes' => $o->lab_notes,
            'notes' => $o->notes,
        ];
    }

    /** @return list<string> */
    private function relations(): array
    {
        return [
            'customer:id,name,phone', 'frame:id,sku,name', 'lens:id,sku,name,lens_type',
            'prescription:id,prescription_number,patient_name', 'invoice:id,invoice_number',
        ];
    }
}
