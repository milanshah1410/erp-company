<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SupplierRequest;
use App\Http\Resources\PurchaseOrderResource;
use App\Http\Resources\SupplierResource;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use Illuminate\Http\Request;

class SupplierController extends Controller
{
    public function index(Request $request)
    {
        $query = Supplier::query();

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('company_name', 'like', "%{$search}%");
            });
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $sort = $request->query('sort', 'created_at');
        $direction = $request->query('direction', 'desc');
        if (in_array($sort, ['name', 'code', 'created_at'], true)) {
            $query->orderBy($sort, $direction === 'asc' ? 'asc' : 'desc');
        }

        $perPage = min((int) $request->query('per_page', 15), 100);

        return SupplierResource::collection($query->paginate($perPage));
    }

    public function store(SupplierRequest $request)
    {
        $supplier = Supplier::create($request->validated());

        return (new SupplierResource($supplier))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Supplier $supplier)
    {
        return new SupplierResource($supplier);
    }

    public function update(SupplierRequest $request, Supplier $supplier)
    {
        $supplier->update($request->validated());

        return new SupplierResource($supplier);
    }

    public function destroy(Supplier $supplier)
    {
        if ($supplier->purchaseOrders()->exists()) {
            return response()->json([
                'message' => 'This supplier has purchase orders and cannot be deleted. Mark it inactive instead.',
            ], 422);
        }
        $supplier->delete();

        return response()->json(['message' => 'Supplier deleted.']);
    }

    /**
     * Purchase history for a supplier: paginated orders plus a summary
     * block (order counts, lifetime value, pending exposure).
     */
    public function purchaseHistory(Request $request, Supplier $supplier)
    {
        $base = PurchaseOrder::query()->where('supplier_id', $supplier->id);

        $summary = [
            'total_orders' => (clone $base)->count(),
            'total_value' => (float) (clone $base)->whereNot('status', 'cancelled')->sum('total'),
            'open_orders' => (clone $base)->whereIn('status', ['draft', 'pending_approval', 'approved', 'partially_received'])->count(),
            'open_value' => (float) (clone $base)->whereIn('status', ['draft', 'pending_approval', 'approved', 'partially_received'])->sum('total'),
            'last_order_date' => (clone $base)->max('order_date'),
            'credit_limit' => (float) $supplier->credit_limit,
            'payment_terms_days' => (int) $supplier->payment_terms_days,
        ];

        $query = $base->with(['warehouse:id,name'])->withCount('goodsReceipts');
        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }
        $query->orderByDesc('id');
        $perPage = min((int) $request->query('per_page', 15), 100);

        return PurchaseOrderResource::collection($query->paginate($perPage))
            ->additional(['summary' => $summary]);
    }

    /**
     * Supplier ledger: a chronological, running-balance payables statement
     * built from the opening balance, approved purchase invoices (bills that
     * raise the payable) and payments (that reduce it). Optional ?from / ?to.
     */
    public function ledger(Request $request, Supplier $supplier)
    {
        $from = $request->query('from');
        $to = $request->query('to');
        $dateWindow = fn ($q, string $column) => $q
            ->when($from, fn ($qq) => $qq->whereDate($column, '>=', $from))
            ->when($to, fn ($qq) => $qq->whereDate($column, '<=', $to));

        $entries = [];

        // Approved vendor invoices raise the payable (debit).
        $dateWindow($supplier->purchaseInvoices()->where('status', 'approved'), 'invoice_date')
            ->get(['id', 'invoice_number', 'invoice_date', 'total'])
            ->each(function ($inv) use (&$entries) {
                $entries[] = [
                    'date' => optional($inv->invoice_date)->toDateString(),
                    'type' => 'invoice',
                    'reference' => $inv->invoice_number,
                    'description' => 'Purchase Invoice',
                    'debit' => (float) $inv->total,
                    'credit' => 0.0,
                ];
            });

        // Payments reduce the payable (credit).
        $dateWindow(
            SupplierPayment::query()
                ->whereIn('purchase_invoice_id', $supplier->purchaseInvoices()->select('id'))
                ->with('purchaseInvoice:id,invoice_number'),
            'payment_date'
        )->get(['id', 'purchase_invoice_id', 'amount', 'mode', 'payment_date'])
            ->each(function ($pay) use (&$entries) {
                $entries[] = [
                    'date' => optional($pay->payment_date)->toDateString(),
                    'type' => 'payment',
                    'reference' => $pay->purchaseInvoice?->invoice_number,
                    'description' => 'Payment ('.$pay->mode.')',
                    'debit' => 0.0,
                    'credit' => (float) $pay->amount,
                ];
            });

        // Chronological order; entries without a date float to the top.
        usort($entries, fn ($a, $b) => ($a['date'] ?? '') <=> ($b['date'] ?? ''));

        $opening = (float) $supplier->opening_balance;
        $running = $opening;
        foreach ($entries as &$entry) {
            $running = round($running + $entry['debit'] - $entry['credit'], 2);
            $entry['balance'] = $running;
        }
        unset($entry);

        if (! $from) {
            array_unshift($entries, [
                'date' => optional($supplier->created_at)->toDateString(),
                'type' => 'opening',
                'reference' => null,
                'description' => 'Opening Balance',
                'debit' => $opening,
                'credit' => 0.0,
                'balance' => $opening,
            ]);
        }

        $totalDebit = round(array_sum(array_column($entries, 'debit')), 2);
        $totalCredit = round(array_sum(array_column($entries, 'credit')), 2);

        return response()->json([
            'data' => $entries,
            'summary' => [
                'opening_balance' => $opening,
                'total_debit' => $totalDebit,
                'total_credit' => $totalCredit,
                'closing_balance' => $running,
                'outstanding_balance' => $supplier->outstandingBalance(),
            ],
        ]);
    }

    /**
     * Payment history: every payment recorded against this supplier's
     * purchase invoices, most recent first, with a totals summary.
     */
    public function paymentHistory(Request $request, Supplier $supplier)
    {
        $base = SupplierPayment::query()
            ->whereIn('purchase_invoice_id', $supplier->purchaseInvoices()->select('id'));

        $summary = [
            'total_paid' => (float) (clone $base)->sum('amount'),
            'payment_count' => (clone $base)->count(),
            'last_payment_date' => (clone $base)->max('payment_date'),
        ];

        $query = (clone $base)->with('purchaseInvoice:id,invoice_number');
        if ($request->filled('mode')) {
            $query->where('mode', $request->query('mode'));
        }
        $query->orderByDesc('payment_date')->orderByDesc('id');

        $perPage = min((int) $request->query('per_page', 15), 100);
        $payments = $query->paginate($perPage);

        $payments->getCollection()->transform(fn ($p) => [
            'id' => $p->id,
            'purchase_invoice_id' => $p->purchase_invoice_id,
            'invoice_number' => $p->purchaseInvoice?->invoice_number,
            'amount' => (float) $p->amount,
            'mode' => $p->mode,
            'payment_date' => optional($p->payment_date)->toDateString(),
            'notes' => $p->notes,
        ]);

        return response()->json([
            'data' => $payments->items(),
            'meta' => [
                'current_page' => $payments->currentPage(),
                'last_page' => $payments->lastPage(),
                'per_page' => $payments->perPage(),
                'total' => $payments->total(),
            ],
            'summary' => $summary,
        ]);
    }
}
