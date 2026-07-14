<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FactoryMaintenanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'branch_id' => ['nullable', 'exists:branches,id'],
            'asset_id' => ['nullable', 'exists:assets,id'],
            'area' => ['nullable', 'string', 'max:255'],
            'maintenance_type' => ['required', Rule::in(['preventive', 'breakdown', 'routine', 'installation', 'other'])],
            'maintenance_date' => ['required', 'date'],
            'performed_by' => ['nullable', 'string', 'max:255'],
            'downtime_hours' => ['nullable', 'numeric', 'min:0'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'payment_method' => ['required', Rule::in(['cash', 'bank', 'upi', 'card', 'cheque', 'other'])],
            'reference_no' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ];
    }
}
