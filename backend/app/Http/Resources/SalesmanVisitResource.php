<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SalesmanVisitResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'salesman_id' => $this->salesman_id,
            'salesman' => $this->whenLoaded('salesman', fn () => $this->salesman?->name),
            'customer_id' => $this->customer_id,
            'customer' => $this->whenLoaded('customer', fn () => $this->customer?->name),
            'beat_id' => $this->beat_id,
            'beat' => $this->whenLoaded('beat', fn () => $this->beat?->name),
            'visit_date' => $this->visit_date?->toDateString(),
            'sequence' => $this->sequence,
            'status' => $this->status,
            'purpose' => $this->purpose,
            'check_in_at' => $this->check_in_at,
            'check_out_at' => $this->check_out_at,
            'check_in_lat' => $this->check_in_lat,
            'check_in_lng' => $this->check_in_lng,
            'outcome' => $this->outcome,
            'sales_order_id' => $this->sales_order_id,
            'sales_order_number' => $this->whenLoaded('order', fn () => $this->order?->so_number),
            'notes' => $this->notes,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
