<?php

declare(strict_types=1);

namespace App\Modules\Industry\Stationery\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The shared shape of "supply against this list" — an estimate, a tender
 * quotation and a school's order all answer the same question with different
 * amounts of commitment, so they take the same input.
 */
class KitSupplyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware handles permission checks
    }

    public function rules(): array
    {
        return [
            /**
             * Who the document is for. Optional because a school's own order
             * falls back to the ledger party on its account — and required in
             * practice for a counter estimate, where the buyer is a parent and
             * the slab price depends on which one.
             */
            'customer_id' => ['nullable', 'exists:customers,id'],
            'warehouse_id' => ['required', 'exists:warehouses,id'],
            /**
             * How many copies of the list. One for a parent, four hundred for a
             * school — and it is what makes quantity-slab pricing bite, so it is
             * the single most consequential number on this form.
             */
            'sets' => ['nullable', 'numeric', 'min:0.001', 'max:100000'],

            'include_optional' => ['nullable', 'boolean'],
            // A parent who already owns the geometry box. Null takes whatever the
            // list makes compulsory.
            'item_ids' => ['nullable', 'array'],
            'item_ids.*' => ['integer'],

            'po_number' => ['nullable', 'string', 'max:60'],
            'po_date' => ['nullable', 'date'],
            'valid_until' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
