<?php

declare(strict_types=1);

namespace App\Modules\Industry\Grocery\Http\Requests;

use App\Modules\Industry\Grocery\Models\ShelfPlacement;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * A planogram line.
 *
 * The interesting validation is not the field list, it is the two invariants a
 * database constraint cannot express here:
 *
 *  1. **One placement per product per shelf.** The unique index covers it only
 *     when `variant_id` is set — MySQL treats NULLs as distinct, so two
 *     variant-less rows for the same product on the same shelf would slip
 *     through and give the replenishment round two contradictory minimums.
 *  2. **max ≥ min.** A shelf whose full quantity is below its gap threshold is
 *     permanently gapped and permanently suggests topping up by zero, which is
 *     the kind of configuration nobody notices until the round stops working.
 */
class ShelfPlacementRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'location_id' => ['required', 'exists:warehouse_locations,id'],
            'product_id' => ['required', 'exists:products,id'],
            'variant_id' => ['nullable', 'exists:product_variants,id'],
            'facings' => ['nullable', 'integer', 'min:1', 'max:999'],
            'min_qty' => ['nullable', 'numeric', 'min:0'],
            'max_qty' => ['nullable', 'numeric', 'min:0'],
            'sequence' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $data = $validator->getData();

            $min = (float) ($data['min_qty'] ?? 0);
            $max = (float) ($data['max_qty'] ?? 0);

            if ($max > 0 && $max < $min) {
                $validator->errors()->add('max_qty', 'A full shelf cannot hold less than the point at which it counts as empty.');
            }

            if (empty($data['location_id']) || empty($data['product_id'])) {
                return;
            }

            $duplicate = ShelfPlacement::query()
                ->where('location_id', $data['location_id'])
                ->where('product_id', $data['product_id'])
                ->when(
                    empty($data['variant_id']),
                    static fn ($q) => $q->whereNull('variant_id'),
                    static fn ($q) => $q->where('variant_id', $data['variant_id']),
                )
                ->when($this->route('shelfPlacement'), fn ($q, $placement) => $q->whereKeyNot($placement->id))
                ->exists();

            if ($duplicate) {
                $validator->errors()->add('product_id', 'This product is already placed on that shelf. Edit the existing placement instead.');
            }
        });
    }
}
