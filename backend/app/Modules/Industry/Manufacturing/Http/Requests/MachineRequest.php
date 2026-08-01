<?php

declare(strict_types=1);

namespace App\Modules\Industry\Manufacturing\Http\Requests;

use App\Modules\Industry\Manufacturing\Models\Machine;
use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for the machine master.
 *
 * Only the code is really enforced: it is what a docket, a downtime slip and a
 * schedule all refer to, and two machines answering to "PRESS-2" is a
 * mis-loaded order waiting to happen. Everything else — capacity, rate, serial
 * number — is optional, because a shop should be able to write down the machine
 * it has today and rate it properly next week.
 */
class MachineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $machineId = $this->route('machine')?->id;

        return [
            'code' => [
                'required', 'string', 'max:40',
                // Matches the table's unique index so a duplicate comes back as
                // a field error rather than a 500 from the constraint.
                Rule::unique('machines', 'code')
                    ->where('tenant_id', app(TenantContext::class)->id())
                    ->ignore($machineId),
            ],
            'name' => ['required', 'string', 'max:255'],
            'machine_type' => ['nullable', 'string', 'max:60'],
            'warehouse_id' => ['nullable', 'exists:warehouses,id'],
            'department_id' => ['nullable', 'exists:departments,id'],
            'asset_id' => ['nullable', 'exists:assets,id'],
            'operator_id' => ['nullable', 'exists:employees,id'],
            'manufacturer' => ['nullable', 'string', 'max:255'],
            'model_number' => ['nullable', 'string', 'max:100'],
            'serial_number' => ['nullable', 'string', 'max:100'],
            'installed_on' => ['nullable', 'date'],
            'capacity_per_hour' => ['nullable', 'numeric', 'min:0'],
            'capacity_uom' => ['nullable', 'string', 'max:20'],
            'hourly_cost' => ['nullable', 'numeric', 'min:0'],
            'power_kw' => ['nullable', 'numeric', 'min:0'],
            'status' => ['nullable', Rule::in(Machine::STATUSES)],
            'last_service_on' => ['nullable', 'date'],
            'next_service_due' => ['nullable', 'date'],
            'is_active' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.unique' => 'A machine with this code already exists.',
        ];
    }
}
