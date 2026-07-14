<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LeadResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'company_name' => $this->company_name,
            'email' => $this->email,
            'phone' => $this->phone,
            'source' => $this->source,
            'status' => $this->status,
            'estimated_value' => $this->estimated_value,
            'expected_close_date' => $this->expected_close_date?->toDateString(),
            'assigned_to' => $this->assigned_to,
            'assignee' => $this->whenLoaded('assignee', fn () => [
                'id' => $this->assignee?->id,
                'name' => $this->assignee?->name,
            ]),
            'notes' => $this->notes,
            'lost_reason' => $this->lost_reason,
            'converted_customer_id' => $this->converted_customer_id,
            'converted_at' => $this->converted_at,
            'is_converted' => $this->isConverted(),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
