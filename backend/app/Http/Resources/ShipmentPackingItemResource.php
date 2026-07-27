<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One packing-list line. Alongside the stored columns it names the box the line
 * is stuffed into and labels the package type, so the packing list prints and
 * exports without the client re-resolving either.
 */
class ShipmentPackingItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'shipment_id' => $this->shipment_id,
            'shipment_container_id' => $this->shipment_container_id,
            'container_number' => $this->whenLoaded('container', fn () => $this->container?->container_number),
            'sales_item_id' => $this->sales_item_id,
            'purchase_item_id' => $this->purchase_item_id,
            'product_id' => $this->product_id,
            'product' => $this->whenLoaded('product', fn () => $this->product?->name),
            'sku' => $this->whenLoaded('product', fn () => $this->product?->sku),

            'description' => $this->description,
            'marks_numbers' => $this->marks_numbers,
            'package_type' => $this->package_type,
            'package_label' => $this->packageLabel(),
            'package_count' => $this->package_count,
            'quantity' => $this->quantity,
            'uom' => $this->uom,
            'net_weight_kg' => $this->net_weight_kg,
            'gross_weight_kg' => $this->gross_weight_kg,
            'length_cm' => $this->length_cm,
            'width_cm' => $this->width_cm,
            'height_cm' => $this->height_cm,
            // True when the volume came off the measured box rather than being
            // typed in — the UI greys the field out in that case.
            'cbm' => $this->cbm,
            'cbm_is_measured' => $this->measuredCbm() !== null,
            'hs_code' => $this->hs_code,
            'remarks' => $this->remarks,
            'sort_order' => $this->sort_order,
        ];
    }
}
