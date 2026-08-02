<?php

declare(strict_types=1);

namespace App\Modules\Industry\Optical\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shape and bounds only. Whether the slot actually clashes with somebody else's
 * is AppointmentBook's job, so the rule lives in one place and a reschedule gets
 * the same answer a new booking does.
 */
class OpticalAppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'customer_id' => ['nullable', 'exists:customers,id'],
            'patient_name' => ['required', 'string', 'max:150'],
            'patient_phone' => ['nullable', 'string', 'max:30'],
            'patient_age' => ['nullable', 'integer', 'min:0', 'max:130'],

            'purpose' => ['required', Rule::in(['eye_test', 'fitting', 'collection', 'adjustment', 'repair', 'follow_up'])],
            /**
             * A slot may be booked in the past — shops write up the walk-in they
             * have just seen, and refusing that only teaches them to fake the
             * time. Overdue is shown on the board instead.
             */
            'scheduled_at' => ['required', 'date'],
            // Under five minutes is a mis-key; over four hours is not a slot,
            // it is a day.
            'duration_minutes' => ['nullable', 'integer', 'min:5', 'max:240'],

            'optometrist_id' => ['nullable', 'exists:employees,id'],
            'warehouse_id' => ['nullable', 'exists:warehouses,id'],
            'eye_prescription_id' => ['nullable', 'exists:eye_prescriptions,id'],
            'optical_order_id' => ['nullable', 'exists:optical_orders,id'],

            'notes' => ['nullable', 'string'],
        ];
    }
}
