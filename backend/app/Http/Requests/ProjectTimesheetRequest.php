<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProjectTimesheetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'project_id' => ['required', 'exists:projects,id'],
            'project_task_id' => ['nullable', 'exists:project_tasks,id'],
            'employee_id' => ['required', 'exists:employees,id'],
            'work_date' => ['required', 'date'],
            // A 24-hour ceiling per entry: anything above it is a typo, and
            // splitting a long stint across days is what the date is for.
            'hours' => ['required', 'numeric', 'min:0.01', 'max:24'],
            'description' => ['nullable', 'string'],
            'is_billable' => ['nullable', 'boolean'],
            // Blank falls back to the project's rate (ProjectCostingService).
            'hourly_rate' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
