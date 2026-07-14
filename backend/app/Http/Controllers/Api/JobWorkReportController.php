<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ExportsReports;
use App\Http\Controllers\Controller;
use App\Models\ColourPrinting;
use App\Models\EmbroideryWork;
use App\Models\TailorWork;
use Illuminate\Http\Request;

/**
 * Reports for the three outside-processing streams: in-house tailors
 * (TailorWork), embroidery vendors (EmbroideryWork) and colour printers
 * (ColourPrinting). Each is a filterable detail list with a cost/amount total.
 */
class JobWorkReportController extends Controller
{
    use ExportsReports;

    private const EXPORT_LIMIT = 10000;

    /** Tailor work orders: issued vs completed pieces and the piece-rate amount earned. */
    public function tailor(Request $request)
    {
        $query = TailorWork::query()->with(['tailor:id,name', 'product:id,sku,name']);

        if ($request->filled('tailor_id')) {
            $query->where('tailor_id', $request->query('tailor_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }
        if ($request->filled('date_from')) {
            $query->whereDate('issue_date', '>=', $request->query('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('issue_date', '<=', $request->query('date_to'));
        }

        $query->orderByDesc('issue_date')->orderByDesc('id');

        $map = fn (TailorWork $w) => [
            'work_number' => $w->work_number,
            'tailor' => $w->tailor?->name,
            'product' => $w->product?->name,
            'issued_qty' => (float) $w->issued_qty,
            'completed_qty' => (float) $w->completed_qty,
            'pending_qty' => $w->remainingQty(),
            'rate' => (float) $w->rate,
            'amount' => round((float) $w->completed_qty * (float) $w->rate, 2),
            'status' => $w->status,
            'issue_date' => $w->issue_date?->toDateString(),
        ];

        $columns = [
            'work_number' => 'Work No', 'tailor' => 'Tailor', 'product' => 'Product',
            'issued_qty' => 'Issued', 'completed_qty' => 'Completed', 'pending_qty' => 'Pending',
            'rate' => 'Rate', 'amount' => 'Amount', 'status' => 'Status', 'issue_date' => 'Issue Date',
        ];

        return $this->listOrExport($request, $query, $map, $columns, 'tailor-report', 'work_number', fn ($rows) => [
            'grand_total' => round((float) collect($rows)->sum('amount'), 2),
        ]);
    }

    /** Embroidery work: pieces dispatched to and received from each vendor, plus cost. */
    public function embroidery(Request $request)
    {
        $query = EmbroideryWork::query()->with(['vendor:id,name', 'product:id,sku,name']);

        if ($request->filled('embroidery_vendor_id')) {
            $query->where('embroidery_vendor_id', $request->query('embroidery_vendor_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }
        if ($request->filled('date_from')) {
            $query->whereDate('dispatch_date', '>=', $request->query('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('dispatch_date', '<=', $request->query('date_to'));
        }

        $query->orderByDesc('dispatch_date')->orderByDesc('id');

        $map = fn (EmbroideryWork $w) => [
            'work_number' => $w->work_number,
            'vendor' => $w->vendor?->name,
            'product' => $w->product?->name,
            'design_number' => $w->design_number,
            'dispatched_qty' => (float) $w->dispatched_qty,
            'received_qty' => (float) $w->received_qty,
            'pending_qty' => $w->pendingQty(),
            'cost' => (float) $w->cost,
            'status' => $w->status,
            'dispatch_date' => $w->dispatch_date?->toDateString(),
        ];

        $columns = [
            'work_number' => 'Work No', 'vendor' => 'Vendor', 'product' => 'Product',
            'design_number' => 'Design', 'dispatched_qty' => 'Dispatched', 'received_qty' => 'Received',
            'pending_qty' => 'Pending', 'cost' => 'Cost', 'status' => 'Status', 'dispatch_date' => 'Dispatch Date',
        ];

        return $this->listOrExport($request, $query, $map, $columns, 'embroidery-report', 'work_number', fn ($rows) => [
            'grand_total' => round((float) collect($rows)->sum('cost'), 2),
        ]);
    }

    /** Colour-printing jobs sent to external printers, grouped-ready detail list with cost. */
    public function printing(Request $request)
    {
        $query = ColourPrinting::query()->with('printerVendor:id,name');

        if ($request->filled('printer_vendor_id')) {
            $query->where('printer_vendor_id', $request->query('printer_vendor_id'));
        }
        if ($request->filled('print_type')) {
            $query->where('print_type', $request->query('print_type'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->query('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->query('date_to'));
        }

        $query->orderByDesc('id');

        $map = fn (ColourPrinting $p) => [
            'id' => $p->id,
            'vendor' => $p->printerVendor?->name,
            'print_type' => $p->print_type,
            'screen_count' => (int) $p->screen_count,
            'colour_count' => (int) $p->colour_count,
            'cost' => (float) $p->cost,
            'status' => $p->status,
            'date' => $p->created_at?->toDateString(),
        ];

        $columns = [
            'id' => 'ID', 'vendor' => 'Printer', 'print_type' => 'Print Type',
            'screen_count' => 'Screens', 'colour_count' => 'Colours',
            'cost' => 'Cost', 'status' => 'Status', 'date' => 'Date',
        ];

        return $this->listOrExport($request, $query, $map, $columns, 'printing-report', 'id', fn ($rows) => [
            'grand_total' => round((float) collect($rows)->sum('cost'), 2),
        ]);
    }

    /**
     * Shared responder: paginated JSON (with a computed meta) for on-screen use,
     * or the full result set through the export pipeline. The $meta closure is
     * given the mapped page/export rows so it can total a computed column.
     */
    private function listOrExport(Request $request, $query, callable $map, array $columns, string $filename, string $dataKey, callable $meta)
    {
        if ($request->query('format', 'json') === 'json') {
            $perPage = min((int) $request->query('per_page', 20), 200);
            $page = $query->paginate($perPage);
            // Total the computed amount/cost column across the whole filtered set.
            $allRows = (clone $query)->limit(self::EXPORT_LIMIT)->get()->map($map)->all();
            $page->getCollection()->transform($map);

            return response()->json(['data' => $page->items(), 'meta' => array_merge([
                'current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(),
            ], $meta($allRows))]);
        }

        $rows = $query->limit(self::EXPORT_LIMIT)->get()->map($map)->all();

        return $this->exportResponse($request, $rows, $columns, $filename, $meta($rows));
    }
}
