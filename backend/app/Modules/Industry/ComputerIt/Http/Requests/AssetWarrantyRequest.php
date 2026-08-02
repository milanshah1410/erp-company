<?php

declare(strict_types=1);

namespace App\Modules\Industry\ComputerIt\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssetWarrantyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware handles permission checks
    }

    public function rules(): array
    {
        return [
            'type' => ['nullable', Rule::in(['product', 'part', 'service', 'extended'])],
            'provider' => ['nullable', Rule::in(['manufacturer', 'dealer', 'third_party'])],

            /**
             * Required except for a part warranty, which a counter registers
             * against the part and the invoice before anyone knows which machine
             * it will end up inside.
             */
            'asset_id' => [
                Rule::requiredIf(fn () => $this->input('type', 'product') !== 'part'),
                'nullable', 'exists:computer_it_assets,id',
            ],
            'customer_id' => ['nullable', 'exists:customers,id'],
            'product_id' => ['nullable', 'exists:products,id'],
            'serial_no' => ['nullable', 'string', 'max:100'],
            'invoice_id' => ['nullable', 'exists:invoices,id'],
            'service_job_id' => ['nullable', 'exists:service_jobs,id'],

            'start_date' => ['required', 'date'],
            // Cover that ends before it starts is a mis-keyed date, and it would
            // read as "expired" forever afterwards without anyone knowing why.
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'coverage_months' => ['nullable', 'integer', 'min:1', 'max:120'],
            'service_level' => ['nullable', Rule::in(['carry_in', 'onsite', 'nbd_onsite'])],

            'status' => ['nullable', Rule::in(['active', 'expired', 'claimed', 'void'])],
            'terms' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
