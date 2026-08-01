<?php

declare(strict_types=1);

namespace App\Modules\Industry\Distribution\Http\Controllers;

use App\Http\Controllers\Concerns\ExportsReports;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Invoice;
use App\Modules\Industry\Distribution\Http\Concerns\FiltersParties;
use App\Modules\Industry\Distribution\Services\CreditControlService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Outstanding & Ageing — party-wise receivables in days-past-due buckets.
 *
 * The counterpart of the payables report, and deliberately not its mirror image.
 * Payables ages a supplier bill from its due date in 30-day steps and that is
 * enough, because a purchase ledger is a few dozen accounts. A distribution
 * sales ledger is thousands of shops on different terms, and the only question
 * worth asking of it is "who is late, by how long, and whose beat are they on?" —
 * so the buckets here are *days past due*, and the whole thing slices by
 * salesman, beat, category and town.
 *
 * Two figures are kept apart on purpose:
 *
 *  - The buckets, which are invoice money and therefore have an age.
 *  - The opening balance, which is a carried-forward number with no invoice
 *    behind it and so no age at all. Folding it into "not due" would understate
 *    the ageing and folding it into "90+" would invent a fact, so it gets its own
 *    column and is added only into the total.
 *
 * Aggregation happens in SQL, one row per party, for the same reason as Credit
 * Control: this is a screen that gets run against the entire ledger.
 */
class ReceivablesReportController extends Controller
{
    use ExportsReports, FiltersParties;

    public function __construct(private readonly CreditControlService $credit) {}

    public function ageing(Request $request)
    {
        $request->validate([
            'as_of' => ['nullable', 'date'],
            'overdue_only' => ['nullable', 'boolean'],
            'min_amount' => ['nullable', 'numeric', 'min:0'],
        ]);

        $asOf = $request->filled('as_of')
            ? $request->date('as_of')->startOfDay()
            : now()->startOfDay();
        $asOfDate = $asOf->toDateString();

        $rows = $this->partyQuery($request, $asOfDate)->get()
            ->map(fn (Customer $c) => $this->row($c))
            // Parties whose whole balance is settled fall out here rather than in
            // SQL: the join has to run anyway, and the arithmetic that decides
            // "nothing left to chase" is the same rounding the columns show.
            ->reject(fn (array $row) => $row['total_outstanding'] <= 0);

        if ($request->boolean('overdue_only')) {
            $rows = $rows->reject(fn (array $row) => $row['overdue_total'] <= 0);
        }

        if ($request->filled('min_amount')) {
            $min = (float) $request->query('min_amount');
            $rows = $rows->reject(fn (array $row) => $row['total_outstanding'] < $min);
        }

        // Biggest exposure first: this is a work list, not a directory.
        $rows = $rows->sortByDesc('total_outstanding')->values()->all();

        return $this->exportResponse(
            $request,
            $rows,
            $this->columns(),
            'outstanding-ageing',
            $this->totals($rows) + ['as_of' => $asOfDate, 'buckets' => CreditControlService::bucketLabels()],
        );
    }

    /**
     * One party's ageing in detail — the invoices behind the buckets, oldest
     * first. What a collections call is actually made from.
     */
    public function party(Request $request, Customer $customer)
    {
        $request->validate(['as_of' => ['nullable', 'date']]);

        $asOf = $request->filled('as_of') ? $request->date('as_of') : null;

        return response()->json([
            'data' => [
                'customer' => [
                    'id' => $customer->id,
                    'code' => $customer->code,
                    'name' => $customer->name,
                    'phone' => $customer->phone,
                    'city' => $customer->city,
                    'credit_limit' => round((float) $customer->credit_limit, 2),
                    'payment_terms_days' => $this->credit->termsDays($customer),
                ],
                'ageing' => $this->credit->ageing($customer, $asOf),
            ],
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Query building
    |--------------------------------------------------------------------------
    */

    /**
     * Customers with their bucketed invoice balances joined in.
     *
     * @return Builder<Customer>
     */
    private function partyQuery(Request $request, string $asOfDate): Builder
    {
        /**
         * `COALESCE(due_date, invoice_date)` is the ageing reference — see the
         * note in CreditControlController: invoices raised before due dates
         * existed have none, and ageing those from the invoice date is the
         * stricter reading.
         *
         * DATEDIFF is MySQL, which this application already requires elsewhere
         * (the plugin migrations and the waiter report both depend on it).
         */
        $due = 'COALESCE(invoices.due_date, invoices.invoice_date)';
        $balance = '(invoices.total - invoices.paid_amount - invoices.credited_amount)';
        $age = "DATEDIFF(?, {$due})";

        $invoices = Invoice::query()
            ->selectRaw('customer_id')
            ->selectRaw("COALESCE(SUM({$balance}), 0) as invoice_total")
            ->selectRaw("COALESCE(SUM(CASE WHEN {$age} <= 0 THEN {$balance} ELSE 0 END), 0) as not_due", [$asOfDate])
            ->selectRaw("COALESCE(SUM(CASE WHEN {$age} BETWEEN 1 AND 30 THEN {$balance} ELSE 0 END), 0) as due_0_30", [$asOfDate])
            ->selectRaw("COALESCE(SUM(CASE WHEN {$age} BETWEEN 31 AND 60 THEN {$balance} ELSE 0 END), 0) as due_31_60", [$asOfDate])
            ->selectRaw("COALESCE(SUM(CASE WHEN {$age} BETWEEN 61 AND 90 THEN {$balance} ELSE 0 END), 0) as due_61_90", [$asOfDate])
            ->selectRaw("COALESCE(SUM(CASE WHEN {$age} > 90 THEN {$balance} ELSE 0 END), 0) as due_90_plus", [$asOfDate])
            ->selectRaw("MIN(CASE WHEN {$age} > 0 THEN {$due} END) as oldest_due_date", [$asOfDate])
            ->selectRaw("MAX(CASE WHEN {$age} > 0 THEN {$age} ELSE 0 END) as overdue_days", [$asOfDate, $asOfDate])
            ->whereIn('status', ['unpaid', 'partial'])
            /**
             * Invoices dated after the as-of date are excluded, so re-running the
             * report for last month ages last month's ledger rather than today's.
             *
             * Be clear about what as-of does *not* do: the balance on each invoice
             * is its balance now, because a receipt is stored against the invoice
             * as an amount rather than as a dated reduction of it, and a credit
             * note carries no date on this row at all. So an as-of in the past
             * answers "which invoices existed then, and how late are they by that
             * date" — not "what was the ledger worth that morning". Netting
             * payments by date would need the payment ledger walked per invoice,
             * which is a bigger change than this screen, and half-doing it (dated
             * receipts, undated credits) would be worse than not doing it.
             */
            ->whereDate('invoice_date', '<=', $asOfDate)
            ->groupBy('customer_id');

        $query = Customer::query()
            ->leftJoinSub($invoices, 'inv', 'inv.customer_id', '=', 'customers.id')
            ->leftJoin('customer_categories', 'customer_categories.id', '=', 'customers.customer_category_id')
            ->select('customers.*')
            ->addSelect('customer_categories.name as category_name')
            ->selectRaw('COALESCE(inv.invoice_total, 0) as invoice_total')
            ->selectRaw('COALESCE(inv.not_due, 0) as not_due')
            ->selectRaw('COALESCE(inv.due_0_30, 0) as due_0_30')
            ->selectRaw('COALESCE(inv.due_31_60, 0) as due_31_60')
            ->selectRaw('COALESCE(inv.due_61_90, 0) as due_61_90')
            ->selectRaw('COALESCE(inv.due_90_plus, 0) as due_90_plus')
            ->selectRaw('COALESCE(inv.overdue_days, 0) as overdue_days')
            ->selectRaw('inv.oldest_due_date as oldest_due_date')
            // A party with no open invoice but a carried-forward balance is still
            // owed money, so the join is left and the opening balance stands on
            // its own.
            ->where(function (Builder $q) {
                $q->whereRaw('COALESCE(inv.invoice_total, 0) <> 0')
                    ->orWhere('customers.opening_balance', '<>', 0);
            });

        $this->applyPartyFilters($query, $request);

        // The salesman a party belongs to, for the beat-wise reading. Reported as
        // one name per row: the first beat they are on, which is the salesman who
        // would actually be sent.
        $query->addSelect(['salesman_name' => function ($sub) {
            $sub->selectRaw('salesmen.name')
                ->from('beat_customers')
                ->join('beats', 'beats.id', '=', 'beat_customers.beat_id')
                ->join('salesmen', 'salesmen.id', '=', 'beats.salesman_id')
                ->whereColumn('beat_customers.customer_id', 'customers.id')
                ->orderBy('beat_customers.sequence')
                ->limit(1);
        }]);

        return $query;
    }

    /**
     * One report row.
     *
     * @return array<string, mixed>
     */
    private function row(Customer $customer): array
    {
        $buckets = [
            'not_due' => round((float) $customer->not_due, 2),
            'due_0_30' => round((float) $customer->due_0_30, 2),
            'due_31_60' => round((float) $customer->due_31_60, 2),
            'due_61_90' => round((float) $customer->due_61_90, 2),
            'due_90_plus' => round((float) $customer->due_90_plus, 2),
        ];

        $overdueTotal = round(
            $buckets['due_0_30'] + $buckets['due_31_60'] + $buckets['due_61_90'] + $buckets['due_90_plus'],
            2,
        );
        $opening = round((float) $customer->opening_balance, 2);
        $total = round(array_sum($buckets) + $opening, 2);
        $limit = round((float) $customer->credit_limit, 2);

        return [
            'customer_id' => $customer->id,
            'code' => $customer->code,
            'customer' => $customer->name,
            'category' => $customer->category_name,
            'city' => $customer->city,
            'phone' => $customer->phone,
            'salesman' => $customer->salesman_name,
            'credit_limit' => $limit,
            'payment_terms_days' => $this->credit->termsDays($customer),
            ...$buckets,
            'opening_balance' => $opening,
            'overdue_total' => $overdueTotal,
            'total_outstanding' => $total,
            'overdue_days' => max(0, (int) $customer->overdue_days),
            'oldest_due_date' => $customer->oldest_due_date,
            'on_hold' => (bool) $customer->credit_hold,
            // Over the limit *including* what is already overdue — the pair of
            // facts that decides whether the next order goes out.
            'over_limit' => $limit > 0 && $total > $limit,
        ];
    }

    /**
     * Export column map. Matches the on-screen table so a printed copy and the
     * screen are the same document.
     *
     * @return array<string, string>
     */
    private function columns(): array
    {
        return [
            'code' => 'Code',
            'customer' => 'Party',
            'city' => 'City',
            'salesman' => 'Salesman',
            'payment_terms_days' => 'Terms (days)',
            'credit_limit' => 'Credit Limit',
            ...CreditControlService::bucketLabels(),
            'opening_balance' => 'Opening Balance',
            'overdue_total' => 'Total Overdue',
            'total_outstanding' => 'Total Outstanding',
            'overdue_days' => 'Days Overdue',
        ];
    }

    /**
     * Column totals for the tiles and the export footer.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function totals(array $rows): array
    {
        $sum = static fn (string $key) => round(array_sum(array_column($rows, $key)), 2);

        return [
            'parties' => count($rows),
            'not_due' => $sum('not_due'),
            'due_0_30' => $sum('due_0_30'),
            'due_31_60' => $sum('due_31_60'),
            'due_61_90' => $sum('due_61_90'),
            'due_90_plus' => $sum('due_90_plus'),
            'opening_balance' => $sum('opening_balance'),
            'overdue_total' => $sum('overdue_total'),
            'total_outstanding' => $sum('total_outstanding'),
            'over_limit_parties' => count(array_filter(array_column($rows, 'over_limit'))),
            'on_hold_parties' => count(array_filter(array_column($rows, 'on_hold'))),
        ];
    }
}
