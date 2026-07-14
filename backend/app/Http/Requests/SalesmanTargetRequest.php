<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SalesmanTargetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware handles permission checks
    }

    public function rules(): array
    {
        $targetId = $this->route('salesmanTarget')?->id;

        return [
            'salesman_id' => ['required', 'integer', 'exists:salesmen,id'],
            // One target per salesman per month: scope the uniqueness of the
            // year to this salesman + month combination.
            'period_year' => [
                'required', 'integer', 'min:2000', 'max:2100',
                Rule::unique('salesman_targets', 'period_year')
                    ->where(fn ($q) => $q
                        ->where('salesman_id', $this->input('salesman_id'))
                        ->where('period_month', $this->input('period_month')))
                    ->ignore($targetId),
            ],
            'period_month' => ['required', 'integer', 'min:1', 'max:12'],
            'target_amount' => ['required', 'numeric', 'min:0'],
            'target_orders' => ['nullable', 'integer', 'min:0'],
            'incentive_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'notes' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'period_year.unique' => 'A target already exists for this salesman in the selected month.',
        ];
    }
}
