<?php

namespace App\Http\Requests;

use App\Models\ManufacturingOrder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ManufacturingOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'product_id' => ['required', 'exists:products,id'],
            'variant_id' => ['nullable', 'exists:product_variants,id'],
            'bom_id' => ['nullable', 'exists:boms,id'],
            'warehouse_id' => ['required', 'exists:warehouses,id'],
            // The machine the run is loaded on (Manufacturing industry plugin).
            // Optional: hand finishing runs on nobody's press.
            'machine_id' => ['nullable', 'exists:machines,id'],
            'quantity_to_produce' => ['required', 'numeric', 'min:0.001'],
            'order_date' => ['required', 'date'],
            'expected_completion_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],

            // Production-cost buckets (raw material is derived from the lines).
            'tailor_cost' => ['nullable', 'numeric', 'min:0'],
            'labour_cost' => ['nullable', 'numeric', 'min:0'],
            'machine_cost' => ['nullable', 'numeric', 'min:0'],
            'printing_cost' => ['nullable', 'numeric', 'min:0'],
            'embroidery_cost' => ['nullable', 'numeric', 'min:0'],
            'packing_cost' => ['nullable', 'numeric', 'min:0'],
            'transport_cost' => ['nullable', 'numeric', 'min:0'],
            'other_cost' => ['nullable', 'numeric', 'min:0'],

            // Raw materials to consume (per-order bill of materials).
            'materials' => ['nullable', 'array'],
            'materials.*.product_id' => ['required', 'exists:products,id'],
            'materials.*.variant_id' => ['nullable', 'exists:product_variants,id'],
            'materials.*.quantity' => ['required', 'numeric', 'min:0.001'],
            'materials.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
            'materials.*.remarks' => ['nullable', 'string'],

            // Which stages apply to this order (subset of the fixed pipeline).
            'stages' => ['required', 'array', 'min:1'],
            'stages.*' => [Rule::in(array_keys(ManufacturingOrder::STAGES))],
        ];
    }
}
