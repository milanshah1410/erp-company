<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReferralResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'referrer_customer_id' => $this->referrer_customer_id,
            'referrer' => $this->whenLoaded('referrer', fn () => [
                'id' => $this->referrer?->id,
                'code' => $this->referrer?->code,
                'name' => $this->referrer?->name,
            ]),
            'referred_name' => $this->referred_name,
            'referred_phone' => $this->referred_phone,
            'referred_customer_id' => $this->referred_customer_id,
            'referred_customer' => $this->whenLoaded('referredCustomer', fn () => [
                'id' => $this->referredCustomer?->id,
                'code' => $this->referredCustomer?->code,
                'name' => $this->referredCustomer?->name,
            ]),
            'status' => $this->status,
            'reward_points' => $this->reward_points,
            'notes' => $this->notes,
            'joined_at' => $this->joined_at,
            'rewarded_at' => $this->rewarded_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
