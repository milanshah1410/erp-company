<?php

declare(strict_types=1);

namespace App\Modules\Industry\Healthcare\Http\Requests;

use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for the test master.
 *
 * The rule that matters clinically: a low bound above a high bound is not a
 * range, it is a report that will flag every result it ever sees. Caught here
 * because nothing downstream can tell the difference between an inverted range
 * and a genuinely alarming lab.
 */
class LabTestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $testId = $this->route('labTest')?->id;

        return [
            'code' => [
                'required', 'string', 'max:40',
                Rule::unique('lab_tests', 'code')
                    ->where('tenant_id', app(TenantContext::class)->id())
                    ->ignore($testId),
            ],
            'name' => ['required', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:80'],
            'sample_type' => ['nullable', 'string', 'max:40'],
            'container' => ['nullable', 'string', 'max:80'],
            'method' => ['nullable', 'string', 'max:120'],
            'unit' => ['nullable', 'string', 'max:40'],
            'reference_range' => ['nullable', 'string', 'max:160'],
            'ref_low' => ['nullable', 'numeric'],
            'ref_high' => ['nullable', 'numeric', 'gte:ref_low'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'tax_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'tat_hours' => ['nullable', 'integer', 'min:0', 'max:8760'],
            'is_panel' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],

            // Panel membership. Only meaningful when is_panel is set; the
            // controller ignores it otherwise rather than erroring, so toggling
            // a panel back to a plain test does not require emptying the list
            // first.
            'components' => ['nullable', 'array'],
            'components.*' => [
                'integer',
                Rule::exists('lab_tests', 'id')->where('tenant_id', app(TenantContext::class)->id()),
                // A panel containing itself would report a parameter that is the
                // panel, and expand forever the day nested panels are added.
                Rule::notIn(array_filter([$testId])),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'code.unique' => 'Another test already uses this code.',
            'ref_high.gte' => 'The upper bound cannot be below the lower bound.',
            'components.*.not_in' => 'A panel cannot contain itself.',
        ];
    }
}
