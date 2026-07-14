<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RfqResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'rfq_number' => $this->rfq_number,
            'purchase_indent_id' => $this->purchase_indent_id,
            'indent' => $this->whenLoaded('purchaseIndent', fn () => [
                'id' => $this->purchaseIndent->id,
                'indent_number' => $this->purchaseIndent->indent_number,
            ]),
            'warehouse_id' => $this->warehouse_id,
            'warehouse' => $this->whenLoaded('warehouse', fn () => [
                'id' => $this->warehouse->id,
                'name' => $this->warehouse->name,
            ]),
            'status' => $this->status,
            'rfq_date' => $this->rfq_date?->toDateString(),
            'response_deadline' => $this->response_deadline?->toDateString(),
            'awarded_supplier_id' => $this->awarded_supplier_id,
            'awarded_supplier' => $this->whenLoaded('awardedSupplier', fn () => $this->awardedSupplier ? [
                'id' => $this->awardedSupplier->id,
                'name' => $this->awardedSupplier->name,
            ] : null),
            'purchase_order_id' => $this->purchase_order_id,
            'purchase_order' => $this->whenLoaded('purchaseOrder', fn () => $this->purchaseOrder ? [
                'id' => $this->purchaseOrder->id,
                'po_number' => $this->purchaseOrder->po_number,
            ] : null),
            'notes' => $this->notes,
            'items' => RfqItemResource::collection($this->whenLoaded('items')),
            'suppliers' => RfqSupplierResource::collection($this->whenLoaded('suppliers')),
            'items_count' => $this->whenCounted('items'),
            'suppliers_count' => $this->whenCounted('suppliers'),
            'created_at' => $this->created_at,
        ];
    }
}
