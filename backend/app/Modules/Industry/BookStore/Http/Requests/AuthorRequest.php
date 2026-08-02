<?php

declare(strict_types=1);

namespace App\Modules\Industry\BookStore\Http\Requests;

use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AuthorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware handles permission checks
    }

    public function rules(): array
    {
        $id = $this->route('author')?->id;
        $tenantId = app(TenantContext::class)->id();

        return [
            /**
             * Unique per tenant, and the message says why: the failure this
             * register guards against is the duplicate, not the collision. Two
             * rows for one writer split their shelf, and the missing half is
             * invisible — the counter simply answers "we don't have any others".
             */
            'name' => [
                'required', 'string', 'max:160',
                Rule::unique('book_authors', 'name')->where('tenant_id', $tenantId)->ignore($id),
            ],
            'sort_name' => ['nullable', 'string', 'max:160'],
            'nationality' => ['nullable', 'string', 'max:80'],
            'language' => ['nullable', 'string', 'max:60'],
            'biography' => ['nullable', 'string'],
            'website' => ['nullable', 'string', 'max:190'],
            'is_active' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.unique' => 'This author is already in the register. Open that record instead — a second row would split their titles in two.',
        ];
    }
}
