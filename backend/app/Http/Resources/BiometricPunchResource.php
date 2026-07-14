<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BiometricPunchResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'biometric_device_id' => $this->biometric_device_id,
            'device_name' => $this->whenLoaded('device', fn () => $this->device?->name),
            'employee_id' => $this->employee_id,
            'employee_name' => $this->whenLoaded('employee', fn () => trim("{$this->employee->employee_code} — {$this->employee->first_name} {$this->employee->last_name}")),
            'device_user_id' => $this->device_user_id,
            'punched_at' => $this->punched_at?->format('Y-m-d H:i:s'),
            'direction' => $this->direction,
            'source' => $this->source,
            'processed' => (bool) $this->processed,
        ];
    }
}
