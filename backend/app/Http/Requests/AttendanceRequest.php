<?php

namespace App\Http\Requests;

use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AttendanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $attendance = $this->route('attendance');
        $tenantId = app(TenantContext::class)->id();
        $employeeId = $this->input('employee_id', $attendance?->employee_id);

        return [
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'date' => [
                'required', 'date',
                // One attendance row per employee per day.
                Rule::unique('attendances', 'date')
                    ->where('tenant_id', $tenantId)
                    ->where('employee_id', $employeeId)
                    ->ignore($attendance?->id),
            ],
            'status' => ['sometimes', Rule::in(['present', 'absent', 'half-day', 'leave', 'holiday', 'week-off'])],
            'check_in' => ['nullable', 'date_format:H:i'],
            'check_out' => ['nullable', 'date_format:H:i', 'after_or_equal:check_in'],
            'remarks' => ['nullable', 'string', 'max:255'],
        ];
    }
}
