<?php

namespace App\Http\Requests;

use App\Models\Transporter;
use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TransporterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware handles permission checks
    }

    public function rules(): array
    {
        $transporterId = $this->route('transporter')?->id;
        $tenantId = app(TenantContext::class)->id();

        return [
            'code' => [
                'required', 'string', 'max:30',
                Rule::unique('transporters', 'code')->where('tenant_id', $tenantId)->ignore($transporterId),
            ],
            'name' => ['required', 'string', 'max:255'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'gst_number' => ['nullable', 'string', 'max:20'],
            'pan_number' => ['nullable', 'string', 'max:20'],
            'transport_mode' => ['required', Rule::in(Transporter::MODES)],
            'vehicle_types' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string'],
            'city' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
        ];
    }
}
