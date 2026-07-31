<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_number' => $this->project_number,
            'name' => $this->name,

            'customer_id' => $this->customer_id,
            'customer' => $this->whenLoaded('customer', fn () => $this->customer ? [
                'id' => $this->customer->id,
                'name' => $this->customer->name,
            ] : null),
            'manager_id' => $this->manager_id,
            'manager' => $this->whenLoaded('manager', fn () => $this->manager ? [
                'id' => $this->manager->id,
                'name' => $this->manager->fullName(),
            ] : null),

            'status' => $this->status,
            'priority' => $this->priority,
            'billing_type' => $this->billing_type,
            'bills_by_the_hour' => $this->resource->billsByTheHour(),

            'budget_amount' => (float) $this->budget_amount,
            'estimated_hours' => (float) $this->estimated_hours,
            'hourly_rate' => (float) $this->hourly_rate,

            'start_date' => $this->start_date?->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'actual_start_date' => $this->actual_start_date?->toDateString(),
            'actual_end_date' => $this->actual_end_date?->toDateString(),
            'is_overdue' => $this->end_date
                && $this->end_date->isPast()
                && ! in_array($this->status, ['completed', 'cancelled'], true),

            'description' => $this->description,
            'notes' => $this->notes,
            'tasks_count' => $this->tasks_count ?? null,
            'creator' => $this->whenLoaded('creator', fn () => $this->creator?->name),

            // Attached by the controller for show/index; absent elsewhere so the
            // cost sheet is never computed for a payload that will not use it.
            'costing' => $this->when($this->resource->costing !== null, fn () => $this->resource->costing),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
