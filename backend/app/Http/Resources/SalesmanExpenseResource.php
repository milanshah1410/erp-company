<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SalesmanExpenseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'salesman_id' => $this->salesman_id,
            'salesman' => $this->whenLoaded('salesman', fn () => $this->salesman?->name),
            'expense_date' => $this->expense_date?->toDateString(),
            'category' => $this->category,
            'amount' => $this->amount,
            'description' => $this->description,
            'status' => $this->status,
            'rejection_reason' => $this->rejection_reason,
            'approved_by' => $this->approved_by,
            'approved_at' => $this->approved_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
