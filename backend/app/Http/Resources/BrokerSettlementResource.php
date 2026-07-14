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
            'tds_percent' => $this->tds_percent,
            'tds_amount' => $this->tds_amount,
            'net_paid' => $this->net_paid,
            'mode' => $this->mode,
            'settlement_date' => $this->settlement_date?->toDateString(),
            'period_month' => $this->period_month,
            'notes' => $this->notes,
            'created_at' => $this->created_at,
        ];
    }
}
