<?php

declare(strict_types=1);

namespace App\Modules\Industry\FoodBeverage\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for a time-window offer.
 *
 * `end_time` is deliberately NOT required to be after `start_time`: a window that
 * wraps midnight (22:00–01:00) is a real late-bar offer and the evaluator handles
 * it. What is rejected is a zero-length window, which is always a mistake and would
 * otherwise sit in the list looking like a live offer that never fires.
 */
class MenuHappyHourRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'days' => ['required', 'array', 'min:1'],
            // ISO-8601 day numbers: 1 = Monday … 7 = Sunday.
            'days.*' => ['integer', 'min:1', 'max:7'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'different:start_time'],
            'valid_from' => ['nullable', 'date'],
            'valid_to' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'scope' => ['required', Rule::in(['all', 'category', 'products'])],
            'category_id' => ['nullable', 'required_if:scope,category', 'exists:categories,id'],
            'discount_type' => ['required', Rule::in(['percent', 'flat'])],
            // A percentage over 100 would pay the guest to eat — checked in
            // withValidator(), where the discount type is known.
            'discount_value' => ['required', 'numeric', 'min:0.01'],
            'applies_to' => ['required', Rule::in(['all', 'dine_in', 'takeaway', 'delivery'])],
            'priority' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'is_active' => ['nullable', 'boolean'],

            'product_ids' => ['nullable', 'required_if:scope,products', 'array', 'min:1'],
            'product_ids.*' => ['integer', 'exists:products,id'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if (
                $this->input('discount_type') === 'percent'
                && (float) $this->input('discount_value') > 100
            ) {
                $validator->errors()->add(
                    'discount_value',
                    'A percentage discount cannot be more than 100%.',
                );
            }
        });
    }

    public function messages(): array
    {
        return [
            'end_time.different' => 'The window has to be longer than nothing.',
            'category_id.required_if' => 'Pick the category this offer applies to.',
            'product_ids.required_if' => 'Pick at least one item this offer applies to.',
        ];
    }
}
