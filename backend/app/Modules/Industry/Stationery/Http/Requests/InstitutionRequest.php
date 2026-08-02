<?php

declare(strict_types=1);

namespace App\Modules\Industry\Stationery\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InstitutionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware handles permission checks
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:160'],
            'customer_id' => ['nullable', 'exists:customers,id'],

            'type' => ['nullable', Rule::in(['school', 'college', 'coaching', 'office', 'government', 'ngo', 'other'])],
            'board' => ['nullable', 'string', 'max:40'],
            'medium' => ['nullable', 'string', 'max:30'],

            /**
             * The session, as the trade writes it: "2026-27". Pattern-checked
             * because everything in this module is filtered by it — a stray
             * "2026-2027" is a school that silently vanishes from its own season.
             */
            'academic_year' => ['required', 'string', 'regex:/^\d{4}-\d{2}$/'],
            'session_starts_on' => ['nullable', 'date'],
            'student_count' => ['nullable', 'integer', 'min:0', 'max:100000'],

            'contact_person' => ['nullable', 'string', 'max:100'],
            'contact_phone' => ['nullable', 'string', 'max:20'],
            'contact_email' => ['nullable', 'email', 'max:120'],
            'address' => ['nullable', 'string'],

            'is_authorised_vendor' => ['nullable', 'boolean'],
            'parent_discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'school_discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],

            'credit_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'requires_po' => ['nullable', 'boolean'],

            'notes' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'academic_year.regex' => 'Write the session as 2026-27.',
        ];
    }
}
