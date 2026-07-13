<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BrokerSettlementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'broker_id' => $this->broker_id,
            'settlement_number' => $this->settlement_number,
            'amount' => $this->amount,
            'mode' => $this->mode,
            'settlement_date' => $this->settlement_date?->toDateString(),
            'notes' => $this->notes,
            'created_at' => $this->created_at,
        ];
    }
}
