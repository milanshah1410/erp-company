<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentVoucherResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'voucher_number' => $this->voucher_number,
            'direction' => $this->direction,
            'party_type' => $this->partyAlias(),
            'party_id' => $this->party_id,
            'party_name' => $this->whenLoaded('party', fn () => $this->party?->name),
            'amount' => $this->amount,
            'allocated_amount' => $this->allocated_amount,
            'unallocated_amount' => number_format($this->unallocatedAmount(), 2, '.', ''),
            'is_advance' => $this->isAdvance(),
            'mode' => $this->mode,
            'reference_no' => $this->reference_no,
            'bank_name' => $this->bank_name,
            'cheque_no' => $this->cheque_no,
            'cheque_date' => $this->cheque_date?->toDateString(),
            'cheque_status' => $this->cheque_status,
            'payment_date' => $this->payment_date?->toDateString(),
            'notes' => $this->notes,
            'created_by' => $this->whenLoaded('creator', fn () => $this->creator?->name),
            'allocations' => $this->whenLoaded('allocations', fn () => $this->allocations->map(fn ($a) => [
                'id' => $a->id,
                'allocatable_type' => $a->allocatableAlias(),
                'allocatable_id' => $a->allocatable_id,
                'amount' => $a->amount,
            ])),
            'created_at' => $this->created_at,
        ];
    }
}
