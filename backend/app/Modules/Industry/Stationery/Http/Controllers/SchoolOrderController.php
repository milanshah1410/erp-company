<?php

declare(strict_types=1);

namespace App\Modules\Industry\Stationery\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\SalesOrder;
use App\Models\SalesQuotation;
use App\Modules\Industry\Stationery\Models\Institution;
use App\Modules\Industry\Stationery\Services\ClassKitSupplyService;
use Illuminate\Http\Request;

/**
 * The school-supply board — institutional orders and the tenders behind them,
 * read as a season rather than as a list of documents.
 *
 * There is deliberately no "school order" document here. A school buying five
 * hundred diaries is a sale: it is a `SalesOrder` on the `institution` channel,
 * and it is picked, part-delivered, invoiced and chased by code that already
 * works. What did not exist is the *view* — the shop's question in June is not
 * "show me order SO-000412", it is "which schools have ordered, which are still
 * on a quotation, whose purchase-order number is missing, and how much is
 * outstanding". That question is a projection, so this controller is a projection
 * and owns no table.
 *
 * The one thing it does write is the purchase-order reference, because that lives
 * on the order and nothing on the core sales screen knows to ask for it.
 */
class SchoolOrderController extends Controller
{
    public function __construct(
        private readonly ClassKitSupplyService $supply,
    ) {}

    public function index(Request $request)
    {
        $query = SalesOrder::query()
            ->where('channel', 'institution')
            ->with([
                'customer:id,code,name,phone',
                'stationeryInstitution:id,code,name,board,requires_po',
                'stationeryClassKit:id,kit_number,class_label,stream,academic_year',
                'invoice:id,sales_order_id,invoice_number,status,total,paid_amount',
            ]);

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('so_number', 'like', "%{$search}%")
                    ->orWhere('stn_po_number', 'like', "%{$search}%")
                    ->orWhereHas('stationeryInstitution', fn ($i) => $i->where('name', 'like', "%{$search}%"));
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }
        if ($request->filled('institution_id')) {
            $query->where('stn_institution_id', $request->query('institution_id'));
        }

        /**
         * The chase list. An order for a school that demands a purchase-order
         * number, raised without one, is a bill that will not be paid — and the
         * only moment it is cheap to fix is before the goods leave.
         */
        if ($request->boolean('missing_po')) {
            $query->whereNull('stn_po_number')
                ->whereHas('stationeryInstitution', fn ($i) => $i->where('requires_po', true));
        }

        $query->orderByDesc('id');

        return response()->json(
            $query->paginate(min((int) $request->query('per_page', 25), 100))
                ->through(fn (SalesOrder $o) => $this->row($o))->toArray()
            + ['summary' => $this->summary()],
        );
    }

    /**
     * Tenders still out — quotations to institutions that have not become orders.
     *
     * Separate from the order list because they are a different job: an order is
     * work to do, a quotation is somebody to ring.
     */
    public function quotations(Request $request)
    {
        $query = SalesQuotation::query()
            ->whereNotNull('stn_institution_id')
            ->with([
                'customer:id,code,name',
                'stationeryInstitution:id,code,name',
                'stationeryClassKit:id,kit_number,class_label,academic_year',
            ]);

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        } else {
            // Default to the ones still live — a converted tender is history.
            $query->whereIn('status', ['draft', 'sent', 'accepted']);
        }

        if ($request->filled('institution_id')) {
            $query->where('stn_institution_id', $request->query('institution_id'));
        }

        $query->orderByDesc('id');

        return response()->json(
            $query->paginate(min((int) $request->query('per_page', 25), 100))
                ->through(fn (SalesQuotation $q) => [
                    'id' => $q->id,
                    'quote_number' => $q->quote_number,
                    'quote_date' => $q->quote_date?->toDateString(),
                    'valid_until' => $q->valid_until?->toDateString(),
                    'status' => $q->status,
                    'total' => (float) $q->total,
                    'customer' => $q->customer?->name,
                    'institution_id' => $q->stn_institution_id,
                    'institution' => $q->stationeryInstitution?->name,
                    'kit' => $q->stationeryClassKit?->class_label,
                    'academic_year' => $q->stationeryClassKit?->academic_year,
                    'sales_order_id' => $q->sales_order_id,
                ])->toArray(),
        );
    }

    /**
     * Record the school's purchase-order reference against an order.
     *
     * Its own endpoint because it happens *after* the order — the school's
     * accounts office issues the PO days later, and until it arrives the order is
     * real and the bill is unpayable. Allowed at any status for the same reason:
     * refusing it once the goods have gone would leave the shop with an order it
     * can never get paid for.
     */
    public function recordPo(Request $request, SalesOrder $salesOrder)
    {
        if ($salesOrder->channel !== 'institution') {
            return response()->json(['message' => 'That order is not a school supply order.'], 422);
        }

        $data = $request->validate([
            'po_number' => ['required', 'string', 'max:60'],
            'po_date' => ['nullable', 'date'],
        ]);

        $salesOrder->forceFill([
            'stn_po_number' => $data['po_number'],
            'stn_po_date' => $data['po_date'] ?? null,
        ])->save();

        return response()->json(['data' => $this->row($salesOrder->fresh([
            'customer', 'stationeryInstitution', 'stationeryClassKit', 'invoice',
        ]))]);
    }

    /**
     * The season's buying list: every published booklist rolled up by product and
     * netted against stock.
     *
     * This is the number the trade's whole year turns on. Short in May means
     * turning away the customers who pay for the year; long in July means a
     * godown of Class 7 books nobody will ever ask for again. It sits behind the
     * booklist permission rather than purchase's, because reading it is reading
     * the lists — acting on it is a purchase order, raised on the purchase screen
     * by whoever holds that authority.
     */
    public function seasonDemand(Request $request)
    {
        $year = (string) $request->query(
            'academic_year',
            Institution::query()->where('is_active', true)->orderByDesc('id')->value('academic_year') ?? '',
        );

        if ($year === '') {
            return response()->json(['data' => [], 'academic_year' => null, 'summary' => [
                'lines' => 0, 'to_buy' => 0,
            ]]);
        }

        $rows = $this->supply->seasonDemand(
            $year,
            $request->filled('institution_id') ? (int) $request->query('institution_id') : null,
        );

        return response()->json([
            'data' => $rows,
            'academic_year' => $year,
            'summary' => [
                'lines' => count($rows),
                'to_buy' => count(array_filter($rows, static fn (array $r) => $r['to_buy'] > 0)),
            ],
        ]);
    }

    private function summary(): array
    {
        $base = SalesOrder::query()->where('channel', 'institution');

        return [
            'orders' => (clone $base)->count(),
            'open' => (clone $base)->whereNotIn('status', ['delivered', 'cancelled'])->count(),
            'value' => round((float) (clone $base)->where('status', '!=', 'cancelled')->sum('total'), 2),
            /**
             * Orders that cannot be paid until somebody chases a piece of paper.
             * On the summary rather than buried behind a filter because it is
             * the number that quietly becomes the shop's September problem.
             */
            'missing_po' => (clone $base)
                ->whereNull('stn_po_number')
                ->whereHas('stationeryInstitution', fn ($i) => $i->where('requires_po', true))
                ->count(),
        ];
    }

    private function row(SalesOrder $o): array
    {
        $institution = $o->stationeryInstitution;

        return [
            'id' => $o->id,
            'so_number' => $o->so_number,
            'order_date' => $o->order_date?->toDateString(),
            'status' => $o->status,
            'total' => (float) $o->total,
            'customer_id' => $o->customer_id,
            'customer' => $o->customer?->name,
            'institution_id' => $o->stn_institution_id,
            'institution' => $institution?->name,
            'board' => $institution?->board,
            'kit_id' => $o->stn_class_kit_id,
            'kit' => $o->stationeryClassKit
                ? trim($o->stationeryClassKit->class_label.' '.(string) $o->stationeryClassKit->stream)
                : null,
            'academic_year' => $o->stationeryClassKit?->academic_year,
            'po_number' => $o->stn_po_number,
            'po_date' => $o->stn_po_date?->toDateString(),
            // Surfaced per row, not only in the summary: this is the flag that
            // decides whether the delivery van should leave.
            'po_missing' => (bool) $institution?->requires_po && ! $o->stn_po_number,
            'invoice_number' => $o->invoice?->invoice_number,
            'invoice_status' => $o->invoice?->status,
            'balance_due' => $o->invoice
                ? round((float) $o->invoice->total - (float) $o->invoice->paid_amount, 2)
                : null,
        ];
    }
}
