<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FabricInspectionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'inspection_number' => $this->inspection_number,
            'fabric_roll_id' => $this->fabric_roll_id,
            'fabric_roll' => $this->whenLoaded('fabricRoll', fn () => $this->fabricRoll ? [
                'id' => $this->fabricRoll->id,
                'roll_number' => $this->fabricRoll->roll_number,
                'shade_no' => $this->fabricRoll->shade_no,
                'dye_lot' => $this->fabricRoll->dye_lot,
                'product' => $this->fabricRoll->relationLoaded('product') && $this->fabricRoll->product
                    ? ['id' => $this->fabricRoll->product->id, 'name' => $this->fabricRoll->product->name]
                    : null,
            ] : null),
            'inspected_length' => $this->inspected_length,
            'inspected_width' => $this->inspected_width,
            'total_points' => $this->total_points,
            'points_per_100sqm' => $this->points_per_100sqm,
            'grade' => $this->grade,
            'result' => $this->result,
            'inspector' => $this->inspector,
            'inspection_date' => $this->inspection_date?->toDateString(),
            'notes' => $this->notes,
            'defects' => $this->whenLoaded('defects', fn () => $this->defects->map(fn ($d) => [
                'id' => $d->id,
                'defect_type' => $d->defect_type,
                'severity' => $d->severity,
                'size_range' => $d->size_range,
                'points' => $d->points,
                'count' => $d->count,
                'line_points' => $d->line_points,
            ])),
            'created_at' => $this->created_at,
        ];
    }
}
