<?php

declare(strict_types=1);

namespace App\Modules\Industry\Agriculture\Http\Requests;

use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for one animal in the herd.
 *
 * The tag number is unique per farm because it is the identifier staff actually
 * call out; two cows answering to 47 makes every yield record ambiguous. An
 * animal that has left must say when — a herd list showing an animal as sold with
 * no date cannot be reconciled against the sale.
 */
class LivestockAnimalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $animalId = $this->route('livestockAnimal')?->id;

        return [
            'tag_number' => [
                'required', 'string', 'max:50',
                Rule::unique('livestock_animals', 'tag_number')
                    ->where('tenant_id', app(TenantContext::class)->id())
                    ->ignore($animalId),
            ],
            'name' => ['nullable', 'string', 'max:100'],
            'species' => ['required', Rule::in(['cow', 'buffalo', 'goat', 'sheep', 'other'])],
            'breed' => ['nullable', 'string', 'max:100'],
            'gender' => ['required', Rule::in(['female', 'male'])],
            'date_of_birth' => ['nullable', 'date', 'before_or_equal:today'],
            'acquired_on' => ['nullable', 'date'],
            'acquisition_type' => ['required', Rule::in(['born', 'purchased'])],
            'acquisition_cost' => ['nullable', 'numeric', 'min:0'],
            'supplier_id' => ['nullable', 'exists:suppliers,id'],
            // The shed. Production defaults to landing in this warehouse.
            'warehouse_id' => ['nullable', 'exists:warehouses,id'],
            'dam_tag' => ['nullable', 'string', 'max:50'],
            'sire_tag' => ['nullable', 'string', 'max:50'],
            'lactation_number' => ['nullable', 'integer', 'min:0', 'max:20'],
            'status' => ['required', Rule::in(['active', 'dry', 'sold', 'dead', 'culled'])],
            'exited_on' => [
                'nullable', 'date',
                Rule::requiredIf(fn () => in_array($this->input('status'), ['sold', 'dead', 'culled'], true)),
            ],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'tag_number.unique' => 'That tag number is already used by another animal.',
            'exited_on.required' => 'Record the date the animal left the herd.',
        ];
    }
}
