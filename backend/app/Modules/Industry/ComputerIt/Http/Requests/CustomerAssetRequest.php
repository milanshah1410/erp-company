<?php

declare(strict_types=1);

namespace App\Modules\Industry\ComputerIt\Http\Requests;

use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CustomerAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware handles permission checks
    }

    public function rules(): array
    {
        $id = $this->route('asset')?->id;
        $tenantId = app(TenantContext::class)->id();

        return [
            'customer_id' => ['nullable', 'exists:customers,id'],
            'product_id' => ['nullable', 'exists:products,id'],

            'category' => ['nullable', Rule::in([
                'laptop', 'desktop', 'server', 'printer', 'network',
                'peripheral', 'ups', 'software', 'other',
            ])],
            'make' => ['nullable', 'string', 'max:60'],
            'model' => ['nullable', 'string', 'max:60'],
            /**
             * Unique per tenant, scoped by hand because the model's global scope
             * does not reach a validation rule. Two rows for one machine is what
             * splits a service history in half, so this is the one identity rule
             * worth being strict about — while still allowing the assembled
             * desktop that has no serial at all.
             */
            'serial_no' => [
                'nullable', 'string', 'max:100',
                Rule::unique('computer_it_assets', 'serial_no')
                    ->where('tenant_id', $tenantId)
                    ->ignore($id),
            ],
            'specification' => ['nullable', 'string'],
            'os' => ['nullable', 'string', 'max:60'],
            'mac_address' => ['nullable', 'string', 'max:40'],

            'site' => ['nullable', 'string', 'max:120'],
            'department' => ['nullable', 'string', 'max:80'],
            'assigned_user' => ['nullable', 'string', 'max:120'],

            'purchase_date' => ['nullable', 'date'],
            'installed_on' => ['nullable', 'date'],
            'sale_invoice_id' => ['nullable', 'exists:invoices,id'],
            'amc_contract_id' => ['nullable', 'exists:service_contracts,id'],

            'status' => ['nullable', Rule::in(['in_stock', 'deployed', 'in_service', 'standby', 'retired'])],
            // Two years is the longest interval anyone sets; beyond that the
            // shop is not promising preventive visits, it is guessing.
            'service_interval_days' => ['nullable', 'integer', 'min:1', 'max:730'],

            'notes' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'serial_no.unique' => 'That serial number is already on the register — open the existing machine instead of adding a second one.',
        ];
    }
}
