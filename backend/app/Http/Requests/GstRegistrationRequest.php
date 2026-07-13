<?php

namespace App\Http\Requests;

use App\Support\GstStateCodes;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GstRegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware handles permission checks
    }

    /**
     * Normalise the GSTIN and derive the state fields from it before validation,
     * so the client only needs to supply the number itself.
     */
    protected function prepareForValidation(): void
    {
        $gstin = strtoupper(trim((string) $this->input('gstin')));

        $updates = ['gstin' => $gstin];
        if (strlen($gstin) >= 2 && ctype_digit(substr($gstin, 0, 2))) {
            $code = substr($gstin, 0, 2);
            $updates['state_code'] = $code;
            if (! $this->filled('state')) {
                $updates['state'] = GstStateCodes::name($code);
            }
        }

        $this->merge($updates);
    }

    public function rules(): array
    {
        $registration = $this->route('gstRegistration');

        return [
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'branch_id' => [
                'nullable', 'integer',
                Rule::exists('branches', 'id')->where('company_id', $this->input('company_id')),
            ],
            'gstin' => [
                'required', 'string', 'size:15',
                'regex:/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/',
                Rule::unique('gst_registrations', 'gstin')->ignore($registration?->id),
            ],
            'legal_name' => ['nullable', 'string', 'max:255'],
            'trade_name' => ['nullable', 'string', 'max:255'],
            'state_code' => ['required', 'string', 'size:2'],
            'state' => ['nullable', 'string', 'max:100'],
            'registration_type' => [
                'sometimes',
                Rule::in(['regular', 'composition', 'casual', 'sez', 'input_service_distributor', 'tds', 'tcs', 'unregistered']),
            ],
            'address' => ['nullable', 'string'],
            'effective_from' => ['nullable', 'date'],
            'is_primary' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'gstin.regex' => 'The GSTIN format is invalid. Expected 15 characters, e.g. 27ABCDE1234F1Z5.',
            'branch_id.exists' => 'The selected branch does not belong to this company.',
        ];
    }
}
