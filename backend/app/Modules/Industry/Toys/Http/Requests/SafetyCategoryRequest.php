<?php

declare(strict_types=1);

namespace App\Modules\Industry\Toys\Http\Requests;

use App\Modules\Industry\Toys\Models\SafetyCategory;
use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SafetyCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware handles permission checks
    }

    public function rules(): array
    {
        $id = $this->route('category')?->id;
        $tenantId = app(TenantContext::class)->id();

        return [
            'code' => [
                'required', 'string', 'max:40',
                Rule::unique('toy_safety_categories', 'code')->where('tenant_id', $tenantId)->ignore($id),
            ],
            'label' => ['required', 'string', 'max:80'],
            'standard' => ['required', Rule::in(array_keys(SafetyCategory::STANDARDS))],

            'hazards' => ['nullable', 'array'],
            'hazards.*' => [Rule::in(array_keys(SafetyCategory::HAZARDS))],
            'warning_text' => ['nullable', 'string', 'max:255'],

            'min_age_months' => ['nullable', 'integer', 'min:0', 'max:216'],
            'requires_certificate' => ['boolean'],
            /**
             * The switch that turns this register into a gate. Accepted freely
             * here — deciding that an uncertified article may not be sold is
             * exactly the decision this screen exists to let a shop make — but
             * note that it only bites when the module is on and the article
             * carries the category.
             */
            'blocks_sale_without_certificate' => ['boolean'],

            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.unique' => 'A safety class with this code already exists.',
        ];
    }
}
