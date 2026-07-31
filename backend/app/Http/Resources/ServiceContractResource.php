<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ServiceContractResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'contract_number' => $this->contract_number,
            'customer_id' => $this->customer_id,
            'customer' => $this->whenLoaded('customer', fn () => [
                'id' => $this->customer->id,
                'name' => $this->customer->name,
                'phone' => $this->customer->phone,
            ]),
            'title' => $this->title,
            'start_date' => $this->start_date?->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'visits_allowed' => (int) $this->visits_allowed,
            'visits_used' => (int) $this->visits_used,
            // null means the contract sells unlimited visits for the term.
            'visits_remaining' => $this->resource->visitsRemaining(),
            'amount' => (float) $this->amount,
            'status' => $this->status,
            // Stored status alone does not answer "can I book against it today?"
            // — a live contract can still be out of term or out of visits.
            'covers_today' => $this->resource->coversToday(),
            'days_to_expiry' => $this->end_date
                ? (int) now()->startOfDay()->diffInDays($this->end_date, false)
                : null,
            'jobs_count' => $this->jobs_count ?? null,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
