<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class InvoiceController extends Controller
{
    public function index(Request $request)
    {
        $query = Invoice::query()->with(['customer:id,name', 'salesOrder:id,so_number']);

        if ($search = $request->query('search')) {
            $query->where('invoice_number', 'like', "%{$search}%");
        }
        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }
        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->query('customer_id'));
        }

        $query->orderByDesc('id');
        $perPage = min((int) $request->query('per_page', 15), 100);

        $page = $query->paginate($perPage);
        $page->getCollection()->transform(fn ($inv) => $this->payload($inv));

        return $page;
    }

    public function show(Invoice $invoice)
    {
        $invoice->load(['customer:id,name', 'salesOrder:id,so_number', 'payments.creator:id,name']);

        $data = $this->payload($invoice);
        $data['payments'] = $invoice->payments->map(fn ($p) => [
            'id' => $p->id,
            'amount' => $p->amount,
            'mode' => $p->mode,
            'payment_date' => $p->payment_date?->toDateString(),
            'notes' => $p->notes,
            'by' => $p->creator?->name,
        ]);

        return response()->json(['data' => $data]);
    }

    /** Record a payment against the invoice and roll its status forward. */
    public function recordPayment(Request $request, Invoice $invoice)
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'mode' => ['required', Rule::in(['cash', 'card', 'upi', 'credit'])],
            'payment_date' => ['required', 'date'],
            'notes' => ['nullable', 'string'],
        ]);

        if ((float) $data['amount'] > $invoice->balanceDue() + 0.005) {
            throw ValidationException::withMessages([
                'amount' => ["Payment exceeds the balance due of {$invoice->balanceDue()}."],
            ]);
        }

        $invoice->payments()->create([
            'amount' => $data['amount'],
            'mode' => $data['mode'],
            'payment_date' => $data['payment_date'],
            'notes' => $data['notes'] ?? null,
            'created_by' => $request->user()?->id,
        ]);

        $invoice->paid_amount = round((float) $invoice->paid_amount + (float) $data['amount'], 2);
        $invoice->refreshStatus();

        return response()->json(['data' => $this->payload($invoice->fresh(['customer:id,name', 'salesOrder:id,so_number']))]);
    }

    private function payload(Invoice $inv): array
    {
        return [
            'id' => $inv->id,
            'invoice_number' => $inv->invoice_number,
            'sales_order_id' => $inv->sales_order_id,
            'so_number' => $inv->salesOrder?->so_number,
            'customer_id' => $inv->customer_id,
            'customer' => $inv->customer ? ['id' => $inv->customer->id, 'name' => $inv->customer->name] : null,
            'invoice_date' => $inv->invoice_date?->toDateString(),
            'total' => $inv->total,
            'paid_amount' => $inv->paid_amount,
            'balance_due' => $inv->balanceDue(),
            'status' => $inv->status,
            'created_at' => $inv->created_at,
        ];
    }
}
