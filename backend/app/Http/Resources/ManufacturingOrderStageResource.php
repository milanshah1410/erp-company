<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ManufacturingOrderStageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'stage_key' => $this->stage_key,
            'name' => $this->name,
            'sequence' => $this->sequence,
            'assigned_employee_id' => $this->assigned_employee_id,
            'employee' => $this->whenLoaded('employee', fn () => $this->employee ? [
                'id' => $this->employee->id,
                'name' => $this->employee->fullName(),
            ] : null),
            'start_date' => $this->start_date?->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'status' => $this->status,
            'remarks' => $this->remarks,
            'image' => $this->image,
            'image_url' => $this->image ? asset('storage/'.$this->image) : null,
        ];
    }
}
