<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EmbroideryVendorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware handles permission checks
    }

    public function rules(): array
    {
        $vendorId = $this->route('embroideryVendor')?->id;

        return [
            'code' => ['required', 'string', 'max:255', Rule::unique('embroidery_vendors', 'code')->ignore($vendorId)],
            'name' => ['required', 'string', 'max:255'],
            'mobile' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string'],
            'gst_number' => ['nullable', 'string', 'max:255'],
            'opening_balance' => ['nullable', 'numeric'],
            'is_active' => ['boolean'],
        ];
    }
}
