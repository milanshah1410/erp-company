<?php

namespace App\Http\Requests;

use App\Models\Customer;
use App\Models\Lead;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FollowUpRequest extends FormRequest
{
    /** Short keys accepted from the client, mapped to the polymorphic model class. */
    public const RELATED_TYPES = [
        'lead' => Lead::class,
        'customer' => Customer::class,
    ];

    public function authorize(): bool
    {
        return true; // route middleware handles permission checks
    }

    public function rules(): array
    {
        $rules = [
            'type' => ['nullable', Rule::in(['call', 'visit', 'email', 'whatsapp', 'other'])],
            'due_at' => ['required', 'date'],
            'title' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
        ];

        // The related party is only set on create; updates keep the original link.
        if ($this->isMethod('post')) {
            $rules['related_type'] = ['required', Rule::in(array_keys(self::RELATED_TYPES))];
            $rules['related_id'] = ['required', 'integer'];
        }

        return $rules;
    }

    /** The fully-qualified model class for the submitted related_type. */
    public function relatedModelClass(): ?string
    {
        return self::RELATED_TYPES[$this->input('related_type')] ?? null;
    }
}
