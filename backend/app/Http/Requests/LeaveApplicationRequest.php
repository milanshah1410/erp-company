<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class LeaveApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** Derive the leave-day count from the date range (or 0.5 for a half-day). */
    protected function prepareForValidation(): void
    {
        if ($this->filled('from_date') && $this->filled('to_date')) {
            try {
                $from = Carbon::parse($this->input('from_date'))->startOfDay();
                $to = Carbon::parse($this->input('to_date'))->startOfDay();
                $days = $this->boolean('half_day') ? 0.5 : $from->diffInDays($to) + 1;
                $this->merge(['days' => $days]);
            } catch (\Throwable) {
                // leave unset; rules will flag the bad dates.
            }
        }
    }

    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'leave_type_id' => ['required', 'integer', 'exists:leave_types,id'],
            'from_date' => ['required', 'date'],
            'to_date' => ['required', 'date', 'after_or_equal:from_date'],
            'days' => ['required', 'numeric', 'min:0.5'],
            'half_day' => ['sometimes', 'boolean'],
            'reason' => ['nullable', 'string', 'max:1000'],
            'status' => ['sometimes', Rule::in(['pending', 'approved', 'rejected', 'cancelled'])],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
