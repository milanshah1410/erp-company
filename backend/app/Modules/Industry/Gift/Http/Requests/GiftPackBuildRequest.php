<?php

declare(strict_types=1);

namespace App\Modules\Industry\Gift\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GiftPackBuildRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware handles permission checks
    }

    public function rules(): array
    {
        return [
            // A thousand hampers is a large corporate order; beyond that someone
            // has typed a phone number into the quantity box.
            'quantity' => ['required', 'numeric', 'min:0.001', 'max:10000'],
            /**
             * Required rather than defaulted. A build moves stock in two
             * directions and a shop with a shop-floor store and a counter store
             * must say which shelves were emptied — guessing would be a silent
             * transfer between locations.
             */
            'warehouse_id' => ['required', 'exists:warehouses,id'],
            'build_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
