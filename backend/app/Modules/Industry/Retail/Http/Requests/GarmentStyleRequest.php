<?php

declare(strict_types=1);

namespace App\Modules\Industry\Retail\Http\Requests;

use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GarmentStyleRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route middleware already carries the permission and module gate.
        return true;
    }

    public function rules(): array
    {
        $id = $this->route('style')?->id;
        $tenantId = app(TenantContext::class)->id();

        return [
            /**
             * The design number is the style's identity in the trade, so it is
             * required and unique per tenant. Uniqueness is scoped by the
             * BelongsToTenant global scope on reads but not on a raw unique rule,
             * so the tenant is stated explicitly here.
             */
            'code' => [
                'required', 'string', 'max:40',
                Rule::unique('garment_styles', 'code')->where('tenant_id', $tenantId)->ignore($id),
            ],
            'name' => ['required', 'string', 'max:160'],
            'category_id' => ['nullable', 'exists:categories,id'],
            'brand_id' => ['nullable', 'exists:brands,id'],
            'fabric_id' => ['nullable', 'exists:garment_fabrics,id'],
            'size_set_id' => ['nullable', 'exists:garment_size_sets,id'],
            'gender' => ['nullable', Rule::in(['mens', 'womens', 'boys', 'girls', 'unisex', 'infant'])],
            'season' => ['nullable', 'string', 'max:40'],
            'fit' => ['nullable', 'string', 'max:40'],
            'sleeve' => ['nullable', 'string', 'max:40'],
            'neck' => ['nullable', 'string', 'max:40'],
            'pattern' => ['nullable', 'string', 'max:40'],
            'mrp' => ['nullable', 'numeric', 'min:0'],
            'cost_price' => ['nullable', 'numeric', 'min:0'],
            'description' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
