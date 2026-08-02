<?php

declare(strict_types=1);

namespace App\Modules\Industry\Electronics\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for a service request.
 *
 * Kept deliberately loose about *what* the call is about — a caller who has not
 * found the serial number is still a call worth logging, and a desk that refuses
 * to log it will end up with the whole thing on a notepad instead.
 *
 * It is strict about the two things that make a request actionable: somebody to
 * ring back, and an address when a technician is being sent to one. A visit with
 * no address is a van driving nowhere.
 */
class ServiceRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(['installation', 'repair', 'demo', 'delivery', 'amc_visit'])],
            'source' => ['nullable', Rule::in(['walk_in', 'phone', 'online', 'whatsapp', 'email', 'campaign'])],
            'priority' => ['nullable', Rule::in(['low', 'normal', 'high', 'urgent'])],

            'unit_id' => ['nullable', 'exists:electronics_units,id'],
            'customer_id' => ['nullable', 'exists:customers,id'],
            'product_id' => ['nullable', 'exists:products,id'],

            // Nullable: a fault reported at 9pm is scheduled in the morning, and
            // forcing a slot now produces a diary full of fictional appointments.
            'scheduled_at' => ['nullable', 'date'],
            'promised_at' => ['nullable', 'date'],
            'assigned_to' => ['nullable', 'exists:employees,id'],
            'warehouse_id' => ['nullable', 'exists:warehouses,id'],

            'is_onsite' => ['boolean'],
            'address' => ['nullable', 'string', 'max:1000'],
            'contact_phone' => ['nullable', 'string', 'max:20'],

            'complaint' => ['nullable', 'string'],
            'estimated_cost' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if (! $this->filled('customer_id') && ! $this->filled('contact_phone')) {
                $validator->errors()->add(
                    'contact_phone',
                    'Give a customer or a phone number — a request nobody can be called back on cannot be worked.',
                );
            }

            if ($this->boolean('is_onsite') && ! $this->filled('address') && ! $this->filled('customer_id')) {
                $validator->errors()->add(
                    'address',
                    'An onsite visit needs an address, or a customer to take one from.',
                );
            }

            /**
             * The promise is what the customer was told; the schedule is when the
             * shop intends to go. Intending to go after the promised time is not
             * a scheduling nuance, it is a broken promise being entered on
             * purpose — so it is refused at the desk rather than explained later.
             */
            if ($this->filled('scheduled_at') && $this->filled('promised_at')
                && $this->date('scheduled_at')?->gt($this->date('promised_at'))) {
                $validator->errors()->add(
                    'promised_at',
                    'The visit is scheduled after the time promised to the customer.',
                );
            }
        });
    }
}
