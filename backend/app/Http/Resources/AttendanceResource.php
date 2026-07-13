<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AttendanceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'employee_name' => $this->whenLoaded('employee', fn () => trim("{$this->employee->employee_code} — {$this->employee->first_name} {$this->employee->last_name}")),
            'date' => $this->date?->format('Y-m-d'),
            'status' => $this->status,
            'check_in' => $this->check_in ? substr((string) $this->check_in, 0, 5) : null,
            'check_out' => $this->check_out ? substr((string) $this->check_out, 0, 5) : null,
            'remarks' => $this->remarks,
            'created_at' => $this->created_at,
        ];
    }
}
