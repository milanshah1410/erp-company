<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssetMaintenanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'asset_id' => ['required', 'exists:assets,id'],
            'branch_id' => ['nullable', 'exists:branches,id'],
            'maintenance_type' => ['required', Rule::in(['preventive', 'repair', 'calibration', 'amc', 'inspection', 'other'])],
            'maintenance_date' => ['required', 'date'],
            'next_service_date' => ['nullable', 'date'],
            'performed_by' => ['nullable', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'payment_method' => ['required', Rule::in(['cash', 'bank', 'upi', 'card', 'cheque', 'other'])],
            'reference_no' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ];
    }
}
