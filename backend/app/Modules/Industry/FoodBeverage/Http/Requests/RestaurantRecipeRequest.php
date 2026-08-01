<?php

declare(strict_types=1);

namespace App\Modules\Industry\FoodBeverage\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for a dish's recipe.
 *
 * The one rule worth stating out loud: a dish may not be an ingredient of its own
 * recipe. Nothing downstream loops on recipes today, but a self-referential row
 * is meaningless and would become a genuine infinite loop the day sub-recipes
 * (a gravy used by six curries) are added.
 */
class RestaurantRecipeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $recipeId = $this->route('restaurantRecipe')?->id;

        return [
            'product_id' => [
                'required',
                'exists:products,id',
                // One recipe per dish, matching the table's unique index so the
                // user gets a field error rather than a 500 from the constraint.
                Rule::unique('restaurant_recipes', 'product_id')
                    ->where('tenant_id', app(\App\Support\TenantContext::class)->id())
                    ->ignore($recipeId),
            ],
            'yield_quantity' => ['required', 'numeric', 'min:0.001'],
            'is_active' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],

            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'exists:products,id', 'different:product_id'],
            'items.*.variant_id' => ['nullable', 'exists:product_variants,id'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.0001'],
            'items.*.wastage_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'items.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
            'items.*.remarks' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'product_id.unique' => 'This dish already has a recipe. Edit that one instead.',
            'items.required' => 'A recipe needs at least one ingredient.',
            'items.*.product_id.different' => 'A dish cannot be an ingredient of itself.',
        ];
    }
}
