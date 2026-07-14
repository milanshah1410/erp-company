<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeClaimItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'expense_category_id' => $this->expense_category_id,
            'category' => $this->whenLoaded('category', fn () => $this->category?->name),
            'expense_date' => $this->expense_date?->toDateString(),
            'description' => $this->description,
            'amount' => $this->amount,
        ];
    }
}
