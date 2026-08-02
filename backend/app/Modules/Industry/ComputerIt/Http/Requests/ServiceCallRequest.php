<?php

declare(strict_types=1);

namespace App\Modules\Industry\ComputerIt\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ServiceCallRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware handles permission checks
    }

    public function rules(): array
    {
        return [
            /**
             * Required here even though the column is nullable. The two are not
             * in conflict: the API refuses a call with nobody on it, and the
             * column stays nullable so deleting a customer years later does not
             * take the record of what was done for them with it.
             */
            'customer_id' => ['required', 'exists:customers,id'],
            'asset_id' => ['nullable', 'exists:computer_it_assets,id'],
            'contract_id' => ['nullable', 'exists:service_contracts,id'],
            'warranty_id' => ['nullable', 'exists:computer_it_warranties,id'],

            'type' => ['nullable', Rule::in(['onsite', 'remote', 'carry_in', 'installation', 'preventive'])],
            'source' => ['nullable', Rule::in(['phone', 'email', 'whatsapp', 'walk_in', 'portal'])],
            'priority' => ['nullable', Rule::in(['low', 'normal', 'high', 'critical'])],

            'logged_at' => ['nullable', 'date'],
            // Left out on purpose most of the time: the desk sets a priority and
            // the promise follows from it. Accepted so a contract with an unusual
            // response time can be honoured on the call it applies to.
            'sla_due_at' => ['nullable', 'date'],
            'scheduled_at' => ['nullable', 'date'],

            'assigned_to' => ['nullable', 'exists:employees,id'],
            'site_address' => ['nullable', 'string'],
            'contact_person' => ['nullable', 'string', 'max:100'],
            'contact_phone' => ['nullable', 'string', 'max:20'],

            'complaint' => ['required', 'string'],
            'resolution' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
