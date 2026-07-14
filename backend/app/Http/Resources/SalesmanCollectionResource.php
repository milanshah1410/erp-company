<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SalesmanCollectionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'collection_number' => $this->collection_number,
            'salesman_id' => $this->salesman_id,
            'salesman' => $this->whenLoaded('salesman', fn () => $this->salesman?->name),
            'customer_id' => $this->customer_id,
            'customer' => $this->whenLoaded('customer', fn () => $this->customer?->name),
            'invoice_id' => $this->invoice_id,
            'amount' => $this->amount,
            'mode' => $this->mode,
            'reference_no' => $this->reference_no,
            'collection_date' => $this->collection_date?->toDateString(),
            'status' => $this->status,
            'deposited_at' => $this->deposited_at,
            'notes' => $this->notes,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
