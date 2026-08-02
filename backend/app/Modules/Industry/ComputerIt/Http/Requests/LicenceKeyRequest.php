<?php

declare(strict_types=1);

namespace App\Modules\Industry\ComputerIt\Http\Requests;

use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LicenceKeyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware handles permission checks
    }

    public function rules(): array
    {
        $id = $this->route('licence')?->id;
        $tenantId = app(TenantContext::class)->id();

        return [
            'product_id' => ['nullable', 'exists:products,id'],
            'software' => ['required', 'string', 'max:120'],
            'version' => ['nullable', 'string', 'max:40'],
            'publisher' => ['nullable', 'string', 'max:80'],
            'licence_type' => ['nullable', Rule::in(['oem', 'retail', 'volume', 'subscription', 'trial'])],
            /**
             * The whole point of the register: the same key must not go out of
             * the door twice. Enforced in the database as well, because two
             * counters selling at the same moment will both pass validation.
             */
            'licence_key' => [
                'required', 'string', 'max:120',
                Rule::unique('computer_it_licence_keys', 'licence_key')
                    ->where('tenant_id', $tenantId)
                    ->ignore($id),
            ],
            'seats' => ['nullable', 'integer', 'min:1', 'max:100000'],

            'customer_id' => ['nullable', 'exists:customers,id'],
            'asset_id' => ['nullable', 'exists:computer_it_assets,id'],
            'invoice_id' => ['nullable', 'exists:invoices,id'],
            'vendor' => ['nullable', 'string', 'max:120'],

            'valid_from' => ['nullable', 'date'],
            // Nullable means perpetual, which is most of them — see the column
            // comment. A dated licence that ends before it starts is a typo.
            'valid_to' => ['nullable', 'date', 'after_or_equal:valid_from'],

            'notes' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'licence_key.unique' => 'This key is already in the register — it has been sold or issued once already.',
        ];
    }
}
