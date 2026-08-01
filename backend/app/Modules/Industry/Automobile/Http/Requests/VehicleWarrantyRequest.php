<?php

declare(strict_types=1);

namespace App\Modules\Industry\Automobile\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for a warranty registration.
 *
 * The rule that matters is that cover must attach to *something* checkable. A
 * vehicle warranty needs a vehicle; a part warranty needs the part. A row with
 * neither is a promise nobody can look up when the customer comes back with it,
 * which is the only moment a warranty register is ever used.
 */
class VehicleWarrantyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(['vehicle', 'part', 'service'])],
            'provider' => ['nullable', Rule::in(['manufacturer', 'dealer', 'third_party'])],

            'vehicle_id' => ['nullable', 'exists:automobile_vehicles,id'],
            'customer_id' => ['nullable', 'exists:customers,id'],
            'product_id' => ['nullable', 'exists:products,id'],
            'serial_no' => ['nullable', 'string', 'max:100'],
            'invoice_id' => ['nullable', 'exists:invoices,id'],
            'service_job_id' => ['nullable', 'exists:service_jobs,id'],

            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after:start_date'],
            'coverage_months' => ['nullable', 'integer', 'min:1', 'max:600'],
            'coverage_km' => ['nullable', 'integer', 'min:1'],
            // The reading the cover starts counting from. Usually the odometer
            // at delivery, and zero for a new vehicle.
            'start_odometer' => ['nullable', 'integer', 'min:0'],

            'status' => ['nullable', Rule::in(['active', 'expired', 'claimed', 'void'])],
            'terms' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $type = $this->input('type');

            if (in_array($type, ['vehicle', 'service'], true) && ! $this->filled('vehicle_id')) {
                $validator->errors()->add('vehicle_id', 'Vehicle and service cover has to name the vehicle it covers.');
            }

            if ($type === 'part' && ! $this->filled('product_id') && ! $this->filled('serial_no')) {
                $validator->errors()->add('product_id', 'Part cover has to name the part, or the serial stamped on it.');
            }

            // Distance cover with no starting reading counts from zero, which
            // silently halves a 40,000 km warranty on a car delivered with
            // 20,000 on the clock. Ask for the number rather than assume it.
            if ($this->filled('coverage_km') && ! $this->filled('start_odometer')) {
                $validator->errors()->add(
                    'start_odometer',
                    'Give the reading the cover starts from — otherwise the kilometre limit is counted from zero.',
                );
            }
        });
    }
}
