<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BankAccountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type,
            'is_petty_cash' => (bool) $this->is_petty_cash,
            'bank_name' => $this->bank_name,
            'account_number' => $this->account_number,
            'ifsc' => $this->ifsc,
            'branch_name' => $this->branch_name,
            'opening_balance' => (float) $this->opening_balance,
            'opening_balance_date' => $this->opening_balance_date?->toDateString(),
            'branch_id' => $this->branch_id,
            'branch' => $this->whenLoaded('branch', fn () => $this->branch?->name),
            'is_active' => $this->is_active,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
