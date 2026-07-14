<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ExportsReports;
use App\Http\Controllers\Controller;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseInvoiceItem;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use Illuminate\Http\Request;

class PurchaseReportController extends Controller
{
    use ExportsReports;

    private const EXPORT_LIMIT = 10000;

    /** Purchase orders with totals, filterable by supplier/date/status. */
    public function summary(Request $request)
    {
        $query = PurchaseOrder::query()->with(['supplier:id,name', 'warehouse:id,name']);

        if ($request->filled('supplier_id')) {
            $query->where('supplier_id', $request->query('supplier_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }
        if ($request->filled('date_from')) {
            $query->whereDate('order_date', '>=', $request->query('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('order_date', '<=', $request->query('date_to'));
        }

        $query->orderByDesc('order_date');

        $map = fn (PurchaseOrder $po) => [
            'po_number' => $po->po_number,
            'supplier' => $po->supplier?->name,
            'warehouse' => $po->warehouse?->name,
            'order_date' => $po->order_date?->toDateString(),
            'status' => $po->status,
            'subtotal' => (float) $po->subtotal,
            'tax_amount' => (float) $po->tax_amount,
            'total' => (float) $po->total,
        ];

        $columns = [
            'po_number' => 'PO Number', 'supplier' => 'Supplier', 'warehouse' => 'Warehouse',
            'order_date' => 'Order Date', 'status' => 'Status',
            'subtotal' => 'Subtotal', 'tax_amount' => 'Tax', 'total' => 'Total',
        ];

        if ($request->query('format', 'json') === 'json') {
            $grandTotal = (float) (clone $query)->sum('total');
            $perPage = min((int) $request->query('per_page', 20), 200);
            $page = $query->paginate($perPage);
            $page->getCollection()->transform($map);

            return response()->json(['data' => $page->items(), 'meta' => [
                'current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(),
                'grand_total' => round($grandTotal, 2),
            ]]);
        }

        $rows = $query->limit(self::EXPORT_LIMIT)->get()->map($map)->all();

        return $this->exportResponse($request, $rows, $columns, 'purchase-report');
    }

    /**
     * Per-supplier performance: order volume/value and on-time delivery,
     * based on the earliest goods receipt against each PO's expected date.
     */
    public function vendorPerformance(Request $request)
    {
        $query = Supplier::query()->with(['purchaseOrders.goodsReceipts']);

        if ($request->filled('supplier_id')) {
            $query->where('id', $request->query('supplier_id'));
        }

        $rows = $query->get()->map(function (Supplier $supplier) {
            $pos = $supplier->purchaseOrders;
            $withExpected = $pos->filter(fn ($po) => $po->expected_date && $po->goodsReceipts->isNotEmpty());

            $onTime = $withExpected->filter(function ($po) {
                $firstReceived = $po->goodsReceipts->min('received_date');

                return $firstReceived && $firstReceived->lte($po->expected_date);
            });

            $leadTimes = $withExpected->map(function ($po) {
                $firstReceived = $po->goodsReceipts->min('received_date');

                return $firstReceived ? $po->order_date->diffInDays($firstReceived) : null;
            })->filter(fn ($d) => $d !== null);

            return [
                'supplier' => $supplier->name,
                'total_pos' => $pos->count(),
                'total_value' => (float) $pos->sum('total'),
                'on_time_deliveries' => $onTime->count(),
                'tracked_deliveries' => $withExpected->count(),
                'on_time_rate' => $withExpected->count() > 0 ? round($onTime->count() / $withExpected->count() * 100, 1) : null,
                'avg_lead_time_days' => $leadTimes->isNotEmpty() ? round($leadTimes->avg(), 1) : null,
            ];
        })->filter(fn ($r) => $r['total_pos'] > 0)->sortByDesc('total_value')->values()->all();

        $columns = [
            'supplier' => 'Supplier', 'total_pos' => 'Total POs', 'total_value' => 'Total Value',
            'on_time_deliveries' => 'On-Time Deliveries', 'tracked_deliveries' => 'Tracked Deliveries',
            'on_time_rate' => 'On-Time Rate %', 'avg_lead_time_days' => 'Avg Lead Time (days)',
        ];

        return $this->exportResponse($request, $rows, $columns, 'vendor-performance');
    }

    /** GST input tax summary from vendor invoices, grouped by HSN code and tax rate. */
    public function gstInput(Request $request)
    {
        $query = PurchaseInvoiceItem::query()
            ->join('products', 'products.id', '=', 'purchase_invoice_items.product_id')
            ->join('purchase_invoices', 'purchase_invoices.id', '=', 'purchase_invoice_items.purchase_invoice_id')
            ->selectRaw("COALESCE(products.hsn_code, 'N/A') as hsn_code, purchase_invoice_items.tax_percent,
                SUM(purchase_invoice_items.line_total - purchase_invoice_items.tax_amount) as taxable_value,
                SUM(purchase_invoice_items.tax_amount) as tax_amount")
            ->groupBy('hsn_code', 'purchase_invoice_items.tax_percent');

        if ($request->filled('date_from')) {
            $query->whereDate('purchase_invoices.invoice_date', '>=', $request->query('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('purchase_invoices.invoice_date', '<=', $request->query('date_to'));
        }

        $rows = $query->orderBy('hsn_code')->get()->map(fn ($r) => [
            'hsn_code' => $r->hsn_code,
            'tax_percent' => (float) $r->tax_percent,
            'taxable_value' => round((float) $r->taxable_value, 2),
            'tax_amount' => round((float) $r->tax_amount, 2),
        ])->all();

        $columns = [
            'hsn_code' => 'HSN Code', 'tax_percent' => 'Tax %',
            'taxable_value' => 'Taxable Value', 'tax_amount' => 'Tax Amount',
        ];

        return $this->exportResponse($request, $rows, $columns, 'gst-input', [
            'total_tax' => round((float) collect($rows)->sum('tax_amount'), 2),
        ]);
    }

    /**
     * Pending payment (accounts payable): supplier-wise outstanding on approved,
     * unpaid/partial vendor invoices, bucketed by age against the due date
     * (falling back to the invoice date). Buckets: 0-30, 31-60, 61-90, 90+.
     */
    public function payables(Request $request)
    {
        $today = now()->startOfDay();

        $invoices = PurchaseInvoice::query()
            ->with('supplier:id,code,name,gst_number')
            ->where('status', 'approved')
            ->whereIn('payment_status', ['unpaid', 'partial'])
            ->get(['id', 'supplier_id', 'invoice_date', 'due_date', 'total', 'paid_amount']);

        $bySupplier = [];
        foreach ($invoices as $inv) {
            $balance = round((float) $inv->total - (float) $inv->paid_amount, 2);
            if ($balance <= 0) {
                continue;
            }

            $ref = $inv->due_date ?? $inv->invoice_date;
            $ageDays = $ref ? $ref->startOfDay()->diffInDays($today, false) : 0;
            $bucket = $ageDays <= 30 ? 'bucket_0_30'
                : ($ageDays <= 60 ? 'bucket_31_60'
                : ($ageDays <= 90 ? 'bucket_61_90' : 'bucket_90_plus'));

            $sid = $inv->supplier_id;
            if (! isset($bySupplier[$sid])) {
                $bySupplier[$sid] = [
                    'supplier' => $inv->supplier?->name,
                    'code' => $inv->supplier?->code,
                    'gst_number' => $inv->supplier?->gst_number,
                    'bucket_0_30' => 0.0, 'bucket_31_60' => 0.0,
                    'bucket_61_90' => 0.0, 'bucket_90_plus' => 0.0,
                    'total_outstanding' => 0.0,
                ];
            }
            $bySupplier[$sid][$bucket] = round($bySupplier[$sid][$bucket] + $balance, 2);
            $bySupplier[$sid]['total_outstanding'] = round($bySupplier[$sid]['total_outstanding'] + $balance, 2);
        }

        $rows = collect($bySupplier)->sortByDesc('total_outstanding')->values()->all();

        $columns = [
            'code' => 'Code', 'supplier' => 'Supplier', 'gst_number' => 'GSTIN',
            'bucket_0_30' => '0-30 Days', 'bucket_31_60' => '31-60 Days',
            'bucket_61_90' => '61-90 Days', 'bucket_90_plus' => '90+ Days',
            'total_outstanding' => 'Total Outstanding',
        ];

        return $this->exportResponse($request, $rows, $columns, 'payables', [
            'total_outstanding' => round((float) collect($rows)->sum('total_outstanding'), 2),
        ]);
    }
}
