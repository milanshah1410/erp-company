<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PaymentVoucherRequest;
use App\Http\Resources\PaymentVoucherResource;
use App\Models\PaymentVoucher;
use App\Services\PaymentService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Unified payments: customer receipts (inbound) and supplier/vendor/expense
 * payouts (outbound), in any mode, optionally allocated across open invoices.
 * See {@see PaymentService} for the allocation + advance accounting.
 */
class PaymentVoucherController extends Controller
{
    public function __construct(private PaymentService $service)
    {
    }

    public function index(Request $request)
    {
        $query = PaymentVoucher::query()->with('party:id,name');

        if ($request->filled('direction')) {
            $query->where('direction', $request->query('direction'));
        }
        if ($request->filled('mode')) {
            $query->where('mode', $request->query('mode'));
        }
        if ($request->filled('party_type') && isset(PaymentVoucher::PARTY_TYPES[$request->query('party_type')])) {
            $query->where('party_type', PaymentVoucher::PARTY_TYPES[$request->query('party_type')]);
            if ($request->filled('party_id')) {
                $query->where('party_id', $request->query('party_id'));
            }
        }
        if ($request->boolean('advances_only')) {
            $query->whereRaw('amount - allocated_amount > 0.005');
        }
        if ($request->filled('date_from')) {
            $query->whereDate('payment_date', '>=', $request->query('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('payment_date', '<=', $request->query('date_to'));
        }

        $query->orderByDesc('id');

        $perPage = min((int) $request->query('per_page', 15), 100);

        return PaymentVoucherResource::collection($query->paginate($perPage));
    }

    public function store(PaymentVoucherRequest $request)
    {
        $data = $request->validated();
        $data['created_by'] = $request->user()?->id;

        $voucher = $this->service->record($data);

        return (new PaymentVoucherResource($voucher->load(['party', 'allocations', 'creator'])))
            ->response()
            ->setStatusCode(201);
    }

    public function show(PaymentVoucher $paymentVoucher)
    {
        return new PaymentVoucherResource(
            $paymentVoucher->load(['party', 'allocations', 'creator'])
        );
    }

    /** Apply a booked advance (or its remainder) to open documents. */
    public function allocate(Request $request, PaymentVoucher $paymentVoucher)
    {
        $data = $request->validate([
            'allocations' => ['required', 'array', 'min:1'],
            'allocations.*.allocatable_type' => ['required', Rule::in(array_keys(PaymentVoucher::ALLOCATABLE_TYPES))],
            'allocations.*.allocatable_id' => ['required', 'integer'],
            'allocations.*.amount' => ['required', 'numeric', 'gt:0'],
        ]);

        $this->service->allocate($paymentVoucher, $data['allocations']);

        return new PaymentVoucherResource(
            $paymentVoucher->fresh()->load(['party', 'allocations', 'creator'])
        );
    }

    public function destroy(PaymentVoucher $paymentVoucher)
    {
        $this->service->delete($paymentVoucher);

        return response()->json(['message' => 'Payment voucher deleted.']);
    }
}
