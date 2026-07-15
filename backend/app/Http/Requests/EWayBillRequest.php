<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EWayBillRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware handles permission checks
    }

    public function rules(): array
    {
        return [
            'transport_mode' => ['required', Rule::in(['road', 'rail', 'air', 'ship'])],
            'transporter_id' => ['nullable', 'string', 'max:20'],
            'transporter_name' => ['nullable', 'string', 'max:255'],
            'transport_doc_no' => ['nullable', 'string', 'max:50'],
            'transport_doc_date' => ['nullable', 'date'],
            // Vehicle number required for road transport.
            'vehicle_no' => ['nullable', 'required_if:transport_mode,road', 'string', 'max:20'],
            'vehicle_type' => ['sometimes', Rule::in(['regular', 'over_dimensional_cargo'])],
            'distance_km' => ['required', 'numeric', 'min:0', 'max:4000'],
            'from_place' => ['nullable', 'string', 'max:255'],
            'from_pincode' => ['nullable', 'string', 'regex:/^[0-9]{6}$/'],
            'to_place' => ['nullable', 'string', 'max:255'],
            'to_pincode' => ['nullable', 'string', 'regex:/^[0-9]{6}$/'],
        ];
    }
}
