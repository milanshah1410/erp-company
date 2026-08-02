<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ServiceJobRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'customer_id' => ['required', 'exists:customers,id'],
            'service_contract_id' => ['nullable', 'exists:service_contracts,id'],
            'warehouse_id' => ['nullable', 'exists:warehouses,id'],
            'assigned_to' => ['nullable', 'exists:employees,id'],
            'priority' => ['nullable', Rule::in(['low', 'normal', 'high', 'urgent'])],

            'item_name' => ['required', 'string', 'max:200'],
            'item_serial' => ['nullable', 'string', 'max:100'],

            /*
             * Job-card fields, contributed by the Automobile plugin. Nullable
             * and ignored by every other repair trade; the vehicle is validated
             * against the plugin's table, which always exists because plugin
             * migrations run with the rest (IndustryServiceProvider).
             */
            'vehicle_id' => ['nullable', 'exists:automobile_vehicles,id'],
            'odometer_in' => ['nullable', 'integer', 'min:0'],
            'odometer_out' => ['nullable', 'integer', 'min:0', 'gte:odometer_in'],
            /*
             * The same seam, for the Computer & IT plugin: a job opened straight
             * from the Service screen can still name the customer's machine, so
             * a walk-in repair joins the same install base a converted call does.
             * The cover and the call are set by the conversion service, not by a
             * client, so only the machine is accepted here.
             */
            'it_asset_id' => ['nullable', 'exists:computer_it_assets,id'],
            'complaint' => ['nullable', 'string'],
            'diagnosis' => ['nullable', 'string'],
            'resolution' => ['nullable', 'string'],
            'is_warranty' => ['nullable', 'boolean'],

            'received_at' => ['nullable', 'date'],
            'promised_at' => ['nullable', 'date'],

            'labour_charge' => ['nullable', 'numeric', 'min:0'],
            'labour_tax_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'discount_type' => ['nullable', Rule::in(['percent', 'flat'])],
            'discount_value' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],

            // Parts are optional at intake — most jobs are booked in before
            // anyone knows what they need.
            'items' => ['nullable', 'array'],
            // Existing lines carry their id so an edit can tell "this is the
            // line you already have" from "this is a new one" — without it an
            // already-issued part comes back as a second copy.
            'items.*.id' => ['nullable', 'integer'],
            'items.*.product_id' => ['required_with:items', 'exists:products,id'],
            'items.*.variant_id' => ['nullable', 'exists:product_variants,id'],
            'items.*.quantity' => ['required_with:items', 'numeric', 'min:0.001'],
            'items.*.unit_price' => ['required_with:items', 'numeric', 'min:0'],
            'items.*.tax_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'items.*.discount_type' => ['nullable', Rule::in(['percent', 'flat'])],
            'items.*.discount_value' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
