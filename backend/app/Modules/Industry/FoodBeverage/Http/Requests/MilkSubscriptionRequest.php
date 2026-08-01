<?php

declare(strict_types=1);

namespace App\Modules\Industry\FoodBeverage\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for a household's standing order.
 *
 * `days` is required and must hold at least one day. The model treats an empty list
 * as "every day" so an older row cannot silently stop delivering, but a *new*
 * subscription with no days is someone who has not finished filling the form in —
 * and guessing "daily" on their behalf would put milk on a doorstep nobody agreed
 * to pay for.
 */
class MilkSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'customer_id' => ['required', 'exists:customers,id'],
            'product_id' => ['required', 'exists:products,id'],
            'variant_id' => ['nullable', 'exists:product_variants,id'],
            'quantity_per_day' => ['required', 'numeric', 'min:0.001', 'max:1000'],
            'rate' => ['required', 'numeric', 'min:0'],
            'shift' => ['required', Rule::in(['morning', 'evening', 'both'])],
            'days' => ['required', 'array', 'min:1'],
            // ISO-8601 day numbers: 1 = Monday … 7 = Sunday.
            'days.*' => ['integer', 'min:1', 'max:7'],
            'delivery_route_id' => ['nullable', 'exists:delivery_routes,id'],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'status' => ['nullable', Rule::in(['active', 'paused', 'cancelled'])],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'days.required' => 'Pick the days milk should be delivered.',
        ];
    }
}
