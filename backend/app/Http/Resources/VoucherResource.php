<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VoucherResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'voucher_number' => $this->voucher_number,
            'voucher_type' => $this->voucher_type,
            'voucher_date' => $this->voucher_date?->toDateString(),
            'amount' => (float) $this->amount,
            'mode' => $this->mode,
            'bank_account_id' => $this->bank_account_id,
            'bank_account' => $this->whenLoaded('bankAccount', fn () => $this->bankAccount?->name),
            'counter_account_id' => $this->counter_account_id,
            'counter_account' => $this->whenLoaded('counterAccount', fn () => $this->counterAccount?->name),
            'debit_ledger' => $this->debit_ledger,
            'credit_ledger' => $this->credit_ledger,
            'party_type' => $this->party_type,
            'party_id' => $this->party_id,
            'party_name' => $this->party_name,
            'reference_no' => $this->reference_no,
            'narration' => $this->narration,
            'branch_id' => $this->branch_id,
            'branch' => $this->whenLoaded('branch', fn () => $this->branch?->name),
            'status' => $this->status,
            'created_by' => $this->whenLoaded('creator', fn () => $this->creator?->name),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
