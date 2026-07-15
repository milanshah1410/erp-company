<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class HsnCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware handles permission checks
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('code')) {
            $this->merge(['code' => preg_replace('/\D/', '', (string) $this->input('code'))]);
        }
    }

    public function rules(): array
    {
        $hsn = $this->route('hsnCode');

        return [
            'code' => [
                'required', 'string', 'regex:/^[0-9]{4,8}$/',
                Rule::unique('hsn_codes', 'code')->ignore($hsn?->id),
            ],
            'type' => ['sometimes', Rule::in(['goods', 'service'])],
            'description' => ['required', 'string', 'max:255'],
            'gst_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'cess_rate' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'uqc' => ['nullable', 'string', 'max:10'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.regex' => 'HSN/SAC code must be 4 to 8 digits.',
        ];
    }
}
