<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BrokerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'mobile' => $this->mobile,
            'address' => $this->address,
            'gst_number' => $this->gst_number,
            'pan_number' => $this->pan_number,
            'commission_percent' => $this->commission_percent,
            'fixed_commission' => $this->fixed_commission,
            'tds_percent' => $this->tds_percent,
            'opening_balance' => $this->opening_balance,
            'wallet_balance' => $this->wallet_balance,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
