<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectTimesheetResource extends JsonResource
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
            'project_task_id' => $this->project_task_id,
            'task' => $this->whenLoaded('task', fn () => $this->task?->title),
            'employee_id' => $this->employee_id,
            'employee' => $this->whenLoaded('employee', fn () => $this->employee ? [
                'id' => $this->employee->id,
                'name' => $this->employee->fullName(),
            ] : null),

            'work_date' => $this->work_date?->toDateString(),
            'hours' => (float) $this->hours,
            'description' => $this->description,
            'is_billable' => (bool) $this->is_billable,
            'hourly_rate' => (float) $this->hourly_rate,
            'amount' => (float) $this->amount,

            'status' => $this->status,
            'approved_at' => $this->approved_at?->toIso8601String(),
            'invoice_id' => $this->invoice_id,
            'invoice' => $this->whenLoaded('invoice', fn () => $this->invoice?->invoice_number),
            // Approved, billable and not yet carried out on an invoice — what
            // the "bill hours" button actually acts on.
            'is_invoiceable' => $this->resource->isInvoiceable(),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
