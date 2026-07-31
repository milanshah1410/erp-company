<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectTaskResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'project' => $this->whenLoaded('project', fn () => $this->project ? [
                'id' => $this->project->id,
                'project_number' => $this->project->project_number,
                'name' => $this->project->name,
            ] : null),
            'parent_id' => $this->parent_id,
            'assigned_to' => $this->assigned_to,
            'assignee' => $this->whenLoaded('assignee', fn () => $this->assignee ? [
                'id' => $this->assignee->id,
                'name' => $this->assignee->fullName(),
            ] : null),

            'title' => $this->title,
            'description' => $this->description,
            'status' => $this->status,
            'priority' => $this->priority,
            'estimated_hours' => (float) $this->estimated_hours,
            'progress_percent' => (int) $this->progress_percent,
            'start_date' => $this->start_date?->toDateString(),
            'due_date' => $this->due_date?->toDateString(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'is_overdue' => $this->resource->isOverdue(),
            'sort_order' => (int) $this->sort_order,

            'hours_logged' => $this->timesheets_sum_hours !== null
                ? round((float) $this->timesheets_sum_hours, 2)
                : null,
            'subtasks' => ProjectTaskResource::collection($this->whenLoaded('subtasks')),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
