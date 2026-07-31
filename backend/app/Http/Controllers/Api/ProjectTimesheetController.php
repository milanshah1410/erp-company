<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProjectTimesheetRequest;
use App\Http\Resources\ProjectTimesheetResource;
use App\Models\Project;
use App\Models\ProjectTimesheet;
use App\Services\ProjectCostingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Timesheets: the hours behind a project's labour cost.
 *
 * Entries move draft → submitted → approved (or rejected), and only approved
 * ones can be invoiced. Once an entry has left on an invoice it is frozen —
 * editing billed hours would restate a document already with the customer.
 */
class ProjectTimesheetController extends Controller
{
    public function __construct(private readonly ProjectCostingService $costing) {}

    public function index(Request $request)
    {
        $query = ProjectTimesheet::query()
            ->with([
                'project:id,project_number,name',
                'task:id,title',
                'employee:id,first_name,last_name',
                'invoice:id,invoice_number',
            ]);

        foreach (['project_id', 'project_task_id', 'employee_id', 'status'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->query($filter));
            }
        }
        if ($request->filled('from')) {
            $query->where('work_date', '>=', $request->query('from'));
        }
        if ($request->filled('to')) {
            $query->where('work_date', '<=', $request->query('to'));
        }
        if ($request->boolean('billable_only')) {
            $query->where('is_billable', true);
        }
        // The "ready to bill" view: approved, billable, not yet on an invoice.
        if ($request->boolean('invoiceable_only')) {
            $query->where('status', ProjectTimesheet::BILLABLE_STATUS)
                ->where('is_billable', true)
                ->whereNull('invoice_id');
        }

        $query->orderByDesc('work_date')->orderByDesc('id');
        $perPage = min((int) $request->query('per_page', 25), 100);
        $page = $query->paginate($perPage);

        return ProjectTimesheetResource::collection($page)
            ->additional(['totals' => $this->totals($request)]);
    }

    public function store(ProjectTimesheetRequest $request)
    {
        $data = $request->validated();
        $project = Project::findOrFail($data['project_id']);

        if ($project->isClosed()) {
            return response()->json([
                'message' => 'This project is completed or cancelled; hours can no longer be logged against it.',
            ], 422);
        }

        $entry = new ProjectTimesheet($data + [
            'is_billable' => $data['is_billable'] ?? true,
            'hourly_rate' => $data['hourly_rate'] ?? 0,
            'status' => 'draft',
            'created_by' => $request->user()?->id,
        ]);
        $this->costing->valueEntry($entry, $project);
        $entry->save();

        return (new ProjectTimesheetResource($entry->load($this->relations())))
            ->response()->setStatusCode(201);
    }

    public function show(ProjectTimesheet $projectTimesheet)
    {
        return new ProjectTimesheetResource($projectTimesheet->load($this->relations()));
    }

    public function update(ProjectTimesheetRequest $request, ProjectTimesheet $projectTimesheet)
    {
        if ($frozen = $this->frozenResponse($projectTimesheet)) {
            return $frozen;
        }

        $data = $request->validated();
        $project = Project::findOrFail($data['project_id']);

        $projectTimesheet->fill($data);
        $this->costing->valueEntry($projectTimesheet, $project);
        // An edited entry goes back to draft: whoever approved the old numbers
        // did not approve these.
        $projectTimesheet->forceFill(['status' => 'draft', 'approved_by' => null, 'approved_at' => null]);
        $projectTimesheet->save();

        return new ProjectTimesheetResource($projectTimesheet->load($this->relations()));
    }

    public function destroy(ProjectTimesheet $projectTimesheet)
    {
        if ($frozen = $this->frozenResponse($projectTimesheet)) {
            return $frozen;
        }

        $projectTimesheet->delete();

        return response()->json(['message' => 'Timesheet entry deleted.']);
    }

    /** Hand entries to a manager for approval. */
    public function submit(Request $request)
    {
        return $this->transition($request, 'submitted', ['draft', 'rejected']);
    }

    /** Approve entries — what makes billable hours invoiceable. */
    public function approve(Request $request)
    {
        return $this->transition($request, 'approved', ['draft', 'submitted']);
    }

    public function reject(Request $request)
    {
        return $this->transition($request, 'rejected', ['draft', 'submitted', 'approved']);
    }

    /**
     * Move a batch of entries to a status, skipping any that are not in a state
     * the move applies to (and any already carried out on an invoice).
     *
     * Batched because approval is a weekly act over a timesheet grid, not a
     * per-row one.
     *
     * @param  list<string>  $from  Statuses this transition is valid from.
     */
    private function transition(Request $request, string $to, array $from)
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);

        $moved = DB::transaction(function () use ($data, $to, $from, $request) {
            $entries = ProjectTimesheet::whereIn('id', $data['ids'])
                ->whereIn('status', $from)
                ->whereNull('invoice_id')
                ->get();

            foreach ($entries as $entry) {
                $entry->forceFill([
                    'status' => $to,
                    'approved_by' => $to === 'approved' ? $request->user()?->id : null,
                    'approved_at' => $to === 'approved' ? now() : null,
                ])->save();
            }

            return $entries->count();
        });

        return response()->json([
            'message' => "{$moved} entr".($moved === 1 ? 'y' : 'ies')." moved to {$to}.",
            'moved' => $moved,
            'skipped' => count($data['ids']) - $moved,
        ]);
    }

    /** Hours and value for the current filter, so the grid can show a footer. */
    private function totals(Request $request): array
    {
        $query = ProjectTimesheet::query();

        foreach (['project_id', 'project_task_id', 'employee_id', 'status'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->query($filter));
            }
        }
        if ($request->filled('from')) {
            $query->where('work_date', '>=', $request->query('from'));
        }
        if ($request->filled('to')) {
            $query->where('work_date', '<=', $request->query('to'));
        }

        $row = $query
            ->selectRaw('COALESCE(SUM(hours), 0) as hours')
            ->selectRaw('COALESCE(SUM(amount), 0) as amount')
            ->selectRaw('COALESCE(SUM(CASE WHEN is_billable = 1 THEN hours ELSE 0 END), 0) as billable_hours')
            ->first();

        return [
            'hours' => round((float) $row->hours, 2),
            'amount' => round((float) $row->amount, 2),
            'billable_hours' => round((float) $row->billable_hours, 2),
        ];
    }

    /** Billed hours are frozen: the customer already has the document. */
    private function frozenResponse(ProjectTimesheet $entry)
    {
        return $entry->invoice_id
            ? response()->json([
                'message' => 'These hours have been invoiced and can no longer be changed.',
            ], 422)
            : null;
    }

    /** @return list<string> */
    private function relations(): array
    {
        return [
            'project:id,project_number,name',
            'task:id,title',
            'employee:id,first_name,last_name',
            'invoice:id,invoice_number',
        ];
    }
}
