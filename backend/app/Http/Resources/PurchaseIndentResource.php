<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseIndentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'indent_number' => $this->indent_number,
            'department_id' => $this->department_id,
            'department' => $this->whenLoaded('department', fn () => [
                'id' => $this->department->id,
                'name' => $this->department->name,
            ]),
            'warehouse_id' => $this->warehouse_id,
            'warehouse' => $this->whenLoaded('warehouse', fn () => [
                'id' => $this->warehouse->id,
                'name' => $this->warehouse->name,
            ]),
            'status' => $this->status,
            'priority' => $this->priority,
            'indent_date' => $this->indent_date?->toDateString(),
            'required_date' => $this->required_date?->toDateString(),
            'notes' => $this->notes,
            'rejection_reason' => $this->rejection_reason,
            'created_by' => $this->created_by,
            'approved_by' => $this->approved_by,
            'approved_at' => $this->approved_at,
            'items' => PurchaseIndentItemResource::collection($this->whenLoaded('items')),
            'items_count' => $this->whenCounted('items'),
            'rfqs_count' => $this->whenCounted('rfqs'),
            'created_at' => $this->created_at,
        ];
    }
}
