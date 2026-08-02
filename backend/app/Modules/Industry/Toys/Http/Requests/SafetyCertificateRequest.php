<?php

declare(strict_types=1);

namespace App\Modules\Industry\Toys\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SafetyCertificateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware handles permission checks
    }

    public function rules(): array
    {
        return [
            'certificate_number' => ['required', 'string', 'max:80'],
            'standard' => ['required', Rule::in(['bis', 'en71', 'astm', 'iso8124', 'ce', 'other'])],

            // Scope. Any one of the three, or a narrower combination — see the
            // cross-field rule below for the one combination that is useless.
            'product_id' => ['nullable', 'exists:products,id'],
            'brand_id' => ['nullable', 'exists:brands,id'],
            'supplier_id' => ['nullable', 'exists:suppliers,id'],

            'issued_by' => ['nullable', 'string', 'max:120'],
            'licence_no' => ['nullable', 'string', 'max:80'],

            'issued_on' => ['nullable', 'date'],
            // Nullable is the open-ended declaration; dated is everything else.
            'expires_on' => ['nullable', 'date', 'after_or_equal:issued_on'],

            'document_path' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::in(['active', 'revoked'])],
            'notes' => ['nullable', 'string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        /**
         * A certificate scoped to nothing covers nothing. It would sit in the
         * register looking like protection while every counter lookup ignored
         * it, which is worse than not recording it at all — so the one thing
         * refused here is paperwork with no subject.
         */
        $validator->after(function (Validator $v) {
            if (! $this->filled('product_id') && ! $this->filled('brand_id') && ! $this->filled('supplier_id')) {
                $v->errors()->add('product_id', 'Say what this certificate covers: an article, a brand or a supplier.');
            }
        });
    }
}
