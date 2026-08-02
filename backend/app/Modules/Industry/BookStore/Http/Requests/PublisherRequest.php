<?php

declare(strict_types=1);

namespace App\Modules\Industry\BookStore\Http\Requests;

use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PublisherRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware handles permission checks
    }

    public function rules(): array
    {
        $id = $this->route('publisher')?->id;
        $tenantId = app(TenantContext::class)->id();

        return [
            'code' => [
                'required', 'string', 'max:40',
                Rule::unique('book_publishers', 'code')->where('tenant_id', $tenantId)->ignore($id),
            ],
            'name' => ['required', 'string', 'max:160'],
            'imprint' => ['nullable', 'string', 'max:120'],

            // Who the order actually goes to — usually a distributor, which is
            // why this is optional rather than assumed to be the house itself.
            'supplier_id' => ['nullable', 'exists:suppliers,id'],

            /**
             * The trade discount is a percentage off the printed price and is
             * therefore bounded by it. Capped at 90 rather than 100 because a
             * hundred-per-cent discount is not a term anybody has ever been
             * offered — it is a decimal point in the wrong place, and this is the
             * only place it can be caught before it reprices a buyer's whole view
             * of the house.
             */
            'trade_discount_percent' => ['nullable', 'numeric', 'min:0', 'max:90'],

            'is_returnable' => ['boolean'],
            /**
             * The return window, in days from the invoice. Left blank on a
             * returnable house it means the arrangement is open-ended, which is a
             * real answer and not a missing one — so this stays nullable even when
             * `is_returnable` is on.
             */
            'return_window_days' => ['nullable', 'integer', 'min:1', 'max:1095'],

            'contact_person' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:160'],
            'website' => ['nullable', 'string', 'max:190'],
            'notes' => ['nullable', 'string'],
            'is_active' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.unique' => 'A publisher with this code already exists.',
            'trade_discount_percent.max' => 'A trade discount above 90% is almost always a misplaced decimal point.',
        ];
    }
}
