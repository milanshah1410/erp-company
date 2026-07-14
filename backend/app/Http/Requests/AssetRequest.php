<?php

namespace App\Http\Requests;

use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware handles permission checks
    }

    public function rules(): array
    {
        $assetId = $this->route('asset')?->id;
        $tenantId = app(TenantContext::class)->id();

        return [
            'asset_code' => [
                'required', 'string', 'max:50',
                Rule::unique('assets', 'asset_code')->where('tenant_id', $tenantId)->ignore($assetId),
            ],
            'name' => ['required', 'string', 'max:255'],
            'category' => ['required', Rule::in(['machine', 'equipment', 'furniture', 'electronic', 'vehicle', 'building', 'other'])],
            'branch_id' => ['nullable', 'exists:branches,id'],
            'location' => ['nullable', 'string', 'max:255'],
            'purchase_date' => ['nullable', 'date'],
            'purchase_value' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['boolean'],
        ];
    }
}
