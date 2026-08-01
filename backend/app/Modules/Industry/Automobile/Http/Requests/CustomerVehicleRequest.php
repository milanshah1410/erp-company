<?php

declare(strict_types=1);

namespace App\Modules\Industry\Automobile\Http\Requests;

use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for the vehicle register.
 *
 * Two rules carry the weight. First, a vehicle must be identifiable by *some*
 * number: a row with neither plate nor chassis is unfindable the moment a
 * second silver hatchback arrives. Second, whichever numbers are given must be
 * unique in the workspace — duplicate vehicles are what ruin a workshop's
 * history, because the second job card lands on the second row and the "last
 * serviced" the advisor reads out at the counter is wrong.
 */
class CustomerVehicleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $id = $this->route('vehicle')?->id;
        $tenantId = app(TenantContext::class)->id();

        return [
            'customer_id' => ['nullable', 'exists:customers,id'],

            'registration_no' => [
                'nullable', 'string', 'max:20',
                Rule::unique('automobile_vehicles', 'registration_no')
                    ->where('tenant_id', $tenantId)->ignore($id),
            ],
            /**
             * 17 characters, and never I, O or Q — the standard leaves them out
             * precisely so they cannot be confused with 1 and 0 by someone
             * reading a stamped chassis plate in a dark bay. Validating it here
             * catches the mistake at the counter instead of six months later
             * when a warranty claim is rejected.
             */
            'vin' => [
                'nullable', 'string', 'size:17', 'regex:/^[A-HJ-NPR-Z0-9]{17}$/i',
                Rule::unique('automobile_vehicles', 'vin')
                    ->where('tenant_id', $tenantId)->ignore($id),
            ],
            'engine_no' => ['nullable', 'string', 'max:30'],

            'make' => ['nullable', 'string', 'max:60'],
            'model' => ['nullable', 'string', 'max:60'],
            'variant' => ['nullable', 'string', 'max:60'],
            // No upper bound of "this year": dealers register next year's models
            // months before the calendar catches up.
            'manufacture_year' => ['nullable', 'integer', 'min:1900', 'max:'.(date('Y') + 1)],
            'fuel_type' => ['nullable', Rule::in(['petrol', 'diesel', 'cng', 'lpg', 'electric', 'hybrid'])],
            'transmission' => ['nullable', Rule::in(['manual', 'automatic', 'amt', 'cvt'])],
            'colour' => ['nullable', 'string', 'max:40'],

            'odometer' => ['nullable', 'integer', 'min:0'],
            'registration_date' => ['nullable', 'date'],
            'delivery_date' => ['nullable', 'date'],
            'sale_invoice_id' => ['nullable', 'exists:invoices,id'],

            'insurance_provider' => ['nullable', 'string', 'max:100'],
            'insurance_policy_no' => ['nullable', 'string', 'max:60'],
            'insurance_expiry' => ['nullable', 'date'],
            'puc_expiry' => ['nullable', 'date'],
            'fitness_expiry' => ['nullable', 'date'],

            'service_interval_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'service_interval_km' => ['nullable', 'integer', 'min:100', 'max:200000'],

            'notes' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if (! $this->filled('registration_no') && ! $this->filled('vin')) {
                $validator->errors()->add(
                    'registration_no',
                    'Give the vehicle a registration number or a chassis (VIN) number — it cannot be found again without one.',
                );
            }
        });
    }

    protected function prepareForValidation(): void
    {
        // Plates and chassis numbers are written in every case under the sun and
        // searched for in one. Normalising on the way in means "gj01ab1234" and
        // "GJ 01 AB 1234" are the same vehicle, which is the only way the
        // uniqueness rules above are worth anything.
        $this->merge(array_filter([
            'registration_no' => $this->filled('registration_no')
                ? strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $this->input('registration_no')))
                : null,
            'vin' => $this->filled('vin') ? strtoupper(trim((string) $this->input('vin'))) : null,
            'engine_no' => $this->filled('engine_no') ? strtoupper(trim((string) $this->input('engine_no'))) : null,
        ], static fn ($v) => $v !== null));
    }
}
