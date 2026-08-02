<?php

declare(strict_types=1);

namespace App\Modules\Industry\Cosmetics\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ShadeCardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware handles permission checks
    }

    public function rules(): array
    {
        return [
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'shade_ids' => ['required', 'array', 'min:1', 'max:200'],
            'shade_ids.*' => ['integer', 'exists:cosmetic_shades,id'],
            /**
             * Optional overrides. Left out, the card prices every shade from the
             * product — which is right in this trade, where a range sells at one
             * price whatever the shade. Sent, they apply to the variants this run
             * creates and leave existing ones alone: re-running the card must not
             * silently re-price the thirty-four shades already on the shelf.
             */
            'cost_price' => ['nullable', 'numeric', 'min:0'],
            'selling_price' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
