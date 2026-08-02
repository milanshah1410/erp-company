<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductStoneResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'stone_type' => $this->stone_type,
            'shape' => $this->shape,
            'pieces' => (int) $this->pieces,
            'carat_weight' => (float) $this->carat_weight,
            'cut_grade' => $this->cut_grade,
            'colour_grade' => $this->colour_grade,
            'clarity_grade' => $this->clarity_grade,
            'rate_type' => $this->rate_type,
            'rate' => (float) $this->rate,
            'value' => (float) $this->value,
            // What the arithmetic would give, so the editor can show the shop
            // that it has overruled it rather than leaving the two silently apart.
            'computed_value' => $this->resource->computedValue(),
            'certificate_number' => $this->certificate_number,
            'certifying_lab' => $this->certifying_lab,
            'certificate_date' => $this->certificate_date?->toDateString(),
            'setting_type' => $this->setting_type,
            'notes' => $this->notes,
            // Derived here so a bill, a tag and the register all read alike.
            'label' => $this->resource->label(),
            'gram_weight' => $this->resource->gramWeight(),
            'is_graded' => $this->resource->isGraded(),
        ];
    }
}
