<?php

declare(strict_types=1);

namespace App\Modules\Industry\Healthcare\Http\Requests;

use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for registering or amending a lab order.
 *
 * `patient_name` is required and `customer_id` is not, which is the right way
 * round for a lab: most orders are a walk-in who gives a name and a phone, and
 * demanding a customer record for each of them turns the master into a phone
 * book. The account, when there is one, is who gets billed.
 */
class LabOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->id();

        return [
            'customer_id' => ['nullable', Rule::exists('customers', 'id')->where('tenant_id', $tenantId)],
            'patient_name' => ['required', 'string', 'max:255'],
            'patient_age' => ['nullable', 'integer', 'min:0', 'max:130'],
            'patient_gender' => ['nullable', Rule::in(['male', 'female', 'other'])],
            'patient_phone' => ['nullable', 'string', 'max:20'],

            'referring_doctor' => ['nullable', 'string', 'max:255'],
            'doctor_registration_no' => ['nullable', 'string', 'max:60'],
            'prescription_id' => ['nullable', Rule::exists('prescriptions', 'id')->where('tenant_id', $tenantId)],

            'order_date' => ['required', 'date'],
            'priority' => ['nullable', Rule::in(['routine', 'urgent'])],
            'discount_type' => ['nullable', Rule::in(['percent', 'flat'])],
            'discount_value' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],

            'items' => ['required', 'array', 'min:1'],
            'items.*.lab_test_id' => [
                'required',
                Rule::exists('lab_tests', 'id')->where('tenant_id', $tenantId),
            ],
            /**
             * Priced from the master when omitted. Overridable because a lab
             * quotes a corporate panel below list every day, and forcing that
             * through a discount would misreport the discount given.
             */
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'items.*.discount_type' => ['nullable', Rule::in(['percent', 'flat'])],
            'items.*.discount_value' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'items.required' => 'An order needs at least one test.',
        ];
    }
}
