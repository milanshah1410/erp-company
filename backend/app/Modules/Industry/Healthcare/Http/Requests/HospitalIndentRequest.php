<?php

declare(strict_types=1);

namespace App\Modules\Industry\Healthcare\Http\Requests;

use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for a ward indent.
 *
 * Lines carry an optional `id`. That is not decoration: an indent stays editable
 * while it is a draft, and the edit form resends every line it is displaying —
 * so a line that came back without its id would be created a second time and the
 * ward would appear to have asked for double. The controller matches on the id
 * and refuses an edit that has lost one.
 */
class HospitalIndentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->id();

        return [
            'department_id' => [
                'required',
                Rule::exists('departments', 'id')->where('tenant_id', $tenantId),
            ],
            'warehouse_id' => [
                'required',
                Rule::exists('warehouses', 'id')->where('tenant_id', $tenantId),
            ],
            'indent_date' => ['required', 'date'],
            'required_by' => ['nullable', 'date', 'after_or_equal:indent_date'],
            'priority' => ['nullable', Rule::in(['routine', 'urgent', 'emergency'])],
            'notes' => ['nullable', 'string', 'max:2000'],

            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['nullable', 'integer'],
            'items.*.product_id' => [
                'required',
                Rule::exists('products', 'id')->where('tenant_id', $tenantId),
            ],
            'items.*.variant_id' => ['nullable', 'integer'],
            'items.*.batch_id' => ['nullable', 'integer'],
            'items.*.requested_quantity' => ['required', 'numeric', 'min:0.001'],
            'items.*.remarks' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'items.required' => 'An indent needs at least one item.',
            'required_by.after_or_equal' => 'The required-by date cannot be before the indent date.',
        ];
    }
}
