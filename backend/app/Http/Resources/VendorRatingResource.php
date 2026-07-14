<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VendorRatingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'supplier_id' => $this->supplier_id,
            'supplier' => $this->whenLoaded('supplier', fn () => [
                'id' => $this->supplier->id,
                'name' => $this->supplier->name,
            ]),
            'goods_receipt_id' => $this->goods_receipt_id,
            'goods_receipt' => $this->whenLoaded('goodsReceipt', fn () => $this->goodsReceipt ? [
                'id' => $this->goodsReceipt->id,
                'grn_number' => $this->goodsReceipt->grn_number,
            ] : null),
            'purchase_order_id' => $this->purchase_order_id,
            'quality_score' => $this->quality_score,
            'delivery_score' => $this->delivery_score,
            'pricing_score' => $this->pricing_score,
            'service_score' => $this->service_score,
            'overall_score' => $this->overall_score,
            'rated_on' => $this->rated_on?->toDateString(),
            'remarks' => $this->remarks,
            'rated_by' => $this->rated_by,
            'created_at' => $this->created_at,
        ];
    }
}
