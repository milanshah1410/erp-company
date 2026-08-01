<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\CustomFieldDefinition;
use App\Support\CustomFieldEntities;
use App\Support\Modules;
use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CustomFieldDefinitionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware handles permission checks
    }

    public function rules(): array
    {
        $definition = $this->route('customFieldDefinition');
        $isCreate = $definition === null;

        // Entity and key are immutable: both are already baked into every value
        // stored on every record, so changing either would orphan the lot. The
        // builder disables the inputs; the controller drops them from an update
        // in case someone reaches the API directly.
        $identity = $isCreate
            ? [
                'entity' => ['required', Rule::in(CustomFieldEntities::keys())],
                'key' => [
                    'required', 'string', 'max:60',
                    // snake_case: this becomes a JSON key and a form field name.
                    'regex:/^[a-z][a-z0-9_]*$/',
                    Rule::unique('custom_field_definitions', 'key')
                        ->where('tenant_id', app(TenantContext::class)->id())
                        ->where('entity', $this->input('entity')),
                ],
            ]
            : [];

        $isChoice = in_array($this->input('type'), CustomFieldDefinition::CHOICE_TYPES, true);

        return $identity + [
            'label' => ['required', 'string', 'max:120'],
            'help_text' => ['nullable', 'string', 'max:255'],
            'type' => ['required', Rule::in(CustomFieldDefinition::TYPES)],

            'options' => [$isChoice ? 'required' : 'nullable', 'array', $isChoice ? 'min:1' : 'max:200'],
            'options.*.value' => ['required_with:options', 'string', 'max:100'],
            'options.*.label' => ['nullable', 'string', 'max:100'],

            'default_value' => ['nullable', 'string', 'max:255'],
            'is_required' => ['boolean'],

            // min/max read as string length on text types and as magnitude on
            // numeric ones, which is why they are one nullable pair rather than
            // two type-specific ones.
            'min_value' => ['nullable', 'numeric'],
            'max_value' => ['nullable', 'numeric', 'gte:min_value'],

            'pattern' => ['nullable', 'string', 'max:255', function ($attribute, $value, $fail) {
                if ($value !== null && $value !== '' && @preg_match('#^(?:'.$value.')$#u', '') === false) {
                    $fail('That is not a valid regular expression.');
                }
            }],

            'show_in_list' => ['boolean'],
            'is_searchable' => ['boolean'],

            'module' => ['nullable', Rule::in(Modules::keys())],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['boolean'],
        ];
    }

    public function attributes(): array
    {
        return [
            'key' => 'field key',
            'min_value' => 'minimum',
            'max_value' => 'maximum',
            'pattern' => 'validation pattern',
        ];
    }

    public function messages(): array
    {
        return [
            'key.regex' => 'The field key must be lowercase letters, digits and underscores, starting with a letter (e.g. isbn_number).',
            'key.unique' => 'This entity already has a field with that key.',
            'options.required' => 'A dropdown needs at least one choice.',
        ];
    }
}
