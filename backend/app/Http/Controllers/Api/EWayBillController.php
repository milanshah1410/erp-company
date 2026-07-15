<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\EWayBillRequest;
use App\Http\Resources\EWayBillResource;
use App\Models\Invoice;
use App\Services\EWayBillService;
use Illuminate\Http\Request;
use RuntimeException;

class EWayBillController extends Controller
{
    public function __construct(private readonly EWayBillService $service)
    {
    }

    /** Worklist: invoices with their e-way bill status. */
    public function index(Request $request)
    {
        $query = Invoice::query()->with(['customer:id,name,gst_number', 'eWayBill']);

        if ($status = $request->query('status')) {
            $status === 'none'
                ? $query->whereDoesntHave('eWayBill')
                : $query->whereHas('eWayBill', fn ($q) => $q->where('status', $status));
        }
        if ($search = $request->query('search')) {
            $query->where('invoice_number', 'like', "%{$search}%");
        }

        $query->orderByDesc('invoice_date')->orderByDesc('id');
        $perPage = min((int) $request->query('per_page', 15), 100);

        $invoices = $query->paginate($perPage)->through(fn (Invoice $inv) => [
            'invoice_id' => $inv->id,
            'invoice_number' => $inv->invoice_number,
            'invoice_date' => $inv->invoice_date?->toDateString(),
            'customer_name' => $inv->customer?->name,
            'total' => (float) $inv->total,
            'eway_bill' => $inv->eWayBill ? new EWayBillResource($inv->eWayBill) : null,
        ]);

        return response()->json($invoices);
    }

    public function show(Invoice $invoice)
    {
        $ewb = $invoice->eWayBill()->firstOrFail();

        return new EWayBillResource($ewb->load('invoice.customer'));
    }

    public function generate(EWayBillRequest $request, Invoice $invoice)
    {
        try {
            $ewb = $this->service->generate($invoice, $request->validated(), $request->user()?->id);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return new EWayBillResource($ewb->load('invoice.customer'));
    }

    public function updateVehicle(Request $request, Invoice $invoice)
    {
        $data = $request->validate([
            'vehicle_no' => ['required', 'string', 'max:20'],
            'transport_mode' => ['required', 'in:road,rail,air,ship'],
        ]);

        $ewb = $invoice->eWayBill()->firstOrFail();

        try {
            $this->service->updateVehicle($ewb, $data['vehicle_no'], $data['transport_mode']);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return new EWayBillResource($ewb->load('invoice.customer'));
    }

    public function cancel(Request $request, Invoice $invoice)
    {
        $data = $request->validate([
            'cancel_reason_code' => ['required', 'string', 'in:1,2,3,4'],
            'cancel_remarks' => ['required', 'string', 'max:100'],
        ]);

        $ewb = $invoice->eWayBill()->firstOrFail();

        try {
            $this->service->cancel($ewb, $data['cancel_reason_code'], $data['cancel_remarks']);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return new EWayBillResource($ewb->load('invoice.customer'));
    }
}
