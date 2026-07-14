<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ColourPrintingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'printer_vendor_id' => $this->printer_vendor_id,
            'printer_vendor_name' => $this->whenLoaded('printerVendor', fn () => $this->printerVendor?->name),
            'print_type' => $this->print_type,
            'screen_count' => $this->screen_count,
            'colour_count' => $this->colour_count,
            'cost' => $this->cost,
            'status' => $this->status,
            'notes' => $this->notes,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
