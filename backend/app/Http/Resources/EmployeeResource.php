<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_code' => $this->employee_code,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            // Composite label used by dropdown pickers (optionLabel: 'name').
            'name' => trim("{$this->employee_code} — {$this->first_name} {$this->last_name}"),
            'full_name' => trim("{$this->first_name} {$this->last_name}"),
            'email' => $this->email,
            'phone' => $this->phone,
            'designation' => $this->designation,
            'employment_type' => $this->employment_type,
            'user_id' => $this->user_id,
            'department_id' => $this->department_id,
            'department_name' => $this->whenLoaded('department', fn () => $this->department?->name),
            'user_name' => $this->whenLoaded('user', fn () => $this->user?->name),
            'date_of_joining' => $this->date_of_joining?->format('Y-m-d'),
            'date_of_birth' => $this->date_of_birth?->format('Y-m-d'),
            'gender' => $this->gender,
            'address' => $this->address,
            'is_active' => (bool) $this->is_active,
            'created_at' => $this->created_at,
        ];
    }
}
