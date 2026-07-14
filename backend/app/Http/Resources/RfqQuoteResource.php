<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RfqQuoteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'rfq_supplier_id' => $this->rfq_supplier_id,
            'rfq_item_id' => $this->rfq_item_id,
            'unit_price' => $this->unit_price,
            'tax_percent' => $this->tax_percent,
            'remarks' => $this->remarks,
        ];
    }
}
