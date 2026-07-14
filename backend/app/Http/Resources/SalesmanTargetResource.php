<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SalesmanTargetResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'salesman_id' => $this->salesman_id,
            'salesman' => $this->whenLoaded('salesman', fn () => $this->salesman?->name),
            'period_year' => $this->period_year,
            'period_month' => $this->period_month,
            'target_amount' => $this->target_amount,
            'target_orders' => $this->target_orders,
            'incentive_percent' => $this->incentive_percent,
            'effective_incentive_percent' => $this->effectiveIncentivePercent(),
            'notes' => $this->notes,
            // Live achievement figures, attached by the controller when requested.
            'achievement' => $this->when(isset($this->achievement), fn () => $this->achievement),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
