<?php

declare(strict_types=1);

namespace App\Modules\Industry\Distribution\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Beat;
use App\Models\Customer;
use App\Models\CustomerCategory;
use App\Models\Invoice;
use App\Models\Salesman;
use App\Models\SalesOrder;
use App\Models\Setting;
use App\Modules\Industry\Distribution\Http\Concerns\FiltersParties;
use App\Modules\Industry\Distribution\Services\CreditControlService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Credit Control — the book a distributor's owner reads first thing in the
 * morning: who owes what, who is over their limit, and who does not get goods
 * today.
 *
 * The screen exists because the facts were scattered. A party's limit lived on the
 * customer master, their outstanding was derived inside the customer resource,
 * what they had confirmed-but-not-lifted was only visible on the orders list,
 * and "stop supplying them" was not recorded anywhere at all — it was a
 * conversation. Put together they are a decision; apart they are trivia.
 *
 * Everything is aggregated in SQL rather than per row. A distributor's master
 * runs to thousands of parties, and a screen that issued two queries per party
 * to show a page of twenty-five would be unusable by the time it mattered.
 *
 * Actions are deliberately narrow: adjust terms, hold, release. Recording a
 * payment stays where it belongs (Payments/Collections) — this screen decides
 * whether to supply, it does not take money.
 */
class CreditControlController extends Controller
{
    use FiltersParties;

    /** Statuses the list can be filtered to, in escalation order. */
    private const STATUSES = ['hold', 'over_limit', 'overdue_block', 'overdue', 'watch', 'ok'];

    public function __construct(private readonly CreditControlService $credit) {}

    /**
     * The party list with live exposure, plus totals over the whole filtered set
     * (not just the page — a collections desk needs the book's total, and
     * paginating a summary would give them the page's).
     */
    public function index(Request $request)
    {
        $request->validate([
            'status' => ['nullable', Rule::in(self::STATUSES)],
            'sort' => ['nullable', Rule::in(['exposure', 'overdue', 'available', 'name', 'oldest'])],
            'dir' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:200'],
        ]);

        $query = $this->exposureQuery($request);

        $summary = $this->summarize($query, $this->countedExpression());

        $sort = (string) $request->query('sort', 'exposure');
        $dir = (string) $request->query('dir', 'desc');
        $query->orderByRaw(match ($sort) {
            'overdue' => "overdue_amount {$dir}",
            'available' => "credit_available {$dir}",
            'oldest' => "oldest_due_date {$dir}",
            'name' => "customers.name {$dir}",
            default => "counted_exposure {$dir}",
        });

        $page = $query->paginate((int) $request->query('per_page', 25));

        return response()->json([
            'data' => collect($page->items())->map(fn (Customer $c) => $this->row($c))->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'summary' => $summary,
                'policy' => $this->credit->policy(),
            ],
        ]);
    }

    /** One party in full: standing, ageing buckets and the open invoices behind them. */
    public function show(Customer $customer)
    {
        return response()->json([
            'data' => [
                'snapshot' => $this->credit->snapshot($customer),
                'ageing' => $this->credit->ageing($customer),
                // Confirmed and not yet invoiced — what would ship next, and the
                // reason a party can be inside their limit and still be stopped.
                'open_orders' => SalesOrder::query()
                    ->where('customer_id', $customer->id)
                    ->whereIn('status', ['confirmed', 'backordered'])
                    ->orderBy('order_date')
                    ->get(['id', 'so_number', 'order_date', 'status', 'total'])
                    ->map(fn (SalesOrder $so) => [
                        'id' => $so->id,
                        'so_number' => $so->so_number,
                        'order_date' => $so->order_date?->toDateString(),
                        'status' => $so->status,
                        'total' => round((float) $so->total, 2),
                    ]),
            ],
        ]);
    }

    /**
     * A party's supply terms — their limit and their credit period.
     *
     * Editable here rather than only on the customer master because this is the
     * screen where the decision is made: you extend a party's limit while
     * looking at what they already owe, not while editing their pincode.
     */
    public function updateTerms(Request $request, Customer $customer)
    {
        $data = $request->validate([
            'credit_limit' => ['required', 'numeric', 'min:0', 'max:999999999.99'],
            // 0 = follow the workspace default rather than "due immediately",
            // which is what an empty field means to the person typing it.
            'payment_terms_days' => ['required', 'integer', 'min:0', 'max:365'],
        ]);

        $customer->update($data);

        return response()->json(['data' => $this->credit->snapshot($customer->refresh())]);
    }

    /**
     * Stop supplying this party. Requires a reason: the next person to see the
     * red row is a despatch clerk with a lorry waiting, and "no" without a
     * reason gets overridden by whoever is nearest the phone.
     */
    public function hold(Request $request, Customer $customer)
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $customer->update([
            'credit_hold' => true,
            'credit_hold_reason' => $data['reason'],
            'credit_hold_at' => now(),
            'credit_hold_by' => $request->user()?->id,
        ]);

        return response()->json(['data' => $this->credit->snapshot($customer->refresh())]);
    }

    /** Resume supply. The reason is cleared with the hold — it described a past state. */
    public function release(Customer $customer)
    {
        $customer->update([
            'credit_hold' => false,
            'credit_hold_reason' => null,
            'credit_hold_at' => null,
            'credit_hold_by' => null,
        ]);

        return response()->json(['data' => $this->credit->snapshot($customer->refresh())]);
    }

    /**
     * The workspace's credit policy. Lives on this screen rather than in
     * Settings because it is only intelligible next to the rows it governs —
     * "block at 45 days overdue" means nothing until you can see who that hits.
     */
    public function updatePolicy(Request $request)
    {
        $data = $request->validate([
            'mode' => ['required', Rule::in(CreditControlService::MODES)],
            'overdue_block_days' => ['required', 'integer', 'min:0', 'max:365'],
            'include_pipeline' => ['required', 'boolean'],
        ]);

        Setting::set('credit_enforcement', $data['mode']);
        Setting::set('credit_overdue_block_days', (string) $data['overdue_block_days']);
        Setting::set('credit_include_pipeline', $data['include_pipeline'] ? '1' : '0');

        return response()->json(['data' => $this->credit->policy()]);
    }

    /**
     * Dropdown contents for both distribution screens, in one call.
     *
     * Served here rather than left to the existing masters endpoints so a
     * collections clerk holding only `credit-control.view` can still filter by
     * beat — they have no business editing beats, and asking them to hold
     * `beats.view` to use a dropdown would over-grant to solve a UI problem.
     */
    public function filters()
    {
        return response()->json([
            'data' => [
                'categories' => CustomerCategory::query()->orderBy('name')->get(['id', 'name']),
                'salesmen' => Salesman::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
                'beats' => Beat::query()->where('is_active', true)->orderBy('name')
                    ->get(['id', 'name', 'salesman_id', 'day_of_week']),
                // Towns that actually appear on the master, so the filter cannot
                // offer a place with no parties in it.
                'cities' => Customer::query()->whereNotNull('city')->where('city', '!=', '')
                    ->distinct()->orderBy('city')->pluck('city'),
                'statuses' => self::STATUSES,
            ],
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Query building
    |--------------------------------------------------------------------------
    */

    /**
     * Customers with their exposure figures joined in as columns, so the list can
     * be filtered, sorted and paginated on them in the database.
     *
     * @return Builder<Customer>
     */
    private function exposureQuery(Request $request): Builder
    {
        $today = now()->startOfDay()->toDateString();

        /**
         * Open invoice money per party. `COALESCE(due_date, invoice_date)` is the
         * ageing reference: due dates are stamped at creation, but rows from
         * before that existed have none, and ageing those from the invoice date
         * is the harsher — and for a collection call, the safer — reading.
         */
        $invoices = Invoice::query()
            ->selectRaw('customer_id')
            ->selectRaw('COALESCE(SUM(total - paid_amount - credited_amount), 0) as due_amount')
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN COALESCE(due_date, invoice_date) < ? '
                .'THEN total - paid_amount - credited_amount ELSE 0 END), 0) as overdue_amount',
                [$today],
            )
            ->selectRaw(
                'MIN(CASE WHEN COALESCE(due_date, invoice_date) < ? '
                .'THEN COALESCE(due_date, invoice_date) END) as oldest_due_date',
                [$today],
            )
            ->whereIn('status', ['unpaid', 'partial'])
            ->groupBy('customer_id');

        // Confirmed, not yet invoiced. Stock is reserved against these, so they
        // are a commitment even though nothing has been billed.
        $orders = SalesOrder::query()
            ->selectRaw('customer_id, COALESCE(SUM(total), 0) as pipeline_amount')
            ->whereIn('status', ['confirmed', 'backordered'])
            ->groupBy('customer_id');

        $counted = $this->countedExpression();

        $query = Customer::query()
            ->leftJoinSub($invoices, 'inv', 'inv.customer_id', '=', 'customers.id')
            ->leftJoinSub($orders, 'so', 'so.customer_id', '=', 'customers.id')
            ->select('customers.*')
            ->selectRaw('(customers.opening_balance + COALESCE(inv.due_amount, 0)) as outstanding_amount')
            ->selectRaw('COALESCE(so.pipeline_amount, 0) as pipeline_amount')
            ->selectRaw('COALESCE(inv.overdue_amount, 0) as overdue_amount')
            ->selectRaw('inv.oldest_due_date as oldest_due_date')
            ->selectRaw("{$counted} as counted_exposure")
            ->selectRaw(
                'CASE WHEN customers.credit_limit > 0 '
                ."THEN customers.credit_limit - {$counted} ELSE NULL END as credit_available",
            )
            ->selectRaw('DATEDIFF(?, inv.oldest_due_date) as overdue_days', [$today]);

        $this->applyPartyFilters($query, $request);

        /**
         * By default the screen shows parties with something at stake — money
         * owed, goods committed, a limit to breach or a hold in force. Everyone
         * else is a name and two zeroes, and burying the twelve parties that
         * matter under four thousand of those is how a screen stops being read.
         */
        if (! $request->boolean('include_clear')) {
            $query->where(function (Builder $q) use ($counted) {
                $q->whereRaw("{$counted} > 0")
                    ->orWhere('customers.credit_limit', '>', 0)
                    ->orWhere('customers.credit_hold', true);
            });
        }

        if ($status = (string) $request->query('status', '')) {
            $this->applyStatusFilter($query, $status, $counted);
        }

        return $query;
    }

    /**
     * The SQL expression for what the limit is measured against — the same sum
     * CreditControlService::snapshot() calls `counted_exposure`, including the
     * order pipeline only when policy says it counts. Written once because the
     * list's chips, its filters and its totals must all agree with the gate that
     * actually refuses a despatch.
     */
    private function countedExpression(): string
    {
        $pipeline = $this->credit->includesPipeline() ? 'COALESCE(so.pipeline_amount, 0)' : '0';

        return "(customers.opening_balance + COALESCE(inv.due_amount, 0) + {$pipeline})";
    }

    /**
     * Narrow to one standing. Expressed in SQL rather than filtered after the
     * fact so the page counts and the totals stay honest.
     *
     * @param  Builder<Customer>  $query
     */
    private function applyStatusFilter(Builder $query, string $status, string $counted): void
    {
        $blockDays = $this->credit->overdueBlockDays();
        $watch = CreditControlService::WATCH_THRESHOLD;

        match ($status) {
            'hold' => $query->where('customers.credit_hold', true),

            'over_limit' => $query->where('customers.credit_hold', false)
                ->where('customers.credit_limit', '>', 0)
                ->whereRaw("{$counted} > customers.credit_limit"),

            // Only meaningful when an age limit is configured; with none set,
            // nothing can be in this state and the filter returns nothing.
            'overdue_block' => $blockDays > 0
                ? $query->where('customers.credit_hold', false)
                    ->whereRaw('COALESCE(inv.overdue_amount, 0) > 0')
                    ->whereRaw('DATEDIFF(CURDATE(), inv.oldest_due_date) > ?', [$blockDays])
                : $query->whereRaw('1 = 0'),

            'overdue' => $query->where('customers.credit_hold', false)
                ->whereRaw('COALESCE(inv.overdue_amount, 0) > 0'),

            'watch' => $query->where('customers.credit_hold', false)
                ->where('customers.credit_limit', '>', 0)
                ->whereRaw("{$counted} >= customers.credit_limit * ?", [$watch])
                ->whereRaw("{$counted} <= customers.credit_limit"),

            'ok' => $query->where('customers.credit_hold', false)
                ->whereRaw('COALESCE(inv.overdue_amount, 0) = 0')
                ->where(function (Builder $q) use ($counted, $watch) {
                    $q->where('customers.credit_limit', '=', 0)
                        ->orWhereRaw("{$counted} < customers.credit_limit * ?", [$watch]);
                }),

            default => null,
        };
    }

    /**
     * Totals across the whole filtered set. Cloned before paging and reduced to
     * aggregates, so it costs one query rather than walking the book.
     *
     * The clone drops the row columns and their bindings — the per-row selects
     * are not aggregates, and leaving them beside COUNT(*) is exactly the shape
     * MySQL's ONLY_FULL_GROUP_BY rejects. Joins and wheres are kept, which is
     * the whole point: these are the *filtered* totals.
     *
     * @param  Builder<Customer>  $query
     * @return array<string, float|int>
     */
    private function summarize(Builder $query, string $counted): array
    {
        $blockDays = $this->credit->overdueBlockDays();

        // toBase(), not getQuery(): the former applies global scopes, and the
        // tenant scope is one of them. A summary built off getQuery() would count
        // every tenant's book.
        $row = (clone $query)->toBase()
            ->cloneWithout(['columns', 'orders', 'limit', 'offset'])
            ->cloneWithoutBindings(['select', 'order'])
            ->selectRaw('COUNT(*) as parties')
            ->selectRaw('COALESCE(SUM(customers.opening_balance + COALESCE(inv.due_amount, 0)), 0) as outstanding')
            ->selectRaw('COALESCE(SUM(COALESCE(inv.overdue_amount, 0)), 0) as overdue')
            ->selectRaw('COALESCE(SUM(COALESCE(so.pipeline_amount, 0)), 0) as pipeline')
            ->selectRaw('SUM(CASE WHEN customers.credit_hold = 1 THEN 1 ELSE 0 END) as on_hold')
            ->selectRaw(
                "SUM(CASE WHEN customers.credit_limit > 0 AND {$counted} > customers.credit_limit "
                .'THEN 1 ELSE 0 END) as over_limit',
            )
            // With no age limit configured nobody can be age-blocked, so the tile
            // reports a plain zero rather than a comparison against nothing.
            ->selectRaw(
                $blockDays > 0
                    ? 'SUM(CASE WHEN DATEDIFF(CURDATE(), inv.oldest_due_date) > ? THEN 1 ELSE 0 END) as age_blocked'
                    : '0 as age_blocked',
                $blockDays > 0 ? [$blockDays] : [],
            )
            ->first();

        return [
            'parties' => (int) ($row->parties ?? 0),
            'outstanding' => round((float) ($row->outstanding ?? 0), 2),
            'overdue' => round((float) ($row->overdue ?? 0), 2),
            'pipeline' => round((float) ($row->pipeline ?? 0), 2),
            'on_hold' => (int) ($row->on_hold ?? 0),
            'over_limit' => (int) ($row->over_limit ?? 0),
            'age_blocked' => (int) ($row->age_blocked ?? 0),
        ];
    }

    /**
     * One list row. The figures come off the joined columns; the label comes off
     * the service, so the chip on this row and the refusal at the despatch door
     * can never disagree.
     *
     * @return array<string, mixed>
     */
    private function row(Customer $customer): array
    {
        $limit = (float) $customer->credit_limit;
        $counted = round((float) $customer->counted_exposure, 2);
        $overdue = round((float) $customer->overdue_amount, 2);
        $overdueDays = max(0, (int) $customer->overdue_days);

        $status = $this->credit->statusFor(
            (bool) $customer->credit_hold, $limit, $counted, $overdue, $overdueDays,
        );

        return [
            'customer_id' => $customer->id,
            'code' => $customer->code,
            'customer' => $customer->name,
            'company_name' => $customer->company_name,
            'phone' => $customer->phone,
            'city' => $customer->city,
            'credit_limit' => round($limit, 2),
            'payment_terms_days' => $this->credit->termsDays($customer),
            'has_own_terms' => (int) ($customer->payment_terms_days ?? 0) > 0,
            'outstanding' => round((float) $customer->outstanding_amount, 2),
            'pipeline' => round((float) $customer->pipeline_amount, 2),
            'counted_exposure' => $counted,
            'available' => $limit > 0 ? round($limit - $counted, 2) : null,
            'overdue' => $overdue,
            'overdue_days' => $overdueDays,
            'oldest_due_date' => $customer->oldest_due_date,
            'on_hold' => (bool) $customer->credit_hold,
            'hold_reason' => $customer->credit_hold_reason,
            'status' => $status,
            'blocked' => $this->credit->blocksStatus($status),
        ];
    }
}
