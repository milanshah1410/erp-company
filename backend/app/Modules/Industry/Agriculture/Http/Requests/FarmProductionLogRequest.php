<?php

declare(strict_types=1);

namespace App\Modules\Industry\Agriculture\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for a daily production entry.
 *
 * Three rules that the schema cannot express and the screens must not have to
 * remember:
 *
 *  1. Exactly one source. Output came from an animal or from a flock, never
 *     both and never neither — a log with no source posts stock nobody produced.
 *  2. Mortality belongs to flocks. An individual animal's death is a change of
 *     status on its own record, with a date; counting it here would leave the
 *     herd list showing a cow that is alive and a log saying she died.
 *  3. Feed needs a quantity, and feed may not be the produce. A row claiming a
 *     shed both produced and ate the same product is a keying slip that would
 *     post two opposing movements and read as nothing having happened.
 */
class FarmProductionLogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Farms key yesterday's book on a bad morning; the future they cannot
            // know yet.
            'log_date' => ['required', 'date', 'before_or_equal:today'],
            'session' => ['required', Rule::in(['morning', 'evening', 'day'])],

            'animal_id' => ['nullable', 'required_without:flock_id', 'exists:livestock_animals,id'],
            'flock_id' => ['nullable', 'required_without:animal_id', 'exists:livestock_flocks,id'],

            'product_id' => ['required', 'exists:products,id'],
            'warehouse_id' => ['required', 'exists:warehouses,id'],
            // Zero is legitimate: a shed with nothing but deaths to report still
            // has to report them.
            'quantity' => ['required', 'numeric', 'min:0'],
            'unit_cost' => ['nullable', 'numeric', 'min:0'],
            'fat_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'snf_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],

            'feed_product_id' => ['nullable', 'exists:products,id', 'different:product_id'],
            'feed_quantity' => ['nullable', 'numeric', 'min:0', 'required_with:feed_product_id'],

            'mortality_count' => ['nullable', 'integer', 'min:0'],
            'culled_count' => ['nullable', 'integer', 'min:0'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            if ($this->filled('animal_id') && $this->filled('flock_id')) {
                $v->errors()->add('flock_id', 'Record the output against an animal or a flock, not both.');
            }

            if ($this->filled('animal_id')) {
                if ((int) $this->input('mortality_count', 0) > 0 || (int) $this->input('culled_count', 0) > 0) {
                    $v->errors()->add(
                        'mortality_count',
                        "Set the animal's status to dead or culled on its own record instead.",
                    );
                }
            }
        });
    }

    public function messages(): array
    {
        return [
            'animal_id.required_without' => 'Choose the animal or the flock this output came from.',
            'flock_id.required_without' => 'Choose the animal or the flock this output came from.',
            'feed_product_id.different' => 'Feed cannot be the same product as the output.',
            'feed_quantity.required_with' => 'How much feed was drawn?',
        ];
    }
}
