<?php

declare(strict_types=1);

namespace App\Modules\Industry\FoodBeverage\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for a meal deal.
 *
 * Two rules do the real work. A product may back only one combo, matching the
 * table's unique index so the user gets a field error rather than a constraint
 * violation. And a combo may not contain itself — harmless today, an infinite loop
 * the moment combos are allowed to nest.
 */
class MenuComboRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $comboId = $this->route('menuCombo')?->id;

        return [
            'product_id' => [
                'required',
                'exists:products,id',
                Rule::unique('menu_combos', 'product_id')
                    ->where('tenant_id', app(\App\Support\TenantContext::class)->id())
                    ->ignore($comboId),
            ],
            'name' => ['required', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],

            'items' => ['required', 'array', 'min:2'],
            'items.*.product_id' => ['required', 'exists:products,id', 'different:product_id'],
            'items.*.variant_id' => ['nullable', 'exists:product_variants,id'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.001'],
            'items.*.is_swappable' => ['nullable', 'boolean'],
            'items.*.sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'product_id.unique' => 'This product is already a combo. Edit that one instead.',
            // Two, not one: a "combo" of one dish is a dish, and letting one
            // through means a shop can price the same product twice and then
            // wonder which price the till used.
            'items.min' => 'A combo needs at least two items.',
            'items.*.product_id.different' => 'A combo cannot contain itself.',
        ];
    }
}
