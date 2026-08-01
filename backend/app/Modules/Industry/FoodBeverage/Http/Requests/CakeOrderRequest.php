<?php

declare(strict_types=1);

namespace App\Modules\Industry\FoodBeverage\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for a cake booking.
 *
 * `required_at` may be in the past on an update but not on a create: a shop keys
 * yesterday's phone order this morning often enough that refusing it would push the
 * order onto paper, while booking a *new* cake for a date that has gone is always a
 * typo.
 *
 * The advance is not capped at the price here. A customer who has paid in full has
 * paid an advance equal to the price, and the model floors the balance at zero —
 * capping it in validation would reject the commonest case at a bakery counter.
 */
class CakeOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $isCreate = $this->isMethod('POST');

        return [
            'customer_id' => ['nullable', 'exists:customers,id'],
            'customer_name' => ['required', 'string', 'max:255'],
            'customer_phone' => ['nullable', 'string', 'max:20'],
            'product_id' => ['nullable', 'exists:products,id'],
            'flavour' => ['nullable', 'string', 'max:255'],
            'weight_kg' => ['required', 'numeric', 'min:0.1', 'max:500'],
            'shape' => ['required', Rule::in(['round', 'square', 'heart', 'rectangle', 'tiered', 'custom'])],
            'tiers' => ['nullable', 'integer', 'min:1', 'max:20'],
            'is_eggless' => ['nullable', 'boolean'],
            'message_on_cake' => ['nullable', 'string', 'max:255'],
            'design_notes' => ['nullable', 'string', 'max:2000'],
            'required_at' => array_filter([
                'required',
                'date',
                $isCreate ? 'after:now' : null,
            ]),
            'fulfilment' => ['required', Rule::in(['pickup', 'delivery'])],
            'delivery_address' => ['nullable', 'required_if:fulfilment,delivery', 'string', 'max:2000'],
            'delivery_charge' => ['nullable', 'numeric', 'min:0'],
            'price' => ['required', 'numeric', 'min:0'],
            'advance_amount' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'required_at.after' => 'A cake cannot be booked for a time that has already passed.',
            'delivery_address.required_if' => 'A delivery needs an address.',
        ];
    }
}
