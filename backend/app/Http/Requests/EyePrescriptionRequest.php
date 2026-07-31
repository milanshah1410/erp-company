<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EyePrescriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // Shape and bounds only. Whether the powers are actually dispensable —
        // quarter-dioptre steps, axis with cylinder, addition on the right
        // vision type — is OpticalService's job, so the rule lives in one place
        // and the order form gets the same answer as this form.
        return [
            'customer_id' => ['nullable', 'exists:customers,id'],
            'patient_name' => ['required', 'string', 'max:150'],
            'patient_age' => ['nullable', 'integer', 'min:0', 'max:130'],
            'patient_gender' => ['nullable', Rule::in(['male', 'female', 'other'])],
            'patient_phone' => ['nullable', 'string', 'max:30'],

            'optometrist_name' => ['nullable', 'string', 'max:150'],
            'optometrist_registration_no' => ['nullable', 'string', 'max:60'],
            'vision_type' => ['required', Rule::in(['distance', 'near', 'bifocal', 'progressive'])],

            'right_sph' => ['nullable', 'numeric', 'between:-30,30'],
            'right_cyl' => ['nullable', 'numeric', 'between:-12,12'],
            'right_axis' => ['nullable', 'integer', 'between:0,180'],
            'right_add' => ['nullable', 'numeric', 'between:0,4'],
            'left_sph' => ['nullable', 'numeric', 'between:-30,30'],
            'left_cyl' => ['nullable', 'numeric', 'between:-12,12'],
            'left_axis' => ['nullable', 'integer', 'between:0,180'],
            'left_add' => ['nullable', 'numeric', 'between:0,4'],

            // Adult pupillary distance sits around 54–74mm; well outside that
            // is a mis-keyed measurement, not an unusual face.
            'pd' => ['nullable', 'numeric', 'between:40,85'],
            'right_pd' => ['nullable', 'numeric', 'between:20,45'],
            'left_pd' => ['nullable', 'numeric', 'between:20,45'],

            'prescribed_on' => ['required', 'date', 'before_or_equal:today'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:prescribed_on'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
