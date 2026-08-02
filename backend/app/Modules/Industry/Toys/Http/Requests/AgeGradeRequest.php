<?php

declare(strict_types=1);

namespace App\Modules\Industry\Toys\Http\Requests;

use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AgeGradeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware handles permission checks
    }

    public function rules(): array
    {
        $id = $this->route('grade')?->id;
        $tenantId = app(TenantContext::class)->id();

        return [
            'code' => [
                'required', 'string', 'max:40',
                Rule::unique('toy_age_grades', 'code')->where('tenant_id', $tenantId)->ignore($id),
            ],
            'label' => ['required', 'string', 'max:80'],

            /**
             * 0–216 months: birth to eighteen. The ceiling is not fussiness — a
             * band typed in years by mistake ("3" meaning three years) lands at
             * three months and puts a scooter on the newborn shelf, and the only
             * place that can be caught is here.
             */
            'min_months' => ['required', 'integer', 'min:0', 'max:216'],
            'max_months' => ['nullable', 'integer', 'min:0', 'max:216', 'gte:min_months'],

            'warning' => ['nullable', 'string', 'max:255'],
            'requires_supervision' => ['boolean'],
            'description' => ['nullable', 'string'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.unique' => 'An age band with this code already exists.',
            'max_months.gte' => 'The band cannot end before it begins. Leave the upper end blank for an open band such as "14+".',
        ];
    }
}
