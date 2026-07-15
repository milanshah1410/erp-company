<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PaymentTransactionResource;
use App\Models\Invoice;
use App\Models\PaymentTransaction;
use App\Services\PaymentGatewayService;
use Illuminate\Http\Request;

/**
 * Payment links / online-collection transactions (SRS §16): list the links a
 * tenant has generated and create a new one against a sales invoice.
 */
class PaymentTransactionController extends Controller
{
    public function __construct(private PaymentGatewayService $gateways) {}

    public function index(Request $request)
    {
        $query = PaymentTransaction::query()->with(['invoice:id,invoice_number', 'customer:id,name', 'voucher:id,voucher_number']);

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }
        if ($request->filled('gateway')) {
            $query->where('gateway', $request->query('gateway'));
        }
        if ($search = $request->query('search')) {
            $query->whereHas('invoice', fn ($q) => $q->where('invoice_number', 'like', "%{$search}%"))
                ->orWhere('gateway_payment_id', 'like', "%{$search}%");
        }

        $query->orderByDesc('id');
        $perPage = min((int) $request->query('per_page', 15), 100);

        return PaymentTransactionResource::collection($query->paginate($perPage));
    }

    public function show(PaymentTransaction $paymentTransaction)
    {
        $paymentTransaction->load(['invoice:id,invoice_number', 'customer:id,name', 'voucher:id,voucher_number']);

        return new PaymentTransactionResource($paymentTransaction);
    }

    /** Generate a hosted payment link for a sales invoice. */
    public function createForInvoice(Request $request, Invoice $invoice)
    {
        $data = $request->validate([
            'amount' => ['nullable', 'numeric', 'min:0.01'],
            'currency' => ['nullable', 'string', 'size:3'],
        ]);

        $txn = $this->gateways->createLinkForInvoice($invoice, [
            'amount' => $data['amount'] ?? null,
            'currency' => $data['currency'] ?? null,
            'created_by' => $request->user()?->id,
        ]);

        return (new PaymentTransactionResource($txn->load(['invoice:id,invoice_number', 'customer:id,name'])))
            ->response()->setStatusCode(201);
    }

    /** Cancel an open (unpaid) payment link. */
    public function cancel(PaymentTransaction $paymentTransaction)
    {
        if ($paymentTransaction->isPaid()) {
            return response()->json(['message' => 'A completed payment cannot be cancelled.'], 422);
        }

        $paymentTransaction->update(['status' => 'cancelled']);

        return new PaymentTransactionResource($paymentTransaction);
    }
}
