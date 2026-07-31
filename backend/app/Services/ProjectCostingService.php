<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\ProjectTimesheet;
use App\Models\SalesOrder;
use App\Models\Setting;
use App\Models\Warehouse;
use RuntimeException;

/**
 * What a project has actually cost, and what of it can still be billed.
 *
 * Two cost streams meet here: labour, from the hours logged on timesheets at
 * the rate they were logged at, and direct spend, from expenses tagged to the
 * project. Budget variance is the difference between that total and what the
 * work was sold for — the number the whole module exists to produce.
 *
 * Billing follows the same route as Service and POS: approved billable hours
 * leave on an ordinary Sales Order + Invoice, so receivables and GST pick the
 * work up without knowing projects exist.
 */
class ProjectCostingService
{
    public function __construct(private readonly BrokerCommissionService $brokerCommissions) {}

    /**
     * The project's cost sheet.
     *
     * @return array{
     *     budget: float, labour_cost: float, expense_cost: float, actual_cost: float,
     *     variance: float, variance_percent: float, is_over_budget: bool,
     *     hours_logged: float, hours_billable: float, hours_estimated: float,
     *     billable_value: float, unbilled_value: float, billed_value: float,
     *     task_counts: array<string, int>, progress_percent: int
     * }
     */
    public function costSheet(Project $project): array
    {
        return $this->costSheetsFor([$project])[$project->id];
    }

    /**
     * Cost sheets for a whole page of projects in a fixed number of queries.
     *
     * The list screen is where a manager scans for trouble, so every row needs
     * its numbers — which makes the per-project version an N+1 waiting to
     * happen. Three grouped queries cover any number of projects, so this is
     * the real implementation and costSheet() is the single-row wrapper.
     *
     * @param  iterable<Project>  $projects
     * @return array<int, array<string, mixed>> keyed by project id
     */
    public function costSheetsFor(iterable $projects): array
    {
        $projects = collect($projects);
        $ids = $projects->pluck('id')->all();

        if ($ids === []) {
            return [];
        }

        $labour = ProjectTimesheet::query()
            ->whereIn('project_id', $ids)
            ->whereNot('status', 'rejected')
            ->groupBy('project_id')
            ->selectRaw('project_id')
            ->selectRaw('COALESCE(SUM(hours), 0) as total_hours')
            ->selectRaw('COALESCE(SUM(amount), 0) as labour_cost')
            ->selectRaw('COALESCE(SUM(CASE WHEN is_billable = 1 THEN hours ELSE 0 END), 0) as billable_hours')
            ->selectRaw("COALESCE(SUM(CASE WHEN is_billable = 1 AND status = 'approved' THEN amount ELSE 0 END), 0) as billable_value")
            ->selectRaw("COALESCE(SUM(CASE WHEN is_billable = 1 AND status = 'approved' AND invoice_id IS NULL THEN amount ELSE 0 END), 0) as unbilled_value")
            ->selectRaw('COALESCE(SUM(CASE WHEN invoice_id IS NOT NULL THEN amount ELSE 0 END), 0) as billed_value')
            ->get()
            ->keyBy('project_id');

        // Rejected expenses were never spent; draft and pending ones are money
        // already committed, so they belong in the actual just as much as an
        // approved one — a manager watching a budget needs the exposure, not the
        // paperwork state.
        $expenses = Expense::query()
            ->whereIn('project_id', $ids)
            ->whereNot('status', 'rejected')
            ->groupBy('project_id')
            ->selectRaw('project_id, COALESCE(SUM(amount), 0) as expense_cost')
            ->pluck('expense_cost', 'project_id');

        $tasks = ProjectTask::query()
            ->whereIn('project_id', $ids)
            ->groupBy('project_id', 'status')
            ->selectRaw('project_id, status, COUNT(*) as total, AVG(progress_percent) as avg_progress')
            ->get()
            ->groupBy('project_id');

        $out = [];

        foreach ($projects as $project) {
            $l = $labour->get($project->id);
            $rows = $tasks->get($project->id) ?? collect();

            $taskCounts = $rows->pluck('total', 'status')->map(fn ($n) => (int) $n)->all();
            $labourCost = (float) ($l->labour_cost ?? 0);
            $expenseCost = (float) ($expenses[$project->id] ?? 0);
            $actual = round($labourCost + $expenseCost, 2);
            $budget = (float) $project->budget_amount;

            $out[$project->id] = [
                'budget' => $budget,
                'labour_cost' => round($labourCost, 2),
                'expense_cost' => round($expenseCost, 2),
                'actual_cost' => $actual,
                // Positive = still inside the budget.
                'variance' => round($budget - $actual, 2),
                'variance_percent' => $budget > 0 ? round(($budget - $actual) / $budget * 100, 2) : 0.0,
                'is_over_budget' => $budget > 0 && $actual > $budget,
                'hours_logged' => round((float) ($l->total_hours ?? 0), 2),
                'hours_billable' => round((float) ($l->billable_hours ?? 0), 2),
                'hours_estimated' => (float) $project->estimated_hours,
                'billable_value' => round((float) ($l->billable_value ?? 0), 2),
                'unbilled_value' => round((float) ($l->unbilled_value ?? 0), 2),
                'billed_value' => round((float) ($l->billed_value ?? 0), 2),
                'task_counts' => $taskCounts,
                'progress_percent' => $this->progressPercent($project, $rows),
            ];
        }

        return $out;
    }

    /**
     * How far along the project is, as the task-count-weighted average of its
     * tasks' own progress.
     *
     * Deliberately not derived from hours-against-estimate: burning the budget
     * is not the same as finishing the work, and conflating them is how a
     * project reports 100% done the day it runs out of money.
     *
     * @param  \Illuminate\Support\Collection<int, object>  $taskRows  per-status count + average
     */
    private function progressPercent(Project $project, $taskRows): int
    {
        $total = (int) $taskRows->sum('total');

        if ($total === 0) {
            return $project->status === 'completed' ? 100 : 0;
        }

        $weighted = $taskRows->sum(fn ($row) => (float) $row->avg_progress * (int) $row->total);

        return (int) round($weighted / $total);
    }

    /**
     * How many open projects have spent past their budget, in one query.
     *
     * Mirrors the actual-cost definition in costSheetsFor() — labour plus
     * expenses, both excluding rejected rows. Kept here, next to that
     * definition, so the two cannot drift apart in different files.
     */
    public function overBudgetCount(): int
    {
        return Project::query()
            ->whereNotIn('status', Project::CLOSED_STATUSES)
            ->where('budget_amount', '>', 0)
            ->whereRaw(
                'budget_amount < ('
                .'COALESCE((SELECT SUM(t.amount) FROM project_timesheets t'
                ." WHERE t.project_id = projects.id AND t.status <> 'rejected'), 0)"
                .' + COALESCE((SELECT SUM(e.amount) FROM expenses e'
                ." WHERE e.project_id = projects.id AND e.status <> 'rejected'), 0))",
            )
            ->count();
    }

    /**
     * Value a timesheet line: fall back to the project's rate when the entry
     * does not carry its own, then store the arithmetic once.
     */
    public function valueEntry(ProjectTimesheet $entry, Project $project): ProjectTimesheet
    {
        if ((float) $entry->hourly_rate <= 0) {
            $entry->hourly_rate = $project->defaultRate();
        }

        $entry->recalculate();

        return $entry;
    }

    /**
     * Invoice a project's approved, billable, not-yet-invoiced hours.
     *
     * Returns null when there is nothing to bill — no approved hours, or the
     * project is not sold by the hour — so the caller can offer the button
     * unconditionally and let this decide.
     *
     * @param  list<int>|null  $timesheetIds  Limit to these entries; null bills all eligible.
     */
    public function billApprovedHours(Project $project, ?array $timesheetIds = null, ?int $userId = null): ?Invoice
    {
        if (! $project->billsByTheHour()) {
            return null;
        }

        $entries = ProjectTimesheet::query()
            ->where('project_id', $project->id)
            ->where('status', ProjectTimesheet::BILLABLE_STATUS)
            ->where('is_billable', true)
            ->whereNull('invoice_id')
            ->when($timesheetIds !== null, fn ($q) => $q->whereIn('id', $timesheetIds))
            ->get();

        if ($entries->isEmpty()) {
            return null;
        }

        $hours = round((float) $entries->sum('hours'), 2);
        $total = round((float) $entries->sum('amount'), 2);

        if ($total <= 0) {
            return null;
        }

        $product = $this->serviceProduct();

        $salesOrder = SalesOrder::create([
            'so_number' => SalesOrder::nextNumber(),
            'customer_id' => $project->customer_id,
            'warehouse_id' => $this->fallbackWarehouseId($project),
            'status' => 'delivered',
            'channel' => 'project',
            'order_date' => now()->toDateString(),
            'subtotal' => $total,
            'item_discount_total' => 0,
            'discount_type' => null,
            'discount_value' => 0,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'round_off' => 0,
            'total' => $total,
            'notes' => "Project {$project->project_number} — {$hours} hour(s)",
            'created_by' => $userId,
            'delivered_at' => now(),
        ]);

        // One line for the whole batch rather than one per timesheet: the
        // customer bought hours on a project, not forty separate diary entries.
        $salesOrder->items()->create([
            'product_id' => $product->id,
            'variant_id' => null,
            'quantity' => $hours,
            'unit_price' => $hours > 0 ? round($total / $hours, 2) : 0,
            'discount_type' => null,
            'discount_value' => 0,
            'discount_amount' => 0,
            'tax_percent' => 0,
            'tax_amount' => 0,
            'line_total' => $total,
        ]);

        $invoice = Invoice::create([
            'invoice_number' => Invoice::nextNumber(),
            'sales_order_id' => $salesOrder->id,
            'customer_id' => $project->customer_id,
            'invoice_date' => now()->toDateString(),
            'total' => $total,
            'paid_amount' => 0,
            'status' => 'unpaid',
        ]);

        $this->brokerCommissions->accrueForInvoice($invoice);

        // Stamping the entries is what stops them billing twice.
        ProjectTimesheet::whereIn('id', $entries->pluck('id'))->update(['invoice_id' => $invoice->id]);

        return $invoice;
    }

    /**
     * The catalogue product project hours are billed under, created on first
     * use. Same reasoning as the service module's labour product: an invoice
     * line needs a product, and asking every workspace to hand-create one before
     * its first bill is the worse trade.
     */
    private function serviceProduct(): Product
    {
        $sku = (string) Setting::get('project_hours_sku', 'PROJECT-HOURS');

        return Product::firstOrCreate(
            ['sku' => $sku],
            [
                'name' => 'Project Hours',
                'description' => 'Professional hours billed against a project.',
                'is_service' => true,
                'is_active' => true,
                'cost_price' => 0,
                'selling_price' => 0,
                'tax_percent' => 0,
            ],
        );
    }

    /** A sales order needs a warehouse; nothing moves through this one. */
    private function fallbackWarehouseId(Project $project): int
    {
        $id = Warehouse::query()->orderBy('id')->value('id');

        if (! $id) {
            throw new RuntimeException("Create a warehouse before billing project {$project->project_number}.");
        }

        return (int) $id;
    }
}
