<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\EInvoiceResource;
use App\Models\Invoice;
use App\Services\EInvoiceService;
use Illuminate\Http\Request;
use RuntimeException;

class EInvoiceController extends Controller
{
    public function __construct(private readonly EInvoiceService $service)
    {
    }

    /**
     * Worklist: invoices with their e-invoice status, so staff can see what is
     * pending and generate/cancel from one screen.
     */
    public function index(Request $request)
    {
        $query = Invoice::query()->with(['customer:id,name,gst_number', 'eInvoice']);

        if ($status = $request->query('status')) {
            $status === 'none'
                ? $query->whereDoesntHave('eInvoice')
                : $query->whereHas('eInvoice', fn ($q) => $q->where('status', $status));
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
            'customer_gstin' => $inv->customer?->gst_number,
            'total' => (float) $inv->total,
            'einvoice' => $inv->eInvoice ? new EInvoiceResource($inv->eInvoice) : null,
        ]);

        return response()->json($invoices);
    }

    public function show(Invoice $invoice)
    {
        $eInvoice = $invoice->eInvoice()->firstOrFail();

        return new EInvoiceResource($eInvoice->load('invoice.customer'));
    }

    /** Preview the NIC payload without transmitting (dry run). */
    public function preview(Invoice $invoice)
    {
        try {
            return response()->json(['payload' => $this->service->buildPayload($invoice)]);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function generate(Request $request, Invoice $invoice)
    {
        try {
            $eInvoice = $this->service->generate($invoice, $request->user()?->id);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return new EInvoiceResource($eInvoice->load('invoice.customer'));
    }

    public function cancel(Request $request, Invoice $invoice)
    {
        $data = $request->validate([
            'cancel_reason_code' => ['required', 'string', 'in:1,2,3,4'],
            'cancel_remarks' => ['required', 'string', 'max:100'],
        ]);

        $eInvoice = $invoice->eInvoice()->firstOrFail();

        try {
            $this->service->cancel($eInvoice, $data['cancel_reason_code'], $data['cancel_remarks']);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return new EInvoiceResource($eInvoice->load('invoice.customer'));
    }
}
