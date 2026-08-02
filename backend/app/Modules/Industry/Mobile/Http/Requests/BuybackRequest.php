<?php

declare(strict_types=1);

namespace App\Modules\Industry\Mobile\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for a part-exchange valuation.
 *
 * `offered_value` is deliberately absent: the model computes it from base minus
 * deduction plus bonus on every save, because the customer is shown that arithmetic
 * at the counter and a stored total that disagrees with the figures beside it is an
 * argument the shop cannot win.
 */
class BuybackRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'customer_id' => ['nullable', 'exists:customers,id'],

            'brand' => ['required', 'string', 'max:60'],
            'model_name' => ['required', 'string', 'max:80'],
            'imei' => ['nullable', 'string', 'max:40'],
            'device_serial' => ['nullable', 'string', 'max:60'],
            'storage' => ['nullable', 'string', 'max:20'],
            'colour' => ['nullable', 'string', 'max:40'],
            'age_months' => ['nullable', 'integer', 'min:0', 'max:600'],

            'grade' => ['required', Rule::in(['A', 'B', 'C', 'D'])],
            'defects' => ['nullable', 'string'],
            'has_box' => ['nullable', 'boolean'],
            'has_charger' => ['nullable', 'boolean'],
            'has_bill' => ['nullable', 'boolean'],

            'base_value' => ['required', 'numeric', 'min:0'],
            'deduction' => ['nullable', 'numeric', 'min:0'],
            // The brand's exchange bonus, which the brand reimburses — kept apart from
            // the base value precisely so it can be claimed back.
            'bonus_value' => ['nullable', 'numeric', 'min:0'],

            'quoted_on' => ['nullable', 'date'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:quoted_on'],
            'notes' => ['nullable', 'string'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $base = (float) $this->input('base_value', 0);
            $deduction = (float) $this->input('deduction', 0);

            // A deduction larger than the handset's value would price the trade-in
            // below nothing, which the model floors at zero — so say what happened
            // rather than silently quoting ₹0 to a customer expecting money.
            if ($deduction > $base) {
                $validator->errors()->add(
                    'deduction',
                    'The deductions come to more than the handset is worth. Re-grade it, or take it in at no value and say so in the notes.',
                );
            }

            /**
             * A grade D handset with no defects written down is the entry nobody can
             * defend later. The grade *is* the price in this trade, and the only record
             * of why it was that low is this field.
             */
            if ($this->input('grade') === 'D' && ! $this->filled('defects')) {
                $validator->errors()->add(
                    'defects',
                    'Write down what is wrong with it — a grade D valuation with no defects recorded cannot be justified later.',
                );
            }
        });
    }
}
