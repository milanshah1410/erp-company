<?php

declare(strict_types=1);

namespace App\Modules\Industry\Agriculture\Http\Requests;

use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for a flock placement.
 *
 * Note what is *absent*: mortality, culls, feed and eggs. Those are owned by
 * FarmProductionService and moved only when a daily log posts, so accepting them
 * here would let a form silently overwrite figures the log history says are
 * something else. `sold_birds` is the exception — nothing else writes it, so the
 * batch record is where birds leaving for sale are stated.
 */
class LivestockFlockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $flockId = $this->route('livestockFlock')?->id;

        return [
            'batch_code' => [
                'required', 'string', 'max:50',
                Rule::unique('livestock_flocks', 'batch_code')
                    ->where('tenant_id', app(TenantContext::class)->id())
                    ->ignore($flockId),
            ],
            'bird_type' => ['required', Rule::in(['broiler', 'layer', 'breeder', 'duck', 'other'])],
            'breed' => ['nullable', 'string', 'max:100'],
            'placed_on' => ['required', 'date'],
            // Birds placed is the denominator of every ratio the farm is judged
            // on, so it must be a real number rather than filled in later.
            'opening_birds' => ['required', 'integer', 'min:1'],
            'sold_birds' => ['nullable', 'integer', 'min:0'],
            'chick_cost' => ['nullable', 'numeric', 'min:0'],
            // The hatchery the chicks came from.
            'supplier_id' => ['nullable', 'exists:suppliers,id'],
            // The shed: eggs land here and feed is drawn from here.
            'warehouse_id' => ['nullable', 'exists:warehouses,id'],
            'status' => ['required', Rule::in(['active', 'closed'])],
            'closed_on' => [
                'nullable', 'date', 'after_or_equal:placed_on',
                Rule::requiredIf(fn () => $this->input('status') === 'closed'),
            ],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'batch_code.unique' => 'That batch code is already in use.',
            'closed_on.required' => 'Record the date the batch was closed out.',
        ];
    }
}
