<?php

namespace App\Http\Requests;

use App\Models\FinancialYear;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class FinancialYearRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware handles permission checks
    }

    public function rules(): array
    {
        $financialYear = $this->route('financialYear');
        $companyId = $this->input('company_id', $financialYear?->company_id);

        return [
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'name' => [
                'required', 'string', 'max:100',
                Rule::unique('financial_years', 'name')
                    ->where('company_id', $companyId)
                    ->ignore($financialYear?->id),
            ],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after:start_date'],
            'is_closed' => ['sometimes', 'boolean'],
            'is_current' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Reject a period that overlaps an existing financial year for the same
     * company — a transaction date must map to exactly one year.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $financialYear = $this->route('financialYear');

            $overlaps = FinancialYear::query()
                ->where('company_id', $this->input('company_id'))
                ->when($financialYear, fn ($q) => $q->whereKeyNot($financialYear->id))
                ->where('start_date', '<=', $this->input('end_date'))
                ->where('end_date', '>=', $this->input('start_date'))
                ->exists();

            if ($overlaps) {
                $validator->errors()->add('start_date', 'This period overlaps an existing financial year for this company.');
            }
        });
    }
}
