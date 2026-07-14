<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmbroideryWorkResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'work_number' => $this->work_number,
            'embroidery_vendor_id' => $this->embroidery_vendor_id,
            'vendor_name' => $this->whenLoaded('vendor', fn () => $this->vendor?->name),
            'product_id' => $this->product_id,
            'product_name' => $this->whenLoaded('product', fn () => $this->product?->name),
            'product_variant_id' => $this->product_variant_id,
            'design_number' => $this->design_number,
            'stitch_count' => $this->stitch_count,
            'dispatched_qty' => $this->dispatched_qty,
            'received_qty' => $this->received_qty,
            'pending_qty' => $this->pendingQty(),
            'cost' => $this->cost,
            'amount' => round((float) $this->cost * (float) $this->dispatched_qty, 2),
            'earned' => round((float) $this->cost * (float) $this->received_qty, 2),
            'status' => $this->status,
            'dispatch_date' => $this->dispatch_date?->toDateString(),
            'due_date' => $this->due_date?->toDateString(),
            'notes' => $this->notes,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
