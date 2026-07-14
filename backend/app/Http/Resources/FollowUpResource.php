<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FollowUpResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'related_type' => class_basename($this->followupable_type),
            'related_id' => $this->followupable_id,
            'related' => $this->whenLoaded('followupable', fn () => [
                'id' => $this->followupable?->id,
                'name' => $this->followupable?->name,
            ]),
            'type' => $this->type,
            'due_at' => $this->due_at,
            'is_overdue' => $this->status === 'pending' && $this->due_at?->isPast(),
            'title' => $this->title,
            'notes' => $this->notes,
            'status' => $this->status,
            'completed_at' => $this->completed_at,
            'outcome' => $this->outcome,
            'assigned_to' => $this->assigned_to,
            'assignee' => $this->whenLoaded('assignee', fn () => [
                'id' => $this->assignee?->id,
                'name' => $this->assignee?->name,
            ]),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
