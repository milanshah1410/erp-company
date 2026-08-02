<?php

declare(strict_types=1);

namespace App\Modules\Industry\Electronics\Http\Requests;

use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for a unit registration.
 *
 * The rule that matters is that a unit has to be *findable*. The register exists
 * so that a customer arriving in eighteen months with a fault can be matched to
 * the machine they bought, and a row with no serial, no IMEI and no product on it
 * cannot be matched to anything — it is a note, not a record.
 *
 * Uniqueness is scoped to the tenant by the BelongsToTenant global scope on the
 * model, but Rule::unique talks to the table directly and does not see it, so the
 * tenant is stated explicitly. Without that, one workspace registering an IMEI
 * would block every other workspace from ever registering the same handset — and
 * refurbished stock genuinely does move between shops.
 */
class DeviceUnitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $id = $this->route('unit')?->id;
        $tenantId = app(TenantContext::class)->id();

        $unique = fn (string $column) => Rule::unique('electronics_units', $column)
            ->where('tenant_id', $tenantId)
            ->ignore($id);

        return [
            'customer_id' => ['nullable', 'exists:customers,id'],
            'product_id' => ['nullable', 'exists:products,id'],
            'brand' => ['nullable', 'string', 'max:60'],
            'model_name' => ['nullable', 'string', 'max:120'],

            'serial_no' => ['nullable', 'string', 'max:100', $unique('serial_no')],
            // 15 digits, and only digits. A mistyped IMEI is worse than a missing
            // one: it looks like an answer and matches nothing.
            'imei_1' => ['nullable', 'string', 'digits:15', $unique('imei_1')],
            'imei_2' => ['nullable', 'string', 'digits:15', 'different:imei_1'],

            'status' => ['nullable', Rule::in(['in_stock', 'sold', 'returned', 'scrapped'])],
            'is_third_party' => ['boolean'],

            'sale_invoice_id' => ['nullable', 'exists:invoices,id'],
            'sold_at' => ['nullable', 'date'],
            'delivered_at' => ['nullable', 'date'],
            'installation_required' => ['boolean'],
            'installed_at' => ['nullable', 'date'],

            'warranty_months' => ['nullable', 'integer', 'min:1', 'max:600'],
            'amc_contract_id' => ['nullable', 'exists:service_contracts,id'],

            'notes' => ['nullable', 'string'],
            'is_active' => ['boolean'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if (! $this->filled('serial_no') && ! $this->filled('imei_1') && ! $this->filled('product_id')) {
                $validator->errors()->add(
                    'serial_no',
                    'Give a serial, an IMEI or at least the product — otherwise nobody can find this unit again.',
                );
            }

            // A machine cannot be installed before it was sold. Usually a
            // mis-keyed year, and it silently breaks every warranty counted from
            // the installation date.
            if ($this->filled('installed_at') && $this->filled('sold_at')
                && $this->date('installed_at')?->lt($this->date('sold_at'))) {
                $validator->errors()->add('installed_at', 'The installation date is before the sale date.');
            }
        });
    }
}
