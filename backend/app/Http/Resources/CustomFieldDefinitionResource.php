<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Support\CustomFieldEntities;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomFieldDefinitionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'entity' => $this->entity,
            'entity_label' => CustomFieldEntities::CATALOGUE[$this->entity]['label'] ?? $this->entity,
            'key' => $this->key,
            'label' => $this->label,
            'help_text' => $this->help_text,
            'type' => $this->type,
            'options' => $this->options ?? [],
            'default_value' => $this->default_value,
            'is_required' => (bool) $this->is_required,
            'min_value' => $this->min_value === null ? null : (float) $this->min_value,
            'max_value' => $this->max_value === null ? null : (float) $this->max_value,
            'pattern' => $this->pattern,
            'show_in_list' => (bool) $this->show_in_list,
            'is_searchable' => (bool) $this->is_searchable,
            'module' => $this->module,
            'sort_order' => (int) $this->sort_order,
            'is_active' => (bool) $this->is_active,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
