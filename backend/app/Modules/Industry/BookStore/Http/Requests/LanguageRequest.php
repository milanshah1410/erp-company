<?php

declare(strict_types=1);

namespace App\Modules\Industry\BookStore\Http\Requests;

use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LanguageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware handles permission checks
    }

    public function rules(): array
    {
        $id = $this->route('language')?->id;
        $tenantId = app(TenantContext::class)->id();

        return [
            /**
             * ISO 639-1 where one exists ('gu', 'hi', 'en'), the shop's own code
             * where it does not. Deliberately not validated against a fixed list:
             * the languages a shop stocks that nobody's dropdown has — Kutchi,
             * Sindhi, Sourashtra — are exactly the ones a shop stocking them
             * cares about.
             */
            'code' => [
                'required', 'string', 'max:12', 'regex:/^[A-Za-z][A-Za-z0-9\-]*$/',
                Rule::unique('book_languages', 'code')->where('tenant_id', $tenantId)->ignore($id),
            ],
            'label' => ['required', 'string', 'max:80'],
            // What the shelf card says, in the language it names.
            'native_name' => ['nullable', 'string', 'max:80'],
            'script' => ['nullable', 'string', 'max:60'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.unique' => 'This language code is already in use.',
            'code.regex' => 'Use a short code such as gu, hi or en — letters, digits and hyphens only.',
        ];
    }
}
