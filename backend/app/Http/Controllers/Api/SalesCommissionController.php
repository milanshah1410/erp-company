<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CommissionEarning;
use App\Models\CommissionRule;
use App\Services\SalesCommissionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Shop-staff sales commission (retail base §8b): the rules a shop promises, and
 * the earnings computed under them.
 *
 * One controller for both halves because they are one screen to whoever runs it
 * — you change a rate and immediately want to see what the month now comes to.
 */
class SalesCommissionController extends Controller
{
    public function __construct(private readonly SalesCommissionService $commissions) {}

    // -----------------------------------------------------------------------
    // Rules
    // -----------------------------------------------------------------------

    public function rules(Request $request)
    {
        $query = CommissionRule::query()->with([
            'employee:id,first_name,last_name,employee_code', 'department:id,name',
            'category:id,name', 'brand:id,name', 'product:id,name,sku',
        ])->withCount('earnings');

        if ($request->filled('search')) {
            $query->where('name', 'like', '%'.$request->query('search').'%');
        }
        if ($request->boolean('active_only')) {
            $query->where('is_active', true);
        }

        $rules = $query->orderBy('priority')->orderBy('id')->get();

        return response()->json(['data' => $rules->map(fn ($r) => $this->rulePayload($r))]);
    }

    public function storeRule(Request $request)
    {
        $data = $this->validatedRule($request);

        $rule = CommissionRule::create($data + ['created_by' => $request->user()?->id]);

        return response()->json(['data' => $this->rulePayload($rule->load('employee', 'department', 'category', 'brand', 'product'))], 201);
    }

    public function updateRule(Request $request, CommissionRule $rule)
    {
        $rule->update($this->validatedRule($request));

        return response()->json(['data' => $this->rulePayload($rule->fresh()->load('employee', 'department', 'category', 'brand', 'product'))]);
    }

    /**
     * Retiring a rule. Deletes only while nothing has been earned under it;
     * otherwise it is deactivated, because deleting would orphan the rate that
     * explains somebody's past payslip.
     */
    public function destroyRule(CommissionRule $rule)
    {
        if ($rule->earnings()->exists()) {
            $rule->update(['is_active' => false]);

            return response()->json(['message' => 'Commission has already been earned under this rule, so it was switched off rather than deleted.']);
        }

        $rule->delete();

        return response()->json(['message' => 'Rule deleted.']);
    }

    // -----------------------------------------------------------------------
    // Earnings
    // -----------------------------------------------------------------------

    public function earnings(Request $request)
    {
        [$year, $month] = $this->period($request);

        $query = CommissionEarning::query()
            ->with([
                'employee:id,first_name,last_name,employee_code',
                'rule:id,name,basis', 'product:id,name,sku',
                'salesOrder:id,so_number,order_date', 'invoice:id,invoice_number',
            ])
            ->forPeriod($year, $month);

        if ($request->filled('employee_id')) {
            $query->where('employee_id', (int) $request->query('employee_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        $perPage = min((int) $request->query('per_page', 50), 200);
        $page = (clone $query)->orderByDesc('earned_on')->orderByDesc('id')->paginate($perPage);

        return response()->json([
            'data' => $page->getCollection()->map(fn ($e) => $this->earningPayload($e)),
            'summary' => $this->earningSummary($year, $month, $request->query('employee_id')),
            'by_employee' => $this->byEmployee($year, $month),
            'period' => ['year' => $year, 'month' => $month],
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    /**
     * Compute the month. Safe to re-run: earnings are keyed on (line, rule,
     * employee), so a second pass refreshes open rows and leaves paid ones alone.
     */
    public function generate(Request $request)
    {
        $data = $request->validate([
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
            'employee_id' => ['nullable', 'exists:employees,id'],
        ]);

        $result = $this->commissions->generate($data['year'], $data['month'], $data['employee_id'] ?? null);

        $message = "{$result['generated']} earning(s) created, {$result['updated']} refreshed.";
        if ($result['skipped_no_employee'] > 0) {
            // Reported, never silent: a shop whose owner rings up half the bills
            // would otherwise see a low total and no reason for it.
            $message .= " {$result['skipped_no_employee']} bill(s) skipped — rung up by a user with no employee record.";
        }

        return response()->json(['message' => $message, 'data' => $result]);
    }

    /** Sign off a set of earnings. The floor manager's act, not payroll's. */
    public function approve(Request $request)
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);

        $count = CommissionEarning::query()
            ->whereIn('id', $data['ids'])
            ->where('status', 'pending')
            ->update([
                'status' => 'approved',
                'approved_by' => $request->user()?->id,
                'approved_at' => now(),
            ]);

        return response()->json(['message' => "{$count} earning(s) approved."]);
    }

    /**
     * Settle them. Separate from approval because it happens on a different day,
     * usually with the payroll run, and it is the point after which the figures
     * stop being editable.
     */
    public function markPaid(Request $request)
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
            'paid_on' => ['nullable', 'date'],
            'payment_reference' => ['nullable', 'string', 'max:120'],
        ]);

        $count = CommissionEarning::query()
            ->whereIn('id', $data['ids'])
            ->where('status', 'approved')
            ->update([
                'status' => 'paid',
                'paid_on' => $data['paid_on'] ?? now()->toDateString(),
                'payment_reference' => $data['payment_reference'] ?? null,
            ]);

        return response()->json([
            'message' => "{$count} earning(s) marked paid.",
            // Stated rather than assumed: approving is a prerequisite, and a run
            // that silently settled nothing is the hardest kind of bug to notice.
            'skipped' => count($data['ids']) - $count,
        ]);
    }

    /**
     * Cancel an earning that should not have been paid — the only way to change a
     * settled figure. Reversed rows stay on the register and are excluded from
     * every total, so the correction is visible rather than a hole.
     */
    public function reverse(Request $request, CommissionEarning $earning)
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        if ($earning->status === 'reversed') {
            return response()->json(['message' => 'This earning is already reversed.'], 422);
        }

        $earning->update([
            'status' => 'reversed',
            'notes' => trim(($earning->notes ? $earning->notes."\n" : '').'Reversed: '.($data['reason'] ?? 'no reason given')),
        ]);

        return response()->json(['message' => 'Earning reversed.', 'data' => $this->earningPayload($earning->fresh()->load('employee', 'rule', 'product'))]);
    }

    /** Correct one figure by hand, while it is still open. */
    public function updateEarning(Request $request, CommissionEarning $earning)
    {
        if ($earning->isSettled() || $earning->status === 'reversed') {
            return response()->json(['message' => 'A settled or reversed earning cannot be edited — reverse it instead.'], 422);
        }

        $data = $request->validate([
            'commission_amount' => ['sometimes', 'numeric', 'min:0'],
            'employee_id' => ['sometimes', 'exists:employees,id'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $earning->update($data);

        return response()->json(['data' => $this->earningPayload($earning->fresh()->load('employee', 'rule', 'product', 'salesOrder', 'invoice'))]);
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    private function period(Request $request): array
    {
        return [
            (int) ($request->query('year') ?: now()->year),
            (int) ($request->query('month') ?: now()->month),
        ];
    }

    private function earningSummary(int $year, int $month, $employeeId): array
    {
        $base = CommissionEarning::query()->forPeriod($year, $month)->counting();
        if ($employeeId) {
            $base->where('employee_id', (int) $employeeId);
        }

        return [
            'earnings' => (int) (clone $base)->count(),
            'total' => round((float) (clone $base)->sum('commission_amount'), 2),
            'pending' => round((float) (clone $base)->where('status', 'pending')->sum('commission_amount'), 2),
            'approved' => round((float) (clone $base)->where('status', 'approved')->sum('commission_amount'), 2),
            'paid' => round((float) (clone $base)->where('status', 'paid')->sum('commission_amount'), 2),
            'sales_value' => round((float) (clone $base)->sum('sale_amount'), 2),
        ];
    }

    /** The payout sheet: one line per member of staff, which is how it is settled. */
    private function byEmployee(int $year, int $month): array
    {
        return CommissionEarning::query()
            ->forPeriod($year, $month)
            ->counting()
            ->join('employees', 'employees.id', '=', 'commission_earnings.employee_id')
            ->selectRaw("commission_earnings.employee_id,
                CONCAT(employees.first_name, ' ', COALESCE(employees.last_name, '')) as employee,
                employees.employee_code,
                COUNT(*) as lines_count,
                SUM(commission_earnings.sale_amount) as sales_value,
                SUM(commission_earnings.commission_amount) as commission,
                SUM(CASE WHEN commission_earnings.status = 'pending' THEN commission_earnings.commission_amount ELSE 0 END) as pending,
                SUM(CASE WHEN commission_earnings.status = 'approved' THEN commission_earnings.commission_amount ELSE 0 END) as approved,
                SUM(CASE WHEN commission_earnings.status = 'paid' THEN commission_earnings.commission_amount ELSE 0 END) as paid")
            ->groupBy('commission_earnings.employee_id', 'employees.first_name', 'employees.last_name', 'employees.employee_code')
            ->orderByDesc(DB::raw('SUM(commission_earnings.commission_amount)'))
            ->get()
            ->map(fn ($r) => [
                'employee_id' => (int) $r->employee_id,
                'employee' => trim((string) $r->employee),
                'employee_code' => $r->employee_code,
                'lines_count' => (int) $r->lines_count,
                'sales_value' => round((float) $r->sales_value, 2),
                'commission' => round((float) $r->commission, 2),
                'pending' => round((float) $r->pending, 2),
                'approved' => round((float) $r->approved, 2),
                'paid' => round((float) $r->paid, 2),
            ])
            ->all();
    }

    private function validatedRule(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'applies_to' => ['required', Rule::in(['all', 'employee', 'department'])],
            'employee_id' => ['nullable', 'required_if:applies_to,employee', 'exists:employees,id'],
            'department_id' => ['nullable', 'required_if:applies_to,department', 'exists:departments,id'],
            'category_id' => ['nullable', 'exists:categories,id'],
            'brand_id' => ['nullable', 'exists:brands,id'],
            'product_id' => ['nullable', 'exists:products,id'],
            'basis' => ['required', Rule::in(CommissionRule::BASES)],
            // Percentages are capped at 100; a flat per-unit amount is not, because
            // "₹500 a mattress" is a real scheme.
            'rate' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'min_line_amount' => ['nullable', 'numeric', 'min:0'],
            'min_invoice_amount' => ['nullable', 'numeric', 'min:0'],
            'priority' => ['nullable', 'integer', 'min:1', 'max:9999'],
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'is_active' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
    }

    private function rulePayload(CommissionRule $r): array
    {
        return [
            'id' => $r->id,
            'name' => $r->name,
            'applies_to' => $r->applies_to,
            'employee_id' => $r->employee_id,
            'employee' => $r->employee?->fullName(),
            'department_id' => $r->department_id,
            'department' => $r->department?->name,
            'category_id' => $r->category_id,
            'category' => $r->category?->name,
            'brand_id' => $r->brand_id,
            'brand' => $r->brand?->name,
            'product_id' => $r->product_id,
            'product' => $r->product?->name,
            'scope' => $r->describeScope(),
            'basis' => $r->basis,
            'rate' => $r->rate,
            'min_line_amount' => $r->min_line_amount,
            'min_invoice_amount' => $r->min_invoice_amount,
            'priority' => $r->priority,
            'effective_from' => $r->effective_from?->toDateString(),
            'effective_to' => $r->effective_to?->toDateString(),
            'is_active' => $r->is_active,
            'notes' => $r->notes,
            'earnings_count' => $r->earnings_count ?? null,
        ];
    }

    private function earningPayload(CommissionEarning $e): array
    {
        return [
            'id' => $e->id,
            'employee_id' => $e->employee_id,
            'employee' => $e->employee?->fullName(),
            'rule' => $e->rule?->name,
            'so_number' => $e->salesOrder?->so_number,
            'invoice_number' => $e->invoice?->invoice_number,
            'product' => $e->product?->name,
            'earned_on' => $e->earned_on?->toDateString(),
            'quantity' => $e->quantity,
            'sale_amount' => $e->sale_amount,
            'cost_amount' => $e->cost_amount,
            'margin_amount' => $e->margin_amount,
            'basis' => $e->basis,
            'rate' => $e->rate,
            'commission_amount' => $e->commission_amount,
            'status' => $e->status,
            'approved_at' => $e->approved_at,
            'paid_on' => $e->paid_on?->toDateString(),
            'payment_reference' => $e->payment_reference,
            'notes' => $e->notes,
        ];
    }
}
