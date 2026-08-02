<?php

declare(strict_types=1);

namespace App\Modules\Industry\Cosmetics\Http\Requests;

use App\Modules\Industry\Cosmetics\Models\OpenUnit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OpenUnitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware handles permission checks
    }

    public function rules(): array
    {
        return [
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'variant_id' => ['nullable', 'integer', 'exists:product_variants,id'],
            'batch_id' => ['nullable', 'integer', 'exists:batches,id'],
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'purpose' => ['required', Rule::in(OpenUnit::PURPOSES)],
            'quantity' => ['required', 'numeric', 'min:0.001'],
            /**
             * Not `today_or_before`, but close: a unit cannot be opened in the
             * future, because the whole register is a record of something that
             * has already happened, and a future date would start a PAO clock
             * that has not begun.
             */
            'opened_on' => ['required', 'date', 'before_or_equal:today'],
            /**
             * Both optional and both defaulted in the service: the PAO from the
             * product's own, and the expiry from opened_on + PAO. Overridable
             * because the symbol on the pack is occasionally not what the product
             * master says, and the pack in the counter's hand wins.
             */
            'pao_months' => ['nullable', 'integer', 'min:1', 'max:120'],
            'expires_on' => ['nullable', 'date', 'after:opened_on'],
            'notes' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'opened_on.before_or_equal' => 'A unit cannot be opened in the future.',
            'expires_on.after' => 'The period after opening has to end after the day it was opened.',
        ];
    }
}
