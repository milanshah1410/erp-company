<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LeaveApplicationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'employee_name' => $this->whenLoaded('employee', fn () => trim("{$this->employee->employee_code} — {$this->employee->first_name} {$this->employee->last_name}")),
            'leave_type_id' => $this->leave_type_id,
            'leave_type_name' => $this->whenLoaded('leaveType', fn () => $this->leaveType?->name),
            'from_date' => $this->from_date?->format('Y-m-d'),
            'to_date' => $this->to_date?->format('Y-m-d'),
            'days' => (float) $this->days,
            'half_day' => (bool) $this->half_day,
            'reason' => $this->reason,
            'status' => $this->status,
            'approved_by' => $this->approved_by,
            'approver_name' => $this->whenLoaded('approver', fn () => $this->approver?->name),
            'approved_at' => $this->approved_at,
            'remarks' => $this->remarks,
            'created_at' => $this->created_at,
        ];
    }
}
