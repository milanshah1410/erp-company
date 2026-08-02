<?php

declare(strict_types=1);

namespace App\Modules\Industry\Sports\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Booking or writing a hire contract.
 *
 * Everything expensive — is the kit free, is it offered on this basis, is it on
 * the bench — is checked in RentalService rather than here, because those are
 * facts about the fleet at a moment in time and not about the shape of the
 * request. What this class guarantees is only that the shape is answerable: a
 * customer, a return time, and at least one thing to hire.
 */
class RentalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware handles permission checks
    }

    public function rules(): array
    {
        $editing = $this->route('rental') !== null;

        return [
            'customer_id' => [$editing ? 'sometimes' : 'required', 'integer', 'exists:customers,id'],
            'warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'rate_basis' => ['nullable', Rule::in(['hourly', 'daily', 'weekly', 'monthly'])],

            'booked_from' => ['nullable', 'date'],
            'due_at' => [$editing ? 'sometimes' : 'required', 'date'],
            /**
             * Capped at a week. Grace is meant to settle the twenty-minute
             * overrun, not to become an unpriced second hire — a shop that wants
             * to lend kit for another day should book another day.
             */
            'grace_hours' => ['nullable', 'integer', 'min:0', 'max:168'],

            'id_proof_type' => ['nullable', 'string', 'max:40'],
            'id_proof_number' => ['nullable', 'string', 'max:60'],

            'discount_type' => ['nullable', Rule::in(['percent', 'flat'])],
            'discount_value' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],

            /**
             * Required on create, optional on edit — sending no `assets` key
             * leaves the lines alone, which is what a form that only changed the
             * due time should be able to do. Sending an empty array is a
             * different statement ("take everything off"), and is refused by the
             * min:1 below rather than silently emptying a booking.
             */
            'assets' => [$editing ? 'sometimes' : 'required', 'array', 'min:1'],
            'assets.*.asset_id' => ['required', 'integer', 'exists:sports_rental_assets,id'],
            /**
             * The condition it is going out in. Defaults to whatever the fleet
             * register last recorded, but overridable at the counter because the
             * person handing it over is looking at it and the register is not.
             * This is the evidence a damage charge is argued from later.
             */
            'assets.*.condition_out' => ['nullable', Rule::in(['new', 'good', 'fair', 'worn', 'damaged'])],
        ];
    }

    public function messages(): array
    {
        return [
            'assets.required' => 'A hire needs at least one piece of kit on it.',
            'assets.min' => 'A hire needs at least one piece of kit on it.',
            'due_at.required' => 'Say when the kit is due back — a hire with no return time is the one that never comes back.',
        ];
    }
}
