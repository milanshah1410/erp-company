<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ExportsReports;
use App\Http\Controllers\Controller;
use App\Http\Requests\ExpenseRequest;
use App\Http\Resources\ExpenseResource;
use App\Models\Expense;
use App\Support\TenantStorage;
use Illuminate\Http\Request;

/**
 * Company Expense — operating spend booked against a category and optionally a
 * branch, with a light approval workflow (draft → pending → approved /
 * rejected), an optional bill/receipt attachment, and a paid flag.
 *
 * Reporting: the ledger lists expenses chronologically; the monthly / branch /
 * category summaries aggregate approved spend for dashboards.
 */
class ExpenseController extends Controller
{
    use ExportsReports;

    private const EXPORT_LIMIT = 10000;

    public function index(Request $request)
    {
        $query = Expense::query()->with(['category:id,name', 'branch:id,name']);

        $this->applyFilters($query, $request);

        $query->orderByDesc('expense_date')->orderByDesc('id');
        $perPage = min((int) $request->query('per_page', 15), 100);

        return ExpenseResource::collection($query->paginate($perPage));
    }

    public function store(ExpenseRequest $request)
    {
        $data = $request->validated();

        $expense = Expense::create([
            ...$data,
            'expense_number' => Expense::nextNumber(),
            'status' => 'draft',
            'created_by' => $request->user()?->id,
        ]);

        return (new ExpenseResource($expense->load($this->relations())))
            ->response()->setStatusCode(201);
    }

    public function show(Expense $expense)
    {
        return new ExpenseResource($expense->load($this->relations()));
    }

    public function update(ExpenseRequest $request, Expense $expense)
    {
        if (! in_array($expense->status, ['draft', 'rejected'], true)) {
            return response()->json(['message' => 'Only draft or rejected expenses can be edited.'], 422);
        }
        // Editing a rejected expense returns it to draft for re-submission.
        $expense->update([...$request->validated(), 'status' => 'draft', 'rejection_reason' => null]);

        return new ExpenseResource($expense->load($this->relations()));
    }

    public function destroy(Expense $expense)
    {
        if (! in_array($expense->status, ['draft', 'rejected'], true)) {
            return response()->json(['message' => 'Only draft or rejected expenses can be deleted.'], 422);
        }
        if ($expense->attachment_path) {
            TenantStorage::disk()->delete($expense->attachment_path);
        }
        $expense->delete();

        return response()->json(['message' => 'Expense deleted.']);
    }

    /** Submit a draft/rejected expense for approval. */
    public function submit(Expense $expense)
    {
        if (! in_array($expense->status, ['draft', 'rejected'], true)) {
            return response()->json(['message' => 'Only draft or rejected expenses can be submitted.'], 422);
        }
        $expense->update(['status' => 'pending', 'rejection_reason' => null]);

        return new ExpenseResource($expense->load($this->relations()));
    }

    public function approve(Request $request, Expense $expense)
    {
        if ($expense->status !== 'pending') {
            return response()->json(['message' => 'Only pending expenses can be approved.'], 422);
        }
        $expense->update([
            'status' => 'approved',
            'approved_by' => $request->user()?->id,
            'approved_at' => now(),
        ]);

        return new ExpenseResource($expense->load($this->relations()));
    }

    public function reject(Request $request, Expense $expense)
    {
        if ($expense->status !== 'pending') {
            return response()->json(['message' => 'Only pending expenses can be rejected.'], 422);
        }
        $data = $request->validate(['rejection_reason' => ['nullable', 'string', 'max:1000']]);
        $expense->update([
            'status' => 'rejected',
            'rejection_reason' => $data['rejection_reason'] ?? null,
            'approved_by' => $request->user()?->id,
            'approved_at' => now(),
        ]);

        return new ExpenseResource($expense->load($this->relations()));
    }

    /** Mark an approved expense as paid (or unpaid). */
    public function markPaid(Request $request, Expense $expense)
    {
        if ($expense->status !== 'approved') {
            return response()->json(['message' => 'Only approved expenses can be marked paid.'], 422);
        }
        $paid = $request->boolean('paid', true);
        $expense->update(['paid_at' => $paid ? now() : null]);

        return new ExpenseResource($expense->load($this->relations()));
    }

    /** Attach (or replace) a scanned bill/receipt on the tenant public disk. */
    public function uploadAttachment(Request $request, Expense $expense)
    {
        $request->validate([
            'attachment' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
        ]);

        $disk = TenantStorage::disk();
        if ($expense->attachment_path) {
            $disk->delete($expense->attachment_path);
        }
        $path = $disk->putFile('expenses', $request->file('attachment'));
        $expense->update(['attachment_path' => $path]);

        return new ExpenseResource($expense->load($this->relations()));
    }

    /**
     * Expense ledger: chronological list with a running total, honouring the
     * same filters as the index. Oldest-first so the running total accrues.
     */
    public function ledger(Request $request)
    {
        $query = Expense::query()->with(['category:id,name', 'branch:id,name']);
        $this->applyFilters($query, $request);

        $running = 0.0;
        $rows = $query->orderBy('expense_date')->orderBy('id')->get()->map(function ($e) use (&$running) {
            $running += (float) $e->amount;

            return [
                'id' => $e->id,
                'expense_number' => $e->expense_number,
                'expense_date' => $e->expense_date?->toDateString(),
                'category' => $e->category?->name,
                'branch' => $e->branch?->name,
                'payee' => $e->payee,
                'payment_method' => $e->payment_method,
                'status' => $e->status,
                'is_paid' => $e->paid_at !== null,
                'amount' => (float) $e->amount,
                'running_total' => round($running, 2),
            ];
        });

        return response()->json(['data' => $rows, 'total' => round($running, 2)]);
    }

    /**
     * Expense report: a filterable, exportable detail list of expenses. Defaults
     * to approved spend; honours the same filters as the summaries. Paginated
     * for the screen, or the full set through the export pipeline.
     */
    public function report(Request $request)
    {
        $query = $this->reportBase($request)->with(['category:id,name', 'branch:id,name'])
            ->orderByDesc('expense_date')->orderByDesc('id');

        $map = fn (Expense $e) => [
            'expense_number' => $e->expense_number,
            'expense_date' => $e->expense_date?->toDateString(),
            'category' => $e->category?->name,
            'branch' => $e->branch?->name ?? 'Unassigned',
            'payee' => $e->payee,
            'payment_method' => $e->payment_method,
            'status' => $e->status,
            'amount' => (float) $e->amount,
        ];

        $columns = [
            'expense_number' => 'Expense No', 'expense_date' => 'Date', 'category' => 'Category',
            'branch' => 'Branch', 'payee' => 'Payee', 'payment_method' => 'Method',
            'status' => 'Status', 'amount' => 'Amount',
        ];

        if ($request->query('format', 'json') === 'json') {
            $grandTotal = (float) (clone $query)->sum('amount');
            $perPage = min((int) $request->query('per_page', 20), 200);
            $page = $query->paginate($perPage);
            $page->getCollection()->transform($map);

            return response()->json(['data' => $page->items(), 'meta' => [
                'current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(),
                'grand_total' => round($grandTotal, 2),
            ]]);
        }

        $rows = $query->limit(self::EXPORT_LIMIT)->get()->map($map)->all();

        return $this->exportResponse($request, $rows, $columns, 'expense-report');
    }

    /** Month-wise approved spend (optionally filtered by date range / branch / category). */
    public function monthlyReport(Request $request)
    {
        $rows = $this->reportBase($request)
            ->selectRaw("DATE_FORMAT(expense_date, '%Y-%m') as period, COUNT(*) as count, SUM(amount) as total")
            ->groupBy('period')
            ->orderBy('period')
            ->get()
            ->map(fn ($r) => [
                'period' => $r->period,
                'count' => (int) $r->count,
                'total' => (float) $r->total,
            ]);

        return response()->json(['data' => $rows, 'total' => round($rows->sum('total'), 2)]);
    }

    /** Branch-wise approved spend. */
    public function branchReport(Request $request)
    {
        $rows = $this->reportBase($request)
            ->leftJoin('branches', 'branches.id', '=', 'expenses.branch_id')
            ->selectRaw('expenses.branch_id, branches.name as branch, COUNT(*) as count, SUM(expenses.amount) as total')
            ->groupBy('expenses.branch_id', 'branches.name')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($r) => [
                'branch_id' => $r->branch_id,
                'branch' => $r->branch ?? 'Unassigned',
                'count' => (int) $r->count,
                'total' => (float) $r->total,
            ]);

        return response()->json(['data' => $rows, 'total' => round($rows->sum('total'), 2)]);
    }

    /** Category-wise approved spend. */
    public function categoryReport(Request $request)
    {
        $rows = $this->reportBase($request)
            ->join('expense_categories', 'expense_categories.id', '=', 'expenses.expense_category_id')
            ->selectRaw('expenses.expense_category_id, expense_categories.name as category, COUNT(*) as count, SUM(expenses.amount) as total')
            ->groupBy('expenses.expense_category_id', 'expense_categories.name')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($r) => [
                'expense_category_id' => $r->expense_category_id,
                'category' => $r->category,
                'count' => (int) $r->count,
                'total' => (float) $r->total,
            ]);

        return response()->json(['data' => $rows, 'total' => round($rows->sum('total'), 2)]);
    }

    /** Base query for the summary reports: approved spend within the given filters. */
    private function reportBase(Request $request)
    {
        $query = Expense::query();
        // Reports summarise committed spend; default to approved only.
        $query->where('expenses.status', $request->query('status', 'approved'));

        if ($request->filled('date_from')) {
            $query->whereDate('expense_date', '>=', $request->query('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('expense_date', '<=', $request->query('date_to'));
        }
        if ($request->filled('branch_id')) {
            $query->where('expenses.branch_id', $request->query('branch_id'));
        }
        if ($request->filled('expense_category_id')) {
            $query->where('expenses.expense_category_id', $request->query('expense_category_id'));
        }

        return $query;
    }

    private function applyFilters($query, Request $request): void
    {
        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('expense_number', 'like', "%{$search}%")
                    ->orWhere('payee', 'like', "%{$search}%")
                    ->orWhere('reference_no', 'like', "%{$search}%");
            });
        }
        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }
        if ($request->filled('expense_category_id')) {
            $query->where('expense_category_id', $request->query('expense_category_id'));
        }
        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->query('branch_id'));
        }
        if ($request->filled('date_from')) {
            $query->whereDate('expense_date', '>=', $request->query('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('expense_date', '<=', $request->query('date_to'));
        }
    }

    private function relations(): array
    {
        return ['category', 'branch', 'creator', 'approver'];
    }
}
