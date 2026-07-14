<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RfqSupplierResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'rfq_id' => $this->rfq_id,
            'supplier_id' => $this->supplier_id,
            'supplier' => $this->whenLoaded('supplier', fn () => [
                'id' => $this->supplier->id,
                'name' => $this->supplier->name,
            ]),
            'status' => $this->status,
            'quoted_total' => $this->quoted_total,
            'lead_time_days' => $this->lead_time_days,
            'responded_at' => $this->responded_at,
            'remarks' => $this->remarks,
            'quotes' => RfqQuoteResource::collection($this->whenLoaded('quotes')),
        ];
    }
}
