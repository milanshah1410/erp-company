<?php

declare(strict_types=1);

namespace App\Modules\Industry\FoodBeverage\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for one can of milk.
 *
 * Quality readings are bounded but generously: fat above 15% or SNF above 15% is
 * not milk, and rejecting those catches a decimal point typed in the wrong place —
 * which at a fat-based rate is the difference between ₹40 and ₹400 a litre. Inside
 * those bounds nothing is refused, because whether to accept a thin can is the
 * counter's decision and the chart's acceptance band reports it rather than
 * enforcing it.
 *
 * `collection_date` may not be in the future: a round that has not happened cannot
 * have been weighed.
 */
class MilkCollectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'supplier_id' => ['required', 'exists:suppliers,id'],
            'collection_date' => ['required', 'date', 'before_or_equal:today'],
            'shift' => ['required', Rule::in(['morning', 'evening'])],
            'milk_type' => ['required', Rule::in(['cow', 'buffalo', 'mixed'])],
            'quantity_litres' => ['required', 'numeric', 'min:0.001', 'max:100000'],
            'fat_percent' => ['nullable', 'numeric', 'min:0', 'max:15'],
            'snf_percent' => ['nullable', 'numeric', 'min:0', 'max:15'],
            'clr' => ['nullable', 'numeric', 'min:0', 'max:40'],
            'milk_rate_chart_id' => ['nullable', 'exists:milk_rate_charts,id'],
            /**
             * An override. Left out, the chart in force prices the can; sent, it
             * wins — a collection centre does occasionally pay a rate the chart
             * does not give, and that has to be possible and visible rather than
             * impossible and settled in cash off the books.
             */
            'rate_per_litre' => ['nullable', 'numeric', 'min:0'],
            'delivery_route_id' => ['nullable', 'exists:delivery_routes,id'],
            'remarks' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'collection_date.before_or_equal' => 'Milk cannot be collected for a date in the future.',
            'fat_percent.max' => 'Check the fat reading — anything over 15% is not milk.',
        ];
    }
}
