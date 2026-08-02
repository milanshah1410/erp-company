<?php

declare(strict_types=1);

namespace App\Modules\Industry\Grocery\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseOrder;
use App\Models\Setting;
use App\Models\Supplier;
use App\Modules\Industry\Grocery\Services\SupplierCreditService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Supplier Credit — the khata, from the side that owes.
 *
 * The book a kirana owner reads before opening the shutter: who is owed what,
 * which bills are late, whose limit is nearly used up, and what has to be paid
 * today. The facts were all in the system and none of them were in one place —
 * the limit sat unread on the supplier master, the terms sat unread beside it,
 * open bills lived on the vendor-invoice list and goods already ordered against
 * that credit were only visible on the purchase-order list.
 *
 * Deliberately **not** a mirror of Credit Control. There is no hold and nothing
 * is ever blocked: a shop does not get to stop its own distributor. The screen's
 * only lever is the two numbers the shop actually knows — the limit the
 * distributor has granted and the days it allows — which are edited here rather
 * than only on the supplier master, because you record a limit while looking at
 * what is already owed against it, not while editing a pincode.
 *
 * Figures are assembled in PHP over three queries rather than aggregated in SQL,
 * unlike the receivables screen. That is a deliberate trade: the due date a bill
 * is aged against is often *projected* from the supplier's terms rather than
 * stored on the bill, and expressing that projection in SQL would give the list
 * one ageing rule and the drill-down another. A supplier master is tens of rows
 * where a distributor's customer master is thousands, so the cost is affordable
 * and the agreement is worth more.
 */
class SupplierCreditController extends Controller
{
    /** Statuses the list can be filtered to, in escalation order. */
    private const STATUSES = ['over_limit', 'overdue', 'watch', 'ok'];

    public function __construct(private readonly SupplierCreditService $credit) {}

    /**
     * The supplier list with live exposure, plus totals over the whole filtered
     * set — an owner needs the book's total, and a paginated summary would give
     * them the page's.
     */
    public function index(Request $request)
    {
        $request->validate([
            'status' => ['nullable', Rule::in(self::STATUSES)],
            'sort' => ['nullable', Rule::in(['exposure', 'overdue', 'available', 'name', 'due'])],
            'dir' => ['nullable', Rule::in(['asc', 'desc'])],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $asOf = Carbon::now()->startOfDay();
        $rows = $this->rows($request, $asOf);

        $summary = [
            'suppliers' => count($rows),
            'outstanding' => round(array_sum(array_column($rows, 'outstanding')), 2),
            'overdue' => round(array_sum(array_column($rows, 'overdue')), 2),
            'pipeline' => round(array_sum(array_column($rows, 'pipeline')), 2),
            'due_today' => round(array_sum(array_column($rows, 'due_today')), 2),
            'over_limit' => count(array_filter($rows, fn ($r) => $r['status'] === 'over_limit')),
        ];

        if ($status = (string) $request->query('status', '')) {
            $rows = array_values(array_filter($rows, fn ($r) => $r['status'] === $status));
        }

        /**
         * By default only suppliers with something at stake — money owed, goods
         * on the way, or a limit that could be breached. Everyone else is a name
         * and three zeroes, and burying the six distributors that matter under
         * two hundred of those is how a screen stops being read.
         */
        if (! $request->boolean('include_clear')) {
            $rows = array_values(array_filter(
                $rows,
                fn ($r) => $r['exposure'] > 0 || $r['credit_limit'] > 0,
            ));
        }

        $dir = $request->query('dir', 'desc') === 'asc' ? 1 : -1;
        $sort = (string) $request->query('sort', 'exposure');

        usort($rows, function (array $a, array $b) use ($sort, $dir) {
            return $dir * match ($sort) {
                'overdue' => $a['overdue'] <=> $b['overdue'],
                // Nulls are "no limit set" and belong at the far end either way.
                'available' => ($a['available'] ?? PHP_FLOAT_MAX) <=> ($b['available'] ?? PHP_FLOAT_MAX),
                'due' => strcmp((string) $a['next_due_date'], (string) $b['next_due_date']),
                'name' => strcmp((string) $a['supplier'], (string) $b['supplier']),
                default => $a['exposure'] <=> $b['exposure'],
            };
        });

        return response()->json([
            'data' => $rows,
            'meta' => [
                'as_of' => $asOf->toDateString(),
                'summary' => $summary,
                'default_terms_days' => $this->credit->defaultTermsDays(),
                'statuses' => self::STATUSES,
            ],
        ]);
    }

    /** One supplier in full: standing, bill-wise ageing, and what is on the way. */
    public function show(Supplier $supplier)
    {
        return response()->json([
            'data' => [
                'snapshot' => $this->credit->snapshot($supplier),
                'ageing' => $this->credit->ageing($supplier),
                /**
                 * Approved orders with no bill against them yet. This is the
                 * reason a supplier can look clear on the ledger and still be at
                 * their limit: the goods are already booked against the shop's
                 * credit at the distributor's end.
                 */
                'open_orders' => PurchaseOrder::query()
                    ->where('supplier_id', $supplier->id)
                    ->whereIn('status', ['approved', 'partially_received', 'received'])
                    ->whereNotIn('id', PurchaseInvoice::query()
                        ->whereNotNull('purchase_order_id')
                        ->where('status', '!=', 'cancelled')
                        ->select('purchase_order_id'))
                    ->orderBy('order_date')
                    ->get(['id', 'po_number', 'order_date', 'status', 'total'])
                    ->map(fn (PurchaseOrder $po) => [
                        'id' => $po->id,
                        'po_number' => $po->po_number,
                        'order_date' => $po->order_date?->toDateString(),
                        'status' => $po->status,
                        'total' => round((float) $po->total, 2),
                    ]),
            ],
        ]);
    }

    /**
     * This supplier's credit terms — the limit they extend and the days they
     * allow. The only thing this screen writes.
     */
    public function updateTerms(Request $request, Supplier $supplier)
    {
        $data = $request->validate([
            'credit_limit' => ['required', 'numeric', 'min:0', 'max:999999999.99'],
            // 0 = follow the workspace default rather than "due immediately",
            // which is what an empty field means to the person typing it.
            'payment_terms_days' => ['required', 'integer', 'min:0', 'max:365'],
        ]);

        $supplier->update($data);

        return response()->json(['data' => $this->credit->snapshot($supplier->refresh())]);
    }

    /**
     * The morning's payment list: every bill falling due inside the horizon,
     * across every supplier, oldest first.
     *
     * This is the screen's real output. Everything above it explains *why* a bill
     * is on this list; this is the list somebody works down with a cheque book.
     */
    public function paymentPlan(Request $request)
    {
        $data = $request->validate([
            'days' => ['nullable', 'integer', 'min:0', 'max:120'],
        ]);

        $days = (int) ($data['days'] ?? 7);
        $rows = $this->credit->paymentPlan($days);

        return response()->json([
            'data' => $rows,
            'meta' => [
                'days' => $days,
                'as_of' => Carbon::now()->toDateString(),
                'total' => round(array_sum(array_column($rows, 'balance')), 2),
                // Already late, as against merely falling due inside the horizon.
                'overdue_total' => round(array_sum(array_map(
                    fn (array $r) => $r['days_past_due'] > 0 ? $r['balance'] : 0.0,
                    $rows,
                )), 2),
            ],
        ]);
    }

    /**
     * The workspace's default purchase credit period, used for suppliers whose
     * own terms are unrecorded. Lives on this screen for the same reason the
     * receivables policy lives on Credit Control: "30 days" means nothing until
     * you can see whose bills it dates.
     */
    public function updateDefaultTerms(Request $request)
    {
        $data = $request->validate([
            'default_terms_days' => ['required', 'integer', 'min:0', 'max:365'],
        ]);

        Setting::set('purchase_payment_term_days', (string) $data['default_terms_days']);

        return response()->json(['data' => ['default_terms_days' => $this->credit->defaultTermsDays()]]);
    }

    /**
     * Every supplier's standing, assembled from three queries.
     *
     * @return list<array<string, mixed>>
     */
    private function rows(Request $request, Carbon $asOf): array
    {
        $suppliers = Supplier::query()
            ->when($request->filled('search'), function ($q) use ($request) {
                $search = $request->query('search');
                $q->where(fn ($w) => $w->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('company_name', 'like', "%{$search}%"));
            })
            ->when(! $request->boolean('include_inactive'), fn ($q) => $q->where('is_active', true))
            ->get(['id', 'code', 'name', 'company_name', 'phone', 'city',
                'credit_limit', 'payment_terms_days', 'opening_balance']);

        if ($suppliers->isEmpty()) {
            return [];
        }

        $invoices = PurchaseInvoice::query()
            ->whereIn('supplier_id', $suppliers->pluck('id'))
            ->where('status', 'approved')
            ->whereIn('payment_status', ['unpaid', 'partial'])
            ->get(['id', 'supplier_id', 'invoice_date', 'due_date', 'total', 'paid_amount'])
            ->groupBy('supplier_id');

        $pipeline = PurchaseOrder::query()
            ->whereIn('supplier_id', $suppliers->pluck('id'))
            ->whereIn('status', ['approved', 'partially_received', 'received'])
            ->whereNotIn('id', PurchaseInvoice::query()
                ->whereNotNull('purchase_order_id')
                ->where('status', '!=', 'cancelled')
                ->select('purchase_order_id'))
            ->selectRaw('supplier_id, COALESCE(SUM(total), 0) as pipeline_amount')
            ->groupBy('supplier_id')
            ->pluck('pipeline_amount', 'supplier_id');

        $defaultTerms = $this->credit->defaultTermsDays();
        $out = [];

        foreach ($suppliers as $supplier) {
            $terms = (int) ($supplier->payment_terms_days ?? 0) > 0
                ? (int) $supplier->payment_terms_days
                : $defaultTerms;

            $open = $invoices->get($supplier->id, collect());

            $outstanding = (float) $supplier->opening_balance;
            $overdue = 0.0;
            $oldestDays = 0;
            $dueToday = 0.0;
            $nextDue = null;
            $nextDueAmount = 0.0;

            foreach ($open as $invoice) {
                $balance = round((float) $invoice->total - (float) $invoice->paid_amount, 2);

                if ($balance <= 0) {
                    continue;
                }

                $outstanding += $balance;

                $due = $invoice->due_date
                    ? $invoice->due_date->copy()->startOfDay()
                    : ($invoice->invoice_date
                        ? $invoice->invoice_date->copy()->startOfDay()->addDays($terms)
                        : $asOf->copy());

                $days = (int) $due->diffInDays($asOf, false);

                if ($days > 0) {
                    $overdue += $balance;
                    $oldestDays = max($oldestDays, $days);
                }

                if ($days >= 0) {
                    $dueToday += $balance;
                }

                if ($nextDue === null || $due->lt($nextDue)) {
                    $nextDue = $due;
                    $nextDueAmount = $balance;
                } elseif ($due->eq($nextDue)) {
                    $nextDueAmount += $balance;
                }
            }

            $outstanding = round($outstanding, 2);
            $pipe = round((float) ($pipeline[$supplier->id] ?? 0), 2);
            $exposure = round($outstanding + $pipe, 2);
            $limit = (float) $supplier->credit_limit;

            $out[] = [
                'supplier_id' => $supplier->id,
                'code' => $supplier->code,
                'supplier' => $supplier->name,
                'company_name' => $supplier->company_name,
                'phone' => $supplier->phone,
                'city' => $supplier->city,
                'credit_limit' => round($limit, 2),
                'payment_terms_days' => $terms,
                'has_own_terms' => (int) ($supplier->payment_terms_days ?? 0) > 0,
                'outstanding' => $outstanding,
                'pipeline' => $pipe,
                'exposure' => $exposure,
                'available' => $limit > 0 ? round($limit - $exposure, 2) : null,
                'overdue' => round($overdue, 2),
                'overdue_days' => $oldestDays,
                'due_today' => round($dueToday, 2),
                'next_due_date' => $nextDue?->toDateString(),
                'next_due_amount' => round($nextDueAmount, 2),
                // Labelled by the service, so this row and the detail view can
                // never disagree about what "over limit" means.
                'status' => $this->credit->statusFor($limit, $exposure, round($overdue, 2), $oldestDays),
            ];
        }

        return $out;
    }
}
