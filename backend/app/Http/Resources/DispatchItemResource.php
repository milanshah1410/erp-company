<?php

namespace App\Http\Resources;

use App\Support\TenantStorage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DispatchItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'dispatch_plan_id' => $this->dispatch_plan_id,
            'dispatch_number' => $this->whenLoaded('dispatchPlan', fn () => $this->dispatchPlan?->dispatch_number),
            'dispatch_date' => $this->whenLoaded('dispatchPlan', fn () => $this->dispatchPlan?->dispatch_date?->toDateString()),
            'customer_id' => $this->customer_id,
            'customer' => $this->whenLoaded('customer', fn () => $this->customer?->name),
            'sales_order_id' => $this->sales_order_id,
            'sales_order' => $this->whenLoaded('salesOrder', fn () => $this->salesOrder?->so_number),
            'delivery_address' => $this->delivery_address,
            'packages' => $this->packages,
            'weight_kg' => $this->weight_kg,
            'cod_amount' => $this->cod_amount,
            'sequence' => $this->sequence,
            'delivery_status' => $this->delivery_status,
            'delivered_at' => $this->delivered_at,
            'received_by' => $this->received_by,
            'receiver_phone' => $this->receiver_phone,
            'pod_photo_path' => $this->pod_photo_path,
            'pod_photo_url' => TenantStorage::temporaryUrl($this->pod_photo_path),
            'pod_remarks' => $this->pod_remarks,
        ];
    }
}
