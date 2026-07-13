<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'parent_id' => $this->parent_id,
            'parent' => $this->whenLoaded('parent', fn () => [
                'id' => $this->parent->id,
                'name' => $this->parent->name,
            ]),
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'attributes' => $this->getAttribute('attributes'),
            'is_active' => $this->is_active,
            'children_count' => $this->when(
                $this->relationLoaded('children'),
                fn () => $this->children->count()
            ),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
