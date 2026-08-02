<?php

declare(strict_types=1);

namespace App\Modules\Industry\Grocery\Http\Requests;

use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for a combo pack.
 *
 * Three rules do the real work: a product may back only one pack (matching the
 * table's unique index, so the shopkeeper gets a field error rather than a
 * constraint violation), a pack may not contain itself (harmless today, an
 * infinite loop the moment packs are allowed to nest), and a window that ends
 * before it starts is a hamper that never sells.
 */
class ComboPackRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $packId = $this->route('comboPack')?->id;

        return [
            'product_id' => [
                'required',
                'exists:products,id',
                Rule::unique('grocery_combo_packs', 'product_id')
                    ->where('tenant_id', app(TenantContext::class)->id())
                    ->ignore($packId),
            ],
            'name' => ['required', 'string', 'max:255'],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'is_active' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],

            'items' => ['required', 'array', 'min:2'],
            'items.*.product_id' => ['required', 'exists:products,id', 'different:product_id'],
            'items.*.variant_id' => ['nullable', 'exists:product_variants,id'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.001'],
            'items.*.sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'product_id.unique' => 'This product is already a combo pack. Edit that one instead.',
            /**
             * Two, not one. A "pack" of one item is an item, and letting one
             * through means the shop has priced the same product twice and will
             * later wonder which price the till used.
             */
            'items.min' => 'A combo pack needs at least two items.',
            'items.*.product_id.different' => 'A pack cannot contain itself.',
            'ends_on.after_or_equal' => 'The pack cannot end before it starts.',
        ];
    }
}
