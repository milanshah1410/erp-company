<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProjectRequest;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use App\Services\ProjectCostingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Projects: the budgeted work, its cost sheet, and the invoice its billable
 * hours turn into.
 *
 * Tasks and timesheets live in their own controllers; what belongs here is the
 * header, the roll-up of everything booked to it, and the two transitions that
 * carry side effects — status changes stamp the actual dates, and billing
 * stamps the timesheets it carried out.
 */
class ProjectController extends Controller
{
    public function __construct(private readonly ProjectCostingService $costing) {}

    public function index(Request $request)
    {
        $query = Project::query()
            ->with(['customer:id,name', 'manager:id,first_name,last_name'])
            ->withCount('tasks');

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('project_number', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$search}%"));
            });
        }
        foreach (['status', 'priority', 'customer_id', 'manager_id', 'billing_type'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->query($filter));
            }
        }
        if ($request->boolean('open_only')) {
            $query->whereNotIn('status', ['completed', 'cancelled']);
        }

        $query->orderByDesc('id');
        $perPage = min((int) $request->query('per_page', 15), 100);
        $page = $query->paginate($perPage);

        // The list is where a manager scans for trouble, so every row carries
        // its cost sheet — batched, so the page costs a fixed few queries
        // regardless of how many projects are on it.
        $sheets = $this->costing->costSheetsFor($page->getCollection());
        $page->getCollection()->each(function (Project $project) use ($sheets) {
            $project->costing = $sheets[$project->id] ?? null;
        });

        return ProjectResource::collection($page)
            ->additional(['summary' => $this->summary()]);
    }

    public function store(ProjectRequest $request)
    {
        $data = $request->validated();

        $project = Project::create($data + [
            'project_number' => Project::nextNumber(),
            'status' => $data['status'] ?? 'planning',
            'priority' => $data['priority'] ?? 'normal',
            'billing_type' => $data['billing_type'] ?? 'non_billable',
            'budget_amount' => $data['budget_amount'] ?? 0,
            'estimated_hours' => $data['estimated_hours'] ?? 0,
            'hourly_rate' => $data['hourly_rate'] ?? 0,
            'created_by' => $request->user()?->id,
        ]);

        return (new ProjectResource($project->load($this->relations())))
            ->response()->setStatusCode(201);
    }

    public function show(Project $project)
    {
        $project->load($this->relations())->loadCount('tasks');
        $project->costing = $this->costing->costSheet($project);

        return new ProjectResource($project);
    }

    public function update(ProjectRequest $request, Project $project)
    {
        if ($project->isClosed()) {
            return response()->json([
                'message' => 'A completed or cancelled project can no longer be edited.',
            ], 422);
        }

        $project->update($request->validated());
        $project->load($this->relations());
        $project->costing = $this->costing->costSheet($project);

        return new ProjectResource($project);
    }

    public function destroy(Project $project)
    {
        if ($project->timesheets()->exists()) {
            return response()->json([
                'message' => 'Hours have been logged against this project. Cancel it instead of deleting it.',
            ], 422);
        }
        if ($project->expenses()->exists()) {
            return response()->json([
                'message' => 'Expenses are booked to this project. Cancel it instead of deleting it.',
            ], 422);
        }

        $project->delete();

        return response()->json(['message' => 'Project deleted.']);
    }

    /**
     * Move the project through its lifecycle. Kept off `update` because the
     * transition stamps the actual dates — starting sets the real start, closing
     * sets the real end, and neither should depend on someone typing them in.
     */
    public function transition(Request $request, Project $project)
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['planning', 'active', 'on_hold', 'completed', 'cancelled'])],
        ]);

        $status = $data['status'];

        if ($project->isClosed() && ! in_array($status, Project::CLOSED_STATUSES, true)) {
            return response()->json(['message' => 'A closed project cannot be reopened.'], 422);
        }

        $project->forceFill([
            'status' => $status,
            'actual_start_date' => $project->actual_start_date
                ?? ($status === 'active' ? now()->toDateString() : null),
            'actual_end_date' => in_array($status, Project::CLOSED_STATUSES, true)
                ? ($project->actual_end_date ?? now()->toDateString())
                : null,
        ])->save();

        $project->load($this->relations());
        $project->costing = $this->costing->costSheet($project);

        return new ProjectResource($project);
    }

    /** The cost sheet on its own, for the detail panel's refresh. */
    public function costing(Project $project)
    {
        return response()->json(['data' => $this->costing->costSheet($project)]);
    }

    /**
     * Invoice the project's approved, billable, un-invoiced hours. Refuses
     * loudly when there is nothing eligible, so a manager is never left
     * wondering whether the click landed.
     */
    public function bill(Request $request, Project $project)
    {
        $data = $request->validate([
            'timesheet_ids' => ['nullable', 'array'],
            'timesheet_ids.*' => ['integer'],
        ]);

        if (! $project->billsByTheHour()) {
            return response()->json([
                'message' => 'Only a time-and-material project with a customer can bill hours.',
            ], 422);
        }

        try {
            $invoice = DB::transaction(fn () => $this->costing->billApprovedHours(
                $project,
                $data['timesheet_ids'] ?? null,
                $request->user()?->id,
            ));
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['project' => [$e->getMessage()]]);
        }

        if (! $invoice) {
            return response()->json([
                'message' => 'No approved, billable and un-invoiced hours to bill.',
            ], 422);
        }

        $project->load($this->relations());
        $project->costing = $this->costing->costSheet($project);

        return (new ProjectResource($project))->additional(['invoice' => [
            'id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'total' => (float) $invoice->total,
            'status' => $invoice->status,
        ]]);
    }

    /** Portfolio headline: how many are running, and how many are over budget. */
    private function summary(): array
    {
        $byStatus = Project::query()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return [
            'by_status' => $byStatus,
            'open' => (int) $byStatus->except(['completed', 'cancelled'])->sum(),
            'over_budget' => $this->costing->overBudgetCount(),
            'total_budget' => (float) Project::whereNotIn('status', ['cancelled'])->sum('budget_amount'),
        ];
    }

    /** @return list<string> */
    private function relations(): array
    {
        return [
            'customer:id,name',
            'manager:id,first_name,last_name',
            'creator:id,name',
        ];
    }
}
