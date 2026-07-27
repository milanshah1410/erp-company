<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Import/export shipment header. `direction` is immutable once created — the
 * document number, the counterparty side and the customs entry type all hang off
 * it — so it is only accepted on store.
 */
class ShipmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware handles permission checks
    }

    public function rules(): array
    {
        $creating = $this->isMethod('post');

        return [
            'direction' => [$creating ? 'required' : 'prohibited', Rule::in(['import', 'export'])],
            'shipment_date' => ['required', 'date'],
            'supplier_id' => ['nullable', 'exists:suppliers,id'],
            'customer_id' => ['nullable', 'exists:customers,id'],
            'purchase_order_id' => ['nullable', 'exists:purchase_orders,id'],
            'sales_order_id' => ['nullable', 'exists:sales_orders,id'],
            'warehouse_id' => ['nullable', 'exists:warehouses,id'],

            'mode' => ['required', Rule::in(['sea', 'air', 'road', 'rail', 'courier'])],
            'incoterm' => ['required', Rule::in(['EXW', 'FCA', 'FOB', 'CFR', 'CIF', 'CIP', 'DAP', 'DDP', 'other'])],
            // Carriers and service providers come from the module's masters.
            'shipping_line_id' => ['nullable', 'exists:shipping_lines,id'],
            'airline_id' => ['nullable', 'exists:airlines,id'],
            'freight_forwarder_id' => ['nullable', 'exists:freight_forwarders,id'],
            'cha_agent_id' => ['nullable', 'exists:cha_agents,id'],
            'transporter_id' => ['nullable', 'exists:transporters,id'],
            'bl_awb_number' => ['nullable', 'string', 'max:255'],
            'bl_awb_date' => ['nullable', 'date'],
            'vessel_name' => ['nullable', 'string', 'max:255'],
            'voyage_number' => ['nullable', 'string', 'max:255'],
            'origin_country_id' => ['nullable', 'exists:countries,id'],
            'destination_country_id' => ['nullable', 'exists:countries,id'],
            'port_of_loading_id' => ['nullable', 'exists:ports,id'],
            'port_of_discharge_id' => ['nullable', 'exists:ports,id'],
            'final_place' => ['nullable', 'string', 'max:255'],

            'etd' => ['nullable', 'date'],
            'eta' => ['nullable', 'date', 'after_or_equal:etd'],
            'atd' => ['nullable', 'date'],
            'ata' => ['nullable', 'date'],

            'goods_description' => ['nullable', 'string'],
            'packages' => ['nullable', 'integer', 'min:0'],
            'gross_weight_kg' => ['nullable', 'numeric', 'min:0'],
            'net_weight_kg' => ['nullable', 'numeric', 'min:0'],
            'cbm' => ['nullable', 'numeric', 'min:0'],

            'currency' => ['required', 'string', 'size:3'],
            'exchange_rate' => ['required', 'numeric', 'min:0.000001'],
            'invoice_number' => ['nullable', 'string', 'max:255'],
            'invoice_date' => ['nullable', 'date'],
            'invoice_value' => ['nullable', 'numeric', 'min:0'],
            'freight_amount' => ['nullable', 'numeric', 'min:0'],
            'insurance_amount' => ['nullable', 'numeric', 'min:0'],
            'goods_cost' => ['nullable', 'numeric', 'min:0'],

            'payment_due_date' => ['nullable', 'date'],
            'lc_number' => ['nullable', 'string', 'max:255'],
            'lc_expiry' => ['nullable', 'date'],
            'remarks' => ['nullable', 'string'],

            // Seed the document checklist for a new shipment (store only).
            'seed_documents' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $direction = $this->input('direction')
                ?? $this->route('shipment')?->direction;

            // An import is bought from a supplier, an export is sold to a
            // customer: requiring the right side keeps the counterparty columns
            // meaningful on every board that groups by party.
            if ($direction === 'import' && ! $this->filled('supplier_id')) {
                $validator->errors()->add('supplier_id', 'An import shipment needs an overseas supplier.');
            }
            if ($direction === 'export' && ! $this->filled('customer_id')) {
                $validator->errors()->add('customer_id', 'An export shipment needs an overseas customer.');
            }
        });
    }

    /** Columns the controller may mass-assign, with the money defaults filled in. */
    public function payload(): array
    {
        $data = $this->validated();
        unset($data['direction'], $data['seed_documents']);

        foreach (['packages' => 0, 'invoice_value' => 0, 'freight_amount' => 0, 'insurance_amount' => 0, 'goods_cost' => 0] as $key => $default) {
            $data[$key] = $data[$key] ?? $default;
        }

        return $data;
    }
}
