<?php

declare(strict_types=1);

namespace App\Modules\Industry\Automobile\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for a service appointment.
 *
 * Deliberately permissive about the past: a shop keys in yesterday's walk-in
 * this morning, and refusing a back-dated slot would make the appointment book
 * disagree with what actually happened on the ramp.
 */
class VehicleBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'vehicle_id' => ['required', 'exists:automobile_vehicles,id'],
            // Optional because the vehicle already knows its owner; sent only
            // when the caller is someone else (a fleet driver, a spouse).
            'customer_id' => ['nullable', 'exists:customers,id'],

            'service_type' => ['nullable', Rule::in([
                'general', 'free_service', 'running_repair', 'accident',
                'warranty', 'inspection', 'body_paint',
            ])],
            'source' => ['nullable', Rule::in(['walk_in', 'phone', 'online', 'whatsapp', 'campaign'])],

            'scheduled_at' => ['required', 'date'],
            'estimated_ready_at' => ['nullable', 'date', 'after_or_equal:scheduled_at'],
            'assigned_to' => ['nullable', 'exists:employees,id'],
            'warehouse_id' => ['nullable', 'exists:warehouses,id'],

            'odometer' => ['nullable', 'integer', 'min:0'],
            'complaint' => ['nullable', 'string'],
            'estimated_cost' => ['nullable', 'numeric', 'min:0'],

            'pickup_required' => ['nullable', 'boolean'],
            'pickup_address' => ['nullable', 'string', 'required_if:pickup_required,true'],

            'notes' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'pickup_address.required_if' => 'A pickup needs an address to collect the vehicle from.',
        ];
    }
}
