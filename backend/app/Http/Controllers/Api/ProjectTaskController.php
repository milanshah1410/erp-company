<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProjectTaskRequest;
use App\Http\Resources\ProjectTaskResource;
use App\Models\Project;
use App\Models\ProjectTask;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Tasks on a project. Flat list by default, one level of nesting available for
 * checklists under a task.
 *
 * `move` exists next to `update` because a board drags a card far more often
 * than it opens an edit form, and that path should not have to resend the whole
 * task to change one column.
 */
class ProjectTaskController extends Controller
{
    public function index(Request $request)
    {
        $query = ProjectTask::query()
            ->with(['assignee:id,first_name,last_name', 'project:id,project_number,name'])
            ->withSum('timesheets', 'hours');

        foreach (['project_id', 'status', 'priority', 'assigned_to', 'parent_id'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->query($filter));
            }
        }
        if ($search = $request->query('search')) {
            $query->where('title', 'like', "%{$search}%");
        }
        // The board asks for top-level cards; subtasks arrive with their parent.
        if ($request->boolean('roots_only')) {
            $query->whereNull('parent_id');
        }
        if ($request->boolean('overdue_only')) {
            $query->whereNotNull('due_date')->where('due_date', '<', now()->toDateString())
                ->whereNot('status', 'done');
        }

        $query->orderBy('sort_order')->orderBy('id');
        $perPage = min((int) $request->query('per_page', 50), 200);

        return ProjectTaskResource::collection($query->paginate($perPage));
    }

    public function store(ProjectTaskRequest $request)
    {
        $data = $request->validated();

        if ($closed = $this->closedProjectResponse($data['project_id'])) {
            return $closed;
        }

        $task = new ProjectTask($data + [
            'status' => $data['status'] ?? 'todo',
            'priority' => $data['priority'] ?? 'normal',
            'estimated_hours' => $data['estimated_hours'] ?? 0,
            'progress_percent' => $data['progress_percent'] ?? 0,
            // Append to the end of the column unless the caller placed it.
            'sort_order' => $data['sort_order'] ?? $this->nextSortOrder((int) $data['project_id']),
            'created_by' => $request->user()?->id,
        ]);
        $task->syncProgress();
        $task->save();

        return (new ProjectTaskResource($task->load($this->relations())))
            ->response()->setStatusCode(201);
    }

    public function show(ProjectTask $projectTask)
    {
        return new ProjectTaskResource(
            $projectTask->load([...$this->relations(), 'subtasks.assignee:id,first_name,last_name']),
        );
    }

    public function update(ProjectTaskRequest $request, ProjectTask $projectTask)
    {
        $data = $request->validated();

        if ($closed = $this->closedProjectResponse($projectTask->project_id)) {
            return $closed;
        }

        $projectTask->fill($data);
        $projectTask->syncProgress();
        $projectTask->save();

        return new ProjectTaskResource($projectTask->load($this->relations()));
    }

    public function destroy(ProjectTask $projectTask)
    {
        if ($projectTask->timesheets()->exists()) {
            return response()->json([
                'message' => 'Hours are logged against this task. Reassign or delete them first.',
            ], 422);
        }

        // Subtasks cascade at the database level; saying so beats a silent
        // disappearance of work someone else was tracking.
        $projectTask->delete();

        return response()->json(['message' => 'Task deleted.']);
    }

    /**
     * Board drag: change a task's column and its place in it. Status and
     * progress are reconciled by the model, so dropping a card on "Done" is
     * enough to close it out.
     */
    public function move(Request $request, ProjectTask $projectTask)
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['todo', 'in_progress', 'review', 'blocked', 'done'])],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'progress_percent' => ['nullable', 'integer', 'min:0', 'max:100'],
        ]);

        if ($closed = $this->closedProjectResponse($projectTask->project_id)) {
            return $closed;
        }

        $projectTask->status = $data['status'];
        if (array_key_exists('progress_percent', $data) && $data['progress_percent'] !== null) {
            $projectTask->progress_percent = $data['progress_percent'];
        }
        if (array_key_exists('sort_order', $data) && $data['sort_order'] !== null) {
            $projectTask->sort_order = $data['sort_order'];
        }
        $projectTask->syncProgress();
        $projectTask->save();

        return new ProjectTaskResource($projectTask->load($this->relations()));
    }

    /** Persist a whole column's order in one call after a drag. */
    public function reorder(Request $request)
    {
        $data = $request->validate([
            'tasks' => ['required', 'array', 'min:1'],
            'tasks.*.id' => ['required', 'integer', 'exists:project_tasks,id'],
            'tasks.*.sort_order' => ['required', 'integer', 'min:0'],
        ]);

        DB::transaction(function () use ($data) {
            foreach ($data['tasks'] as $row) {
                // Scoped through the model so the tenant scope still applies —
                // a bare update by id would reach across workspaces.
                ProjectTask::whereKey($row['id'])->update(['sort_order' => $row['sort_order']]);
            }
        });

        return response()->json(['message' => 'Order saved.']);
    }

    private function nextSortOrder(int $projectId): int
    {
        return (int) ProjectTask::where('project_id', $projectId)->max('sort_order') + 1;
    }

    /** Tasks cannot be added to or moved on a project that is already closed. */
    private function closedProjectResponse(int $projectId)
    {
        $project = Project::find($projectId);

        return $project?->isClosed()
            ? response()->json([
                'message' => 'This project is completed or cancelled; its tasks can no longer change.',
            ], 422)
            : null;
    }

    /** @return list<string> */
    private function relations(): array
    {
        return ['assignee:id,first_name,last_name', 'project:id,project_number,name'];
    }
}
