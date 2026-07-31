<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PrescriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Optional: a walk-in patient buying against a slip has no account.
            'customer_id' => ['nullable', 'exists:customers,id'],

            'patient_name' => ['required', 'string', 'max:150'],
            'patient_age' => ['nullable', 'integer', 'min:0', 'max:130'],
            'patient_gender' => ['nullable', Rule::in(['male', 'female', 'other'])],
            'patient_phone' => ['nullable', 'string', 'max:30'],

            'doctor_name' => ['required', 'string', 'max:150'],
            'doctor_registration_no' => ['nullable', 'string', 'max:60'],
            'hospital' => ['nullable', 'string', 'max:150'],

            // A slip cannot be dated into the future, and one dated after its
            // own validity is a typo rather than a repeat.
            'prescribed_on' => ['required', 'date', 'before_or_equal:today'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:prescribed_on'],

            'notes' => ['nullable', 'string'],
            'attachment_path' => ['nullable', 'string', 'max:255'],
        ];
    }
}
