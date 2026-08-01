<?php

declare(strict_types=1);

namespace App\Modules\Industry\Manufacturing\Http\Requests;

use App\Modules\Industry\Manufacturing\Models\ProductionScrap;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for a scrap note.
 *
 * The rule that matters: recovery cannot exceed the value written off. Selling
 * regrind for more than the material cost would make scrap a profit centre in
 * the reports, which is both wrong and the kind of wrong nobody questions
 * because it looks good.
 */
class ProductionScrapRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'scrap_date' => ['required', 'date'],
            'manufacturing_order_id' => ['nullable', 'exists:manufacturing_orders,id'],
            'quality_inspection_id' => ['nullable', 'exists:quality_inspections,id'],
            'product_id' => ['required', 'exists:products,id'],
            'variant_id' => ['nullable', 'exists:product_variants,id'],
            'warehouse_id' => ['required', 'exists:warehouses,id'],
            'quantity' => ['required', 'numeric', 'min:0.001'],
            'scrap_type' => ['required', Rule::in(ProductionScrap::TYPES)],
            'reason' => ['required', Rule::in(array_keys(ProductionScrap::REASONS))],
            'unit_cost' => ['nullable', 'numeric', 'min:0'],
            'recoverable_value' => ['nullable', 'numeric', 'min:0'],
            'affects_stock' => ['nullable', 'boolean'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $value = (float) $this->input('quantity', 0) * (float) $this->input('unit_cost', 0);
            $recovery = (float) $this->input('recoverable_value', 0);

            if ($recovery > 0 && $value > 0 && round($recovery, 2) > round($value, 2)) {
                $v->errors()->add(
                    'recoverable_value',
                    'Recovery cannot be more than the value being scrapped.',
                );
            }
        });
    }
}
