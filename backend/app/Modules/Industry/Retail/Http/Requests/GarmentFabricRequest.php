<?php

declare(strict_types=1);

namespace App\Modules\Industry\Retail\Http\Requests;

use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GarmentFabricRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware handles permission and module checks
    }

    public function rules(): array
    {
        $id = $this->route('fabric')?->id;
        $tenantId = app(TenantContext::class)->id();

        return [
            'code' => [
                'required', 'string', 'max:40',
                Rule::unique('garment_fabrics', 'code')->where('tenant_id', $tenantId)->ignore($id),
            ],
            'name' => ['required', 'string', 'max:120'],
            'composition' => ['nullable', 'string', 'max:160'],
            'weave' => ['nullable', 'string', 'max:60'],
            'gsm' => ['nullable', 'integer', 'min:1', 'max:2000'],
            'width_inch' => ['nullable', 'numeric', 'min:0', 'max:200'],
            'shrinkage_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'care' => ['nullable', 'string', 'max:255'],
            'rate' => ['nullable', 'numeric', 'min:0'],
            'supplier_id' => ['nullable', 'exists:suppliers,id'],
            'notes' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
