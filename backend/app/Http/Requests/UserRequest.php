<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $userId = $this->route('user')?->id;
        $creating = $this->isMethod('post');

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($userId)],
            'phone' => ['nullable', 'string', 'max:30'],
            'is_active' => ['boolean'],
            // Password required on create, optional on update.
            'password' => [$creating ? 'required' : 'nullable', 'string', 'min:6'],
            'roles' => ['array'],
            'roles.*' => ['string', 'exists:roles,name'],
            'company_ids' => ['array'],
            'company_ids.*' => ['integer', 'exists:companies,id'],
            'branch_ids' => ['array'],
            'branch_ids.*' => ['integer', 'exists:branches,id'],
        ];
    }
}
